<?php

namespace App\Repositories;

use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\UserAddress;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Order> */
class OrderRepository extends BaseRepository
{
    public function __construct(
        Order $model,
        private readonly UserAddress $addresses,
        private readonly BranchInventory $inventories,
    ) {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function findAddressForUser(int $addressId, int $userId): ?UserAddress
    {
        return $this->addresses->newQuery()
            ->whereKey($addressId)
            ->where('user_id', $userId)
            ->first();
    }

    public function lockInventory(int $branchId, int $variantId): ?BranchInventory
    {
        return $this->inventories->newQuery()
            ->where('branch_id', $branchId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();
    }

    public function reserveInventory(BranchInventory $inventory, int $quantity): void
    {
        $inventory->increment('reserved_quantity', $quantity);
    }

    /** @param array<string, mixed> $attributes */
    public function createOrder(array $attributes): Order
    {
        /** @var Order $order */
        $order = $this->query()->create($attributes);

        return $order;
    }

    /** @param array<int, array<string, mixed>> $items */
    public function createItems(Order $order, array $items): void
    {
        $order->items()->createMany($items);
    }

    public function loadDetails(Order $order): Order
    {
        return $order->load([
            'branch:id,name,address',
            'promotion:id,code,name',
            'userAddress',
            'items' => fn (Builder|HasMany $query): Builder|HasMany => $query->orderBy('id'),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->where('user_id', $userId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->withCount('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForUser(int $orderId, int $userId): ?Order
    {
        $order = $this->query()
            ->where('user_id', $userId)
            ->whereKey($orderId)
            ->first();

        return $order === null ? null : $this->loadDetails($order);
    }

    public function lockForUser(int $orderId, int $userId): ?Order
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereKey($orderId)
            ->with('items')
            ->lockForUpdate()
            ->first();
    }

    public function releaseReservedInventory(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = $this->lockInventory($order->branch_id, $item->product_variant_id);

            if ($inventory === null || $inventory->reserved_quantity < $item->quantity) {
                throw new \RuntimeException('Reserved inventory is inconsistent for order '.$order->id);
            }

            $inventory->decrement('reserved_quantity', $item->quantity);
        }
    }

    public function markCancelled(Order $order, string $reasonType, string $reason): Order
    {
        $order->fill([
            'status' => \App\Enums\OrderStatus::Cancelled,
            'cancellation_reason_type' => $reasonType,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $this->loadDetails($order->refresh());
    }
}
