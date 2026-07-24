<?php

namespace App\Services\Cashier;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\PosSessionRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Services\BaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosService extends BaseService
{
    public function __construct(
        private readonly PosSessionRepository $sessions,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * @param array{keyword: string, limit?: int} $data
     * @return Collection<int, ProductVariant>
     */
    public function searchProducts(User $cashier, array $data): Collection
    {
        $branchId = $this->cashierBranchId($cashier);
        $variants = $this->products->searchActivePosVariants(
            trim($data['keyword']),
            $branchId,
            (int) ($data['limit'] ?? 10),
        );

        return $variants->map(fn (ProductVariant $variant): ProductVariant => $this->decorateVariant($variant));
    }

    public function findByBarcode(User $cashier, string $barcode): ?ProductVariant
    {
        $variant = $this->products->findActivePosVariantByBarcode(
            trim($barcode),
            $this->cashierBranchId($cashier),
        );

        return $variant === null ? null : $this->decorateVariant($variant);
    }

    public function createSession(User $cashier): PosSession
    {
        $branchId = $this->cashierBranchId($cashier);
        $ttl = max(5, (int) config('pos.session_ttl_minutes', 30));

        return $this->sessions->createSession([
            'code' => Str::random(48),
            'cashier_id' => $cashier->id,
            'branch_id' => $branchId,
            'payment_method' => PaymentMethod::Cash,
            'status' => 'open',
            'expires_at' => now()->addMinutes($ttl),
        ]);
    }

    public function getSession(User $cashier, string $code): ?PosSession
    {
        $session = $this->sessions->findOwned(
            $code,
            $cashier->id,
            $this->cashierBranchId($cashier),
        );

        if ($session !== null) {
            $this->ensureNotExpired($session);
        }

        return $session;
    }

    public function display(string $code): ?PosSession
    {
        $session = $this->sessions->findForDisplay($code);

        if ($session !== null) {
            $this->attachBankTransferDetails($session);
        }

        return $session;
    }

    /** @param array{variant_id: int, quantity: int} $data */
    public function addItem(User $cashier, string $code, array $data): ?PosSession
    {
        $branchId = $this->cashierBranchId($cashier);

        return $this->sessions->transaction(function () use ($cashier, $branchId, $code, $data): ?PosSession {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);
            $variant = $this->products->findActivePosVariant((int) $data['variant_id'], $branchId);

            if ($variant === null) {
                $this->validationError('variant_id', 'Sản phẩm không tồn tại hoặc đã ngừng bán');
            }

            $existing = $session->items->firstWhere('product_variant_id', $variant->id);
            $quantity = (int) $data['quantity'] + ($existing?->quantity ?? 0);
            $this->ensureAvailable($variant, $quantity);
            $effectivePrice = $this->effectivePrice($variant);

            if ($existing === null) {
                $this->sessions->addItem($session, [
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'sku' => $variant->sku,
                    'variant_attributes' => $variant->attributes,
                    'unit_price' => $effectivePrice,
                    'quantity' => $quantity,
                ]);
            } else {
                $this->sessions->updateItem($existing, $quantity, $effectivePrice);
            }

            return $this->sessions->loadDetails($session->refresh());
        });
    }

    public function updateItem(
        User $cashier,
        string $code,
        int $variantId,
        int $quantity,
    ): ?PosSession {
        $branchId = $this->cashierBranchId($cashier);

        return $this->sessions->transaction(function () use (
            $cashier,
            $branchId,
            $code,
            $variantId,
            $quantity,
        ): ?PosSession {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);
            $item = $session->items->firstWhere('product_variant_id', $variantId);

            if ($item === null) {
                return null;
            }

            $variant = $this->products->findActivePosVariant($variantId, $branchId);

            if ($variant === null) {
                $this->validationError('variant_id', 'Sản phẩm không tồn tại hoặc đã ngừng bán');
            }

            $this->ensureAvailable($variant, $quantity);
            $this->sessions->updateItem($item, $quantity, $this->effectivePrice($variant));

            return $this->sessions->loadDetails($session->refresh());
        });
    }

    public function deleteItem(User $cashier, string $code, int $variantId): ?PosSession
    {
        $branchId = $this->cashierBranchId($cashier);

        return $this->sessions->transaction(function () use (
            $cashier,
            $branchId,
            $code,
            $variantId,
        ): ?PosSession {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);
            $item = $session->items->firstWhere('product_variant_id', $variantId);

            if ($item === null) {
                return null;
            }

            $this->sessions->deleteItem($item);

            return $this->sessions->loadDetails($session->refresh());
        });
    }

    /**
     * @param array{customer_phone?: string|null, customer_name?: string|null} $data
     */
    public function updateCustomer(User $cashier, string $code, array $data): ?PosSession
    {
        $branchId = $this->cashierBranchId($cashier);

        return $this->sessions->transaction(function () use ($cashier, $branchId, $code, $data): ?PosSession {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);
            $phone = trim((string) ($data['customer_phone'] ?? ''));

            if ($phone === '') {
                return $this->sessions->updateSession($session, [
                    'customer_user_id' => null,
                    'customer_name' => null,
                    'customer_phone' => null,
                ]);
            }

            $customer = $this->users->findCustomerByPhone($phone);
            $name = $customer?->name ?? trim((string) ($data['customer_name'] ?? ''));

            if ($customer === null && $name === '') {
                $this->validationError(
                    'customer_name',
                    'Vui lòng nhập tên cho khách hàng chưa có tài khoản',
                );
            }

            return $this->sessions->updateSession($session, [
                'customer_user_id' => $customer?->id,
                'customer_name' => $name,
                'customer_phone' => $phone,
            ]);
        });
    }

    public function updatePaymentMethod(
        User $cashier,
        string $code,
        string $paymentMethod,
    ): ?PosSession {
        $branchId = $this->cashierBranchId($cashier);
        $method = PaymentMethod::from($paymentMethod);

        return $this->sessions->transaction(function () use (
            $cashier,
            $branchId,
            $code,
            $method,
        ): ?PosSession {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);

            return $this->sessions->updateSession($session, [
                'payment_method' => $method,
            ]);
        });
    }

    public function confirm(User $cashier, string $code): ?PosSession
    {
        $branchId = $this->cashierBranchId($cashier);
        $result = $this->sessions->transaction(function () use ($cashier, $branchId, $code): ?array {
            $session = $this->sessions->lockOwned($code, $cashier->id, $branchId);

            if ($session === null) {
                return null;
            }

            $this->ensureOpen($session);

            if ($session->items->isEmpty()) {
                $this->validationError('items', 'Phiên POS phải có ít nhất một sản phẩm');
            }

            if (! in_array(
                $session->payment_method,
                [PaymentMethod::Cash, PaymentMethod::BankTransfer],
                true,
            )) {
                $this->validationError('payment_method', 'Phương thức thanh toán POS không hợp lệ');
            }

            $snapshots = [];
            $subtotal = 0;

            foreach ($session->items as $item) {
                $variant = $this->products->lockActivePosVariant($item->product_variant_id);

                if ($variant === null) {
                    $this->validationError('items', "Sản phẩm {$item->sku} đã ngừng bán");
                }

                $inventory = $this->orders->lockInventory($branchId, $variant->id);
                $available = $inventory === null
                    ? 0
                    : max(0, $inventory->quantity - $inventory->reserved_quantity);

                if ($inventory === null || $item->quantity > $available) {
                    $this->validationError(
                        'stock',
                        "Sản phẩm {$variant->product->name} chỉ còn {$available} sản phẩm tại chi nhánh",
                    );
                }

                $unitPrice = $this->effectivePrice($variant);
                $lineTotal = $unitPrice * $item->quantity;
                $subtotal += $lineTotal;
                $snapshots[] = [
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'sku' => $variant->sku,
                    'variant_attributes' => $variant->attributes,
                    'unit_price' => $unitPrice,
                    'quantity' => $item->quantity,
                    'line_total' => $lineTotal,
                ];
                $this->orders->reserveInventory($inventory, $item->quantity);
            }

            $order = $this->orders->createOrder([
                'order_number' => 'MZ-POS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                'user_id' => $session->customer_user_id,
                'customer_name' => $session->customer_name,
                'customer_phone' => $session->customer_phone,
                'branch_id' => $branchId,
                'created_by_user_id' => $cashier->id,
                'channel' => 'counter',
                'fulfillment_method' => 'pickup',
                'payment_method' => $session->payment_method,
                'status' => OrderStatus::Confirmed,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'shipping_fee' => 0,
                'total_amount' => $subtotal,
                'placed_at' => now(),
            ]);
            $this->orders->createItems($order, $snapshots);

            return [
                'session' => $this->sessions->complete($session, $order),
                'order' => $order,
            ];
        });

        if ($result === null) {
            return null;
        }

        OrderPlaced::dispatch($result['order']);

        return $result['session'];
    }

    private function cashierBranchId(User $cashier): int
    {
        if ($cashier->role !== UserRole::Cashier || $cashier->branch_id === null) {
            throw new AuthorizationException('Thu ngân chưa được gán chi nhánh');
        }

        return $cashier->branch_id;
    }

    private function ensureOpen(PosSession $session): void
    {
        if ($session->status !== 'open') {
            $this->validationError('status', 'Phiên POS đã được xử lý');
        }

        $this->ensureNotExpired($session);
    }

    private function ensureNotExpired(PosSession $session): void
    {
        if ($session->expires_at->isPast()) {
            $this->validationError('status', 'Phiên POS đã hết hạn');
        }
    }

    private function decorateVariant(ProductVariant $variant): ProductVariant
    {
        $inventory = $variant->inventories->first();
        $available = $inventory === null
            ? 0
            : max(0, $inventory->quantity - $inventory->reserved_quantity);

        $variant->setAttribute('effective_price', $this->effectivePrice($variant));
        $variant->setAttribute('available_quantity', $available);
        $variant->setAttribute('available', $available > 0);

        return $variant;
    }

    private function ensureAvailable(ProductVariant $variant, int $quantity): void
    {
        $available = (int) $this->decorateVariant($variant)->available_quantity;

        if ($quantity > $available) {
            $this->validationError(
                'quantity',
                "Sản phẩm chỉ còn {$available} sản phẩm tại chi nhánh",
            );
        }
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        return $variant->sale_price !== null && $variant->sale_price < $variant->price
            ? $variant->sale_price
            : $variant->price;
    }

    private function attachBankTransferDetails(PosSession $session): void
    {
        if ($session->payment_method !== PaymentMethod::BankTransfer) {
            return;
        }

        $amount = (int) $session->items->sum(
            fn ($item): int => $item->unit_price * $item->quantity,
        );
        $bin = trim((string) config('pos.bank.bin'));
        $accountNumber = trim((string) config('pos.bank.account_number'));
        $accountName = trim((string) config('pos.bank.account_name'));
        $transferContent = $this->buildTransferContent($session);
        $qrUrl = null;

        if ($bin !== '' && $accountNumber !== '') {
            $qrUrl = "https://img.vietqr.io/image/{$bin}-{$accountNumber}-compact2.png"
                .'?amount='.$amount
                .'&addInfo='.rawurlencode($transferContent)
                .'&accountName='.rawurlencode($accountName);
        }

        $session->setAttribute('bank_transfer', [
            'qr_url' => $qrUrl,
            'bank_name' => config('pos.bank.name'),
            'account_number' => $accountNumber,
            'account_holder' => $accountName,
            'amount' => $amount,
            'transfer_content' => $transferContent,
        ]);
    }

    private function buildTransferContent(PosSession $session): string
    {
        $code = preg_replace('/[^A-Za-z0-9]/', '', $session->code) ?? '';
        $identifier = Str::upper(substr($code, 0, 10));

        if ($identifier === '') {
            $identifier = Str::upper(base_convert((string) $session->id, 10, 36));
        }

        $prefix = $this->normalizeTransferText(
            (string) config('pos.bank.transfer_prefix', 'MIZUKI'),
        );
        $maxPrefixLength = max(0, 25 - strlen($identifier) - 1);
        $prefix = $this->shortenTransferPrefix($prefix, $maxPrefixLength);

        return trim($prefix === '' ? $identifier : "{$prefix} {$identifier}");
    }

    private function normalizeTransferText(string $value): string
    {
        $ascii = Str::upper(Str::ascii($value));
        $allowed = preg_replace('/[^A-Z0-9 \-]/', '', $ascii) ?? '';
        $singleSpaced = preg_replace('/\s+/', ' ', $allowed) ?? '';

        return trim($singleSpaced);
    }

    private function shortenTransferPrefix(string $prefix, int $maxLength): string
    {
        if ($maxLength === 0 || $prefix === '') {
            return '';
        }

        if (strlen($prefix) <= $maxLength) {
            return $prefix;
        }

        $result = '';

        foreach (explode(' ', $prefix) as $word) {
            $candidate = $result === '' ? $word : "{$result} {$word}";

            if (strlen($candidate) > $maxLength) {
                break;
            }

            $result = $candidate;
        }

        return $result !== '' ? $result : rtrim(substr($prefix, 0, $maxLength));
    }

    private function validationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
