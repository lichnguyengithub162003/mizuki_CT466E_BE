<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\PosSession;
use App\Models\PosSessionItem;
use Closure;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<PosSession> */
class PosSessionRepository extends BaseRepository
{
    public function __construct(PosSession $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createSession(array $attributes): PosSession
    {
        /** @var PosSession $session */
        $session = $this->query()->create($attributes);

        return $this->loadDetails($session);
    }

    public function findOwned(string $code, int $cashierId, int $branchId): ?PosSession
    {
        $session = $this->query()
            ->where('code', $code)
            ->where('cashier_id', $cashierId)
            ->where('branch_id', $branchId)
            ->first();

        return $session === null ? null : $this->loadDetails($session);
    }

    public function findForDisplay(string $code): ?PosSession
    {
        $session = $this->query()
            ->where('code', $code)
            ->where('expires_at', '>', now())
            ->first();

        return $session === null ? null : $this->loadDetails($session);
    }

    public function lockOwned(string $code, int $cashierId, int $branchId): ?PosSession
    {
        $session = $this->query()
            ->where('code', $code)
            ->where('cashier_id', $cashierId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($session !== null) {
            $session->load(['items' => fn ($query) => $query->orderBy('id')]);
        }

        return $session;
    }

    /** @param array<string, mixed> $attributes */
    public function addItem(PosSession $session, array $attributes): void
    {
        $session->items()->create($attributes);
    }

    public function updateItem(PosSessionItem $item, int $quantity, int $unitPrice): void
    {
        $item->fill([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ])->save();
    }

    public function deleteItem(PosSessionItem $item): void
    {
        $item->delete();
    }

    /** @param array<string, mixed> $attributes */
    public function updateSession(PosSession $session, array $attributes): PosSession
    {
        $session->fill($attributes)->save();

        return $this->loadDetails($session->refresh());
    }

    public function complete(PosSession $session, Order $order): PosSession
    {
        $session->fill([
            'status' => 'completed',
            'order_id' => $order->id,
            'completed_at' => now(),
        ])->save();

        return $this->loadDetails($session->refresh());
    }

    public function loadDetails(PosSession $session): PosSession
    {
        return $session->load([
            'branch:id,name,address',
            'customer:id,name,phone',
            'items' => fn ($query) => $query->orderBy('id'),
            'order:id,order_number,status,total_amount',
        ]);
    }
}
