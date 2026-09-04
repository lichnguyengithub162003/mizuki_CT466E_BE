<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\Shipping\GhnApiException;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use App\Services\BaseService;
use App\Services\Shipping\GhnClient;
use App\Services\Shipping\GhnServiceSelector;
use App\Services\Shipping\PackageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderService extends BaseService
{
    /** @var list<string> */
    private const CANCELLABLE_SHIPMENT_STATUSES = [
        'pending',
        'ready_to_pick',
        'picking',
        'in_transit',
        'out_for_delivery',
        'delivery_failed',
        'returning',
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ShipmentRepository $shipments,
        private readonly GhnClient $ghn,
        private readonly GhnServiceSelector $services,
        private readonly PackageCalculator $packages,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);

        return $this->orders->paginateForAdmin(
            role: $user->role,
            branchId: $user->branch_id,
            filters: $filters,
            perPage: (int) ($filters['per_page'] ?? 20),
        );
    }

    /** @return array{pending: int, processing: int, shipping: int, refund: int} */
    public function counts(User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);

        return $this->orders->countsForAdmin($user->role, $user->branch_id);
    }

    public function detail(User $user, int $orderId): ?Order
    {
        $order = $this->orders->findForAdmin($orderId, $user->role, $user->branch_id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        return $order;
    }

    public function confirm(User $user, int $orderId): ?Order
    {
        $result = $this->orders->transaction(function () use ($user, $orderId): ?array {
            $order = $this->orders->lockForAdmin($orderId, $user->role, $user->branch_id);

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('confirm', $order);

            if ($order->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể xác nhận đơn hàng đang chờ xác nhận'],
                ]);
            }

            $this->assertPaymentReady($order);
            $previousStatus = $order->status;

            return [
                'order' => $this->orders->markConfirmed($order),
                'previous_status' => $previousStatus,
            ];
        });

        if ($result === null) {
            return null;
        }

        // Dispatch only after the transaction has committed successfully.
        OrderStatusUpdated::dispatch($result['order'], $result['previous_status']);

        return $result['order'];
    }

    public function process(User $user, int $orderId): ?Order
    {
        $result = $this->orders->transaction(function () use ($user, $orderId): ?array {
            $order = $this->orders->lockForAdmin($orderId, $user->role, $user->branch_id);

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('process', $order);

            if ($order->status !== OrderStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể xử lý đơn hàng đã xác nhận'],
                ]);
            }

            $this->assertPaymentReady($order);
            $previousStatus = $order->status;

            return [
                'order' => $this->orders->markProcessing($order),
                'previous_status' => $previousStatus,
            ];
        });

        if ($result === null) {
            return null;
        }

        OrderStatusUpdated::dispatch($result['order'], $result['previous_status']);

        return $result['order'];
    }

    public function completePickup(User $user, int $orderId): ?Order
    {
        $result = $this->orders->transaction(function () use ($user, $orderId): ?array {
            $order = $this->orders->lockForAdmin($orderId, $user->role, $user->branch_id);

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('complete', $order);

            if ($order->fulfillment_method !== 'pickup') {
                throw ValidationException::withMessages([
                    'fulfillment_method' => ['Chỉ đơn nhận tại chi nhánh mới hoàn tất thủ công'],
                ]);
            }

            if ($order->status !== OrderStatus::Processing) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể hoàn tất đơn hàng đang được xử lý'],
                ]);
            }

            $this->settlePickupPayment($order, $user);
            $this->orders->consumeReservedInventory($order);
            $previousStatus = $order->status;

            return [
                'order' => $this->orders->markDelivered($order),
                'previous_status' => $previousStatus,
            ];
        });

        if ($result === null) {
            return null;
        }

        OrderStatusUpdated::dispatch($result['order'], $result['previous_status']);

        return $result['order'];
    }

    public function createShipment(User $user, int $orderId): ?Shipment
    {
        return $this->orders->transaction(function () use ($user, $orderId): ?Shipment {
            $order = $this->orders->lockForAdminShipment(
                $orderId,
                $user->role,
                $user->branch_id,
            );

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('view', $order);

            if ($order->status !== OrderStatus::Processing) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ đơn hàng đang được xử lý mới có thể tạo vận đơn'],
                ]);
            }

            if ($order->shipment !== null) {
                return $order->shipment;
            }

            $this->validateShipmentOrder($order);
            $package = $this->packages->calculateForOrder($order);

            try {
                $service = $this->services->select($this->ghn->availableServices(
                    shopId: $this->configuredShopId(),
                    fromDistrictId: (int) $order->branch->ghn_district_id,
                    toDistrictId: (int) $order->ghn_district_id,
                ));
                $provider = $this->ghn->createShipment($this->shipmentPayload(
                    $order,
                    $package,
                    $service,
                ));
            } catch (GhnApiException) {
                throw ValidationException::withMessages([
                    'shipping' => ['Không thể tạo vận đơn GHN lúc này'],
                ]);
            }

            return $this->orders->createShipment($order, [
                'provider' => 'ghn',
                'ghn_order_code' => $provider['order_code'],
                'status' => 'pending',
                'shipping_fee' => $provider['total_fee'] ?? $order->shipping_fee,
                'provider_response' => $provider,
                'expected_delivery_at' => $this->expectedDeliveryAt(
                    $provider['expected_delivery_time'],
                ),
            ]);
        });
    }

    public function cancelShipment(User $user, int $orderId): ?Shipment
    {
        return $this->shipments->cancelGhnForAdmin(
            orderId: $orderId,
            role: $user->role,
            branchId: $user->branch_id,
            cancelProvider: function (Shipment $shipment) use ($user): bool {
                Gate::forUser($user)->authorize('view', $shipment->order);

                if ($shipment->status === 'cancelled') {
                    return false;
                }

                if (! in_array($shipment->status, self::CANCELLABLE_SHIPMENT_STATUSES, true)) {
                    throw ValidationException::withMessages([
                        'status' => ['Không thể hủy vận đơn ở trạng thái hiện tại'],
                    ]);
                }

                try {
                    $this->ghn->cancelOrders([$shipment->ghn_order_code]);
                } catch (GhnApiException) {
                    throw ValidationException::withMessages([
                        'shipping' => ['Không thể hủy vận đơn GHN lúc này'],
                    ]);
                }

                return true;
            },
        );
    }

    /** @return array{shipment: Shipment, print_token: string, print_url: string}|null */
    public function shipmentLabel(User $user, int $orderId): ?array
    {
        $shipment = $this->shipments->findGhnForAdmin(
            orderId: $orderId,
            role: $user->role,
            branchId: $user->branch_id,
        );

        if ($shipment === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $shipment->order);

        try {
            $label = $this->ghn->generatePrintToken([$shipment->ghn_order_code]);
        } catch (GhnApiException) {
            throw ValidationException::withMessages([
                'shipping' => ['Không thể tạo phiếu giao hàng GHN lúc này'],
            ]);
        }

        return ['shipment' => $shipment, ...$label];
    }

    private function validateShipmentOrder(Order $order): void
    {
        if ($order->fulfillment_method !== 'shipping') {
            throw ValidationException::withMessages([
                'fulfillment_method' => ['Chỉ đơn hàng giao tận nơi mới có thể tạo vận đơn'],
            ]);
        }

        $branch = $order->branch;

        if ($branch === null
            || ! $branch->is_active
            || trim((string) $branch->name) === ''
            || trim((string) $branch->phone) === ''
            || trim((string) $branch->address) === ''
            || (int) $branch->ghn_district_id <= 0
            || trim((string) $branch->ghn_ward_code) === '') {
            throw ValidationException::withMessages([
                'branch' => ['Chi nhánh chưa có thông tin giao hàng GHN hợp lệ'],
            ]);
        }

        if (trim((string) $order->recipient_name) === ''
            || trim((string) $order->recipient_phone) === ''
            || trim((string) $order->shipping_address) === ''
            || (int) $order->ghn_district_id <= 0
            || trim((string) $order->ghn_ward_code) === '') {
            throw ValidationException::withMessages([
                'shipping' => ['Đơn hàng chưa có đầy đủ thông tin giao hàng'],
            ]);
        }
    }

    private function assertPaymentReady(Order $order): void
    {
        if ($order->payment === null) {
            throw ValidationException::withMessages([
                'payment' => ['Đơn hàng chưa có giao dịch thanh toán'],
            ]);
        }

        if ($order->payment_method === PaymentMethod::Cash
            && ! in_array($order->payment->status, [
                PaymentStatus::Pending,
                PaymentStatus::Paid,
            ], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Giao dịch COD không còn hợp lệ để xử lý'],
            ]);
        }

        if ($order->payment_method !== PaymentMethod::Cash
            && $order->payment->status !== PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'payment' => ['Đơn hàng chưa được thanh toán'],
            ]);
        }
    }

    private function settlePickupPayment(Order $order, User $operator): void
    {
        $this->assertPaymentReady($order);

        if ($order->payment_method !== PaymentMethod::Cash) {
            return;
        }

        if ($order->payment->status === PaymentStatus::Paid) {
            return;
        }

        if ($order->payment->status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'payment' => ['Giao dịch COD không còn ở trạng thái chờ thanh toán'],
            ]);
        }

        $order->payment->fill([
            'status' => PaymentStatus::Paid,
            'processed_by_user_id' => $operator->id,
            'paid_at' => now(),
        ])->save();
    }

    /**
     * @param  array{weight: int, length: int, width: int, height: int, items: list<array<string, mixed>>}  $package
     * @param  array{service_id: int, short_name: string, service_type_id: int}  $service
     * @return array<string, mixed>
     */
    private function shipmentPayload(Order $order, array $package, array $service): array
    {
        return [
            'payment_type_id' => 1,
            'required_note' => 'KHONGCHOXEMHANG',
            'client_order_code' => $order->order_number,
            'from_name' => $order->branch->name,
            'from_phone' => $order->branch->phone,
            'from_address' => $order->branch->address,
            'from_district_id' => (int) $order->branch->ghn_district_id,
            'from_ward_code' => (string) $order->branch->ghn_ward_code,
            'to_name' => $order->recipient_name,
            'to_phone' => $order->recipient_phone,
            'to_address' => $order->shipping_address,
            'to_district_id' => (int) $order->ghn_district_id,
            'to_ward_code' => (string) $order->ghn_ward_code,
            'cod_amount' => $order->payment_method === PaymentMethod::Cash
                ? (int) $order->total_amount
                : 0,
            'insurance_value' => min(
                max(0, (int) $order->total_amount),
                max(0, (int) config('shipping.package.max_insurance_value', 5_000_000)),
            ),
            'service_id' => $service['service_id'],
            'service_type_id' => $service['service_type_id'],
            'weight' => $package['weight'],
            'length' => $package['length'],
            'width' => $package['width'],
            'height' => $package['height'],
            'items' => $package['items'],
        ];
    }

    private function configuredShopId(): int
    {
        $shopId = config('services.ghn.shop_id');

        if (! (is_int($shopId) || is_string($shopId) && ctype_digit($shopId))
            || (int) $shopId <= 0) {
            throw ValidationException::withMessages([
                'shipping' => ['Cấu hình cửa hàng GHN chưa hợp lệ'],
            ]);
        }

        return (int) $shopId;
    }

    private function expectedDeliveryAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'shipping' => ['GHN trả về thời gian giao hàng không hợp lệ'],
            ]);
        }
    }
}
