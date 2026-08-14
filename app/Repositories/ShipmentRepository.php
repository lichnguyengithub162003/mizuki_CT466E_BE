<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\Shipment;
use Closure;
use Illuminate\Support\Facades\DB;

class ShipmentRepository extends BaseRepository
{
    public function __construct(Shipment $model)
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

            return ['shipment' => $shipment->refresh(), 'changed' => $changed];
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
            $query = $this->query()
                ->where('provider', 'ghn')
                ->where('order_id', $orderId);

            if ($role !== UserRole::SuperAdmin) {
                $query->whereHas('order', static function ($orderQuery) use ($branchId): void {
                    $orderQuery->where('branch_id', $branchId ?? 0);
                });
            }

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
        $query = $this->query()
            ->where('provider', 'ghn')
            ->where('order_id', $orderId);

        if ($role !== UserRole::SuperAdmin) {
            $query->whereHas('order', static function ($orderQuery) use ($branchId): void {
                $orderQuery->where('branch_id', $branchId ?? 0);
            });
        }

        /** @var Shipment|null $shipment */
        $shipment = $query->with('order')->first();

        return $shipment;
    }
}
