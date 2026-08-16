<?php

namespace App\Services;

use App\Enums\OrderRequestReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use App\Models\UserAddress;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PromotionRepository;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService extends BaseService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CartRepository $carts,
        private readonly PromotionRepository $promotions,
        private readonly CartService $cartService,
        private readonly PromotionService $promotionService,
        private readonly PaymentService $paymentService,
        private readonly WalletService $walletService,
        private readonly ShippingQuoteService $shippingQuotes,
    ) {}

    /**
     * @param array{
     *     delivery_method: string,
     *     address_id?: int|null,
     *     shipping_quote_token?: string,
     *     payment_method: string
     * } $data
     */
    public function checkout(User $user, array $data): Order
    {
        Gate::forUser($user)->authorize('create', Order::class);
        $quoteToken = $data['delivery_method'] === 'delivery'
            ? (string) $data['shipping_quote_token']
            : null;
        $quote = $quoteToken === null
            ? null
            : $this->shippingQuotes->loadForCheckout(
                $user,
                (int) $data['address_id'],
                $quoteToken,
            );

        $order = $this->orders->transaction(function () use ($user, $data, $quote): Order {
            $this->carts->lockForCheckout($user->id);
            $cart = $this->cartService->getForUser($user);

            if ($cart->items->isEmpty()) {
                $this->checkoutError('cart', 'Giỏ hàng đang trống');
            }

            if ($cart->branch_id === null) {
                $this->checkoutError('branch_id', 'Vui lòng chọn chi nhánh trước khi đặt hàng');
            }

            if (
                $data['delivery_method'] === 'pickup'
                && ($cart->branch === null || ! $cart->branch->is_active)
            ) {
                $this->checkoutError('branch_id', 'Chi nhánh đã chọn hiện không hoạt động');
            }

            $address = $this->resolveAddress($user, $data, lock: true);
            $shippingFee = 0;

            if ($quote !== null && $address !== null) {
                $this->shippingQuotes->assertMatches($quote, $user, $cart, $address);
                $shippingFee = (int) $quote['shipping_fee'];
            }

            $promotion = $this->resolvePromotion($cart, $user);
            $discountAmount = $promotion === null
                ? 0
                : $this->cartService->calculatePromotionDiscount(
                    $promotion,
                    (int) $cart->total_before_discount,
                );
            $totalAmount = (int) $cart->total_before_discount - $discountAmount + $shippingFee;
            $itemSnapshots = [];
            $inventoryReservations = [];

            foreach ($cart->items as $item) {
                if (! $item->productVariant->is_active) {
                    $this->checkoutError(
                        'cart',
                        "Biến thể {$item->productVariant->name} của sản phẩm {$item->productVariant->product->name} đã ngừng bán",
                    );
                }

                if (! $item->productVariant->product->is_active) {
                    $this->checkoutError(
                        'cart',
                        "Sản phẩm {$item->productVariant->product->name} đã ngừng bán",
                    );
                }

                $inventory = $this->orders->lockInventory($cart->branch_id, $item->product_variant_id);
                $available = $inventory === null
                    ? 0
                    : max(0, $inventory->quantity - $inventory->reserved_quantity);

                if ($inventory === null || $item->quantity > $available) {
                    $this->checkoutError(
                        'stock',
                        "Sản phẩm {$item->productVariant->product->name} chỉ còn {$available} sản phẩm tại chi nhánh đã chọn",
                    );
                }

                $itemSnapshots[] = $this->snapshotItem($item);
                $inventoryReservations[] = [
                    'inventory' => $inventory,
                    'quantity' => $item->quantity,
                ];
            }

            $wallet = $data['payment_method'] === PaymentMethod::Wallet->value
                ? $this->walletService->lockForCheckout($user, $totalAmount)
                : null;

            foreach ($inventoryReservations as $reservation) {
                $this->orders->reserveInventory(
                    $reservation['inventory'],
                    $reservation['quantity'],
                );
            }

            $order = $this->orders->createOrder([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'branch_id' => $cart->branch_id,
                'created_by_user_id' => null,
                'user_address_id' => $address?->id,
                'promotion_id' => $promotion?->id,
                'channel' => 'online',
                'fulfillment_method' => $data['delivery_method'] === 'delivery' ? 'shipping' : 'pickup',
                'payment_method' => $data['payment_method'],
                'status' => OrderStatus::Pending,
                'recipient_name' => $address?->recipient_name,
                'recipient_phone' => $address?->recipient_phone,
                'province_code' => $address?->province_code,
                'ghn_district_id' => $address?->ghn_district_id,
                'ghn_ward_code' => $address?->ghn_ward_code,
                'shipping_address' => $address === null ? null : $this->formatAddress($address),
                'subtotal' => (int) $cart->total_before_discount,
                'discount_amount' => $discountAmount,
                'shipping_fee' => $shippingFee,
                'total_amount' => $totalAmount,
                'placed_at' => now(),
            ]);

            $this->orders->createItems($order, $itemSnapshots);
            $payment = $this->paymentService->createForOrder($order, PaymentStatus::Pending);

            if ($wallet !== null) {
                $this->walletService->completeCheckoutPayment(
                    user: $user,
                    order: $order,
                    payment: $payment,
                    wallet: $wallet,
                );
            }

            if ($promotion !== null) {
                $this->promotions->recordUsage($promotion, $user, $order, $discountAmount);
            }

            $this->carts->clearAfterCheckout($cart);

            return $this->orders->loadDetails($order);
        });

        if ($quoteToken !== null) {
            $this->shippingQuotes->consume($quoteToken);
        }

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);

        return $this->orders->paginateForUser(
            $user->id,
            $filters,
            (int) ($filters['per_page'] ?? 15),
        );
    }

    public function detail(User $user, int $orderId): ?Order
    {
        $order = $this->orders->findForUser($orderId, $user->id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        return $order;
    }

    /** @param array{reason_type: string, reason?: string|null} $data */
    public function cancel(User $user, int $orderId, array $data): ?Order
    {
        $result = $this->orders->transaction(function () use ($user, $orderId, $data): ?array {
            $order = $this->orders->lockForUser($orderId, $user->id);

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('view', $order);

            if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Đơn hàng ở trạng thái hiện tại không thể hủy'],
                ]);
            }

            $previousStatus = $order->status;
            $reasonType = OrderRequestReason::from($data['reason_type']);
            $reason = trim((string) ($data['reason'] ?? '')) ?: $reasonType->label();

            $this->orders->releaseReservedInventory($order);
            $cancelledOrder = $this->orders->markCancelled($order, $reasonType->value, $reason);

            return ['order' => $cancelledOrder, 'previous_status' => $previousStatus];
        });

        if ($result === null) {
            return null;
        }

        OrderStatusUpdated::dispatch($result['order'], $result['previous_status']);

        return $result['order'];
    }

    /** @param array<string, mixed> $data */
    private function resolveAddress(User $user, array $data, bool $lock = false): ?UserAddress
    {
        if ($data['delivery_method'] === 'pickup') {
            return null;
        }

        $address = $lock
            ? $this->orders->lockAddressForUser((int) $data['address_id'], $user->id)
            : $this->orders->findAddressForUser((int) $data['address_id'], $user->id);

        if ($address === null) {
            $this->checkoutError('address_id', 'Địa chỉ giao hàng không tồn tại hoặc không thuộc tài khoản của bạn');
        }

        return $address;
    }

    private function resolvePromotion(Cart $cart, User $user): ?Promotion
    {
        if ($cart->promotion_id === null) {
            return null;
        }

        $promotion = $this->promotions->lockForCheckout($cart->promotion_id, $user->id);

        if ($promotion === null) {
            $this->checkoutError('code', 'Voucher không còn tồn tại');
        }

        $cart->setRelation('promotion', $promotion);
        $this->promotionService->validateForCheckout($promotion, $cart, $user);

        return $promotion;
    }

    /** @return array<string, mixed> */
    private function snapshotItem(CartItem $item): array
    {
        return [
            'product_variant_id' => $item->product_variant_id,
            'product_name' => $item->productVariant->product->name,
            'variant_name' => $item->productVariant->name,
            'sku' => $item->productVariant->sku,
            'variant_attributes' => $item->productVariant->attributes,
            'unit_price' => (int) $item->effective_price,
            'quantity' => $item->quantity,
            'line_total' => (int) $item->subtotal,
        ];
    }

    private function formatAddress(UserAddress $address): string
    {
        return collect([
            $address->address_line,
            $address->hamlet,
            $address->ward,
            $address->district,
            $address->province,
        ])->filter()->implode(', ');
    }

    private function generateOrderNumber(): string
    {
        return 'MZ-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    private function checkoutError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
