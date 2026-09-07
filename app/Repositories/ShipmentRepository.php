<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Shipment;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentRepository extends BaseRepository
{
    public function __construct(
        Shipment $model,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
    )
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{shipment: Shipment, changed: bool}|null
     */
    public function applyGhnWebhook(
        string $orderCode,
        string $providerStatus,
        array $payload,
    ): ?array {
        return DB::transaction(function () use ($orderCode, $providerStatus, $payload): ?array {
            /** @var Shipment|null $shipment */
            $shipment = $this->query()
                ->where('provider', 'ghn')
                ->where('ghn_order_code', $orderCode)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                return null;
            }

            if (! $shipment->applyGhnWebhook($providerStatus, $payload)) {
                return ['shipment' => $shipment, 'changed' => false];
            }

            $changed = $shipment->isDirty();

            if ($changed) {
                $shipment->save();
            }

            $orderChanged = $this->synchronizeOrder($shipment);

            return ['shipment' => $shipment->refresh(), 'changed' => $changed || $orderChanged];
        }, 3);
    }

    public function simulateGhnEventForAdmin(
        int $orderId,
        UserRole $role,
        ?int $branchId,
        string $providerStatus,
    ): ?Shipment {
        return DB::transaction(function () use ($orderId, $role, $branchId, $providerStatus): ?Shipment {
            $query = $this->adminQuery($orderId, $role, $branchId);

            /** @var Shipment|null $shipment */
            $shipment = $query->with('order')->lockForUpdate()->first();

            if ($shipment === null) {
                return null;
            }

            $allowedEvents = match ($shipment->status) {
                'pending', 'ready_to_pick', 'picking' => ['picked'],
                'in_transit' => ['delivering'],
                'out_for_delivery' => ['delivered', 'delivery_fail'],
                'delivery_failed' => ['waiting_to_return'],
                'returning' => ['returned'],
                default => [],
            };

            if (! in_array($providerStatus, $allowedEvents, true)
                || ! $shipment->applyGhnWebhook($providerStatus, [
                    'OrderCode' => $shipment->ghn_order_code,
                    'Status' => $providerStatus,
                    'Type' => 'LocalSimulator',
                    'Time' => now()->toISOString(),
                ])) {
                throw ValidationException::withMessages([
                    'status' => ['Không thể mô phỏng sự kiện GHN ở trạng thái hiện tại'],
                ]);
            }

            if ($shipment->isDirty()) {
                $shipment->save();
            }

            $this->synchronizeOrder($shipment);

            return $shipment->refresh()->load('order');
        }, 3);
    }

    /**
     * @param  Closure(Shipment): bool  $cancelProvider
     */
    public function cancelGhnForAdmin(
        int $orderId,
        UserRole $role,
        ?int $branchId,
        Closure $cancelProvider,
    ): ?Shipment {
        return DB::transaction(function () use (
            $orderId,
            $role,
            $branchId,
            $cancelProvider,
        ): ?Shipment {
            $query = $this->adminQuery($orderId, $role, $branchId)
                ->where('provider', 'ghn');

            /** @var Shipment|null $shipment */
            $shipment = $query
                ->with('order')
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                return null;
            }

            if (! $cancelProvider($shipment)) {
                return $shipment;
            }

            $shipment->fill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            return $shipment->refresh();
        }, 3);
    }

    public function findGhnForAdmin(
        int $orderId,
        UserRole $role,
        ?int $branchId,
    ): ?Shipment {
        $query = $this->adminQuery($orderId, $role, $branchId)
            ->where('provider', 'ghn');

        /** @var Shipment|null $shipment */
        $shipment = $query->with('order')->first();

        return $shipment;
    }

    private function synchronizeOrder(Shipment $shipment): bool
    {
        $order = $this->orders->lockForShipmentSync($shipment->order_id);

        if ($order === null) {
            return false;
        }

        if (in_array($shipment->status, ['in_transit', 'out_for_delivery'], true)
            && $order->status === OrderStatus::Processing) {
            $this->orders->markShipping($order);

            return true;
        }

        if ($shipment->status === 'delivered'
            && in_array($order->status, [OrderStatus::Processing, OrderStatus::Shipping], true)) {
            $this->orders->consumeReservedInventory($order);

            if ($order->payment_method === PaymentMethod::Cash
                && $order->payment?->status === PaymentStatus::Pending) {
                $this->payments->markCodCollectedOnDelivery($order->payment);
            }

            $this->orders->markDelivered($order);

            return true;
        }

        return false;
    }

    /** @return Builder<Shipment> */
    private function adminQuery(int $orderId, UserRole $role, ?int $branchId): Builder
    {
        $query = $this->query()->where('order_id', $orderId);

        if ($role !== UserRole::SuperAdmin) {
            $query->whereHas('order', static function ($orderQuery) use ($branchId): void {
                $orderQuery->where('branch_id', $branchId ?? 0);
            });
        }

        return $query;
    }
}
