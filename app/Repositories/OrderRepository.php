<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\UserAddress;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function lockAddressForUser(int $addressId, int $userId): ?UserAddress
    {
        return $this->addresses->newQuery()
            ->whereKey($addressId)
            ->where('user_id', $userId)
            ->lockForUpdate()
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
            'payment',
            'shipment',
            'refunds' => fn (Builder|HasMany $query): Builder|HasMany => $query->orderByDesc('id'),
            'items' => fn (Builder|HasMany $query): Builder|HasMany => $query->orderBy('id'),
            'items.review' => fn (HasOne $review): HasOne => $review->withTrashed(),
            'items.productVariant' => fn (BelongsTo $variant): BelongsTo => $variant->withTrashed(),
            'items.productVariant.product' => fn (BelongsTo $product): BelongsTo => $product->withTrashed(),
            'items.productVariant.product.reviews' => fn (HasMany $reviews): HasMany => $reviews
                ->withTrashed()
                ->where('user_id', $order->user_id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
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
            ->with('payment')
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
            'status' => OrderStatus::Cancelled,
            'cancellation_reason_type' => $reasonType,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $this->loadDetails($order->refresh());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForAdmin(
        UserRole $role,
        ?int $branchId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->adminScope($this->query(), $role, $branchId)
            ->when(
                $role === UserRole::SuperAdmin && isset($filters['branch_id']),
                fn (Builder $query): Builder => $query->where('branch_id', $filters['branch_id']),
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                filled($filters['keyword'] ?? null),
                function (Builder $query) use ($filters): void {
                    $keyword = trim((string) $filters['keyword']);
                    $query->where(function (Builder $nested) use ($keyword): void {
                        $nested->where('order_number', 'like', "%{$keyword}%")
                            ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                                $userQuery->where('name', 'like', "%{$keyword}%")
                                    ->orWhere('email', 'like', "%{$keyword}%");
                            });
                    });
                },
            )
            ->with([
                'user:id,name,email,phone',
                'branch:id,name,address',
                'items',
                'payment',
                'shipment',
                'refunds',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForAdmin(
        int $orderId,
        UserRole $role,
        ?int $branchId,
    ): ?Order {
        return $this->adminScope($this->query(), $role, $branchId)
            ->whereKey($orderId)
            ->with([
                'user:id,name,email,phone',
                'branch:id,name,address',
                'items',
                'payment',
                'shipment',
                'refunds.reviewedBy:id,name',
            ])
            ->first();
    }

    public function lockForAdmin(
        int $orderId,
        UserRole $role,
        ?int $branchId,
    ): ?Order {
        return $this->adminScope($this->query(), $role, $branchId)
            ->whereKey($orderId)
            ->with(['items', 'payment', 'shipment'])
            ->lockForUpdate()
            ->first();
    }

    public function lockForAdminShipment(
        int $orderId,
        UserRole $role,
        ?int $branchId,
    ): ?Order {
        return $this->adminScope($this->query(), $role, $branchId)
            ->whereKey($orderId)
            ->with([
                'branch:id,name,phone,address,ghn_district_id,ghn_ward_code,is_active',
                'items.productVariant:id,weight',
                'shipment',
            ])
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function createShipment(Order $order, array $attributes): Shipment
    {
        /** @var Shipment $shipment */
        $shipment = $order->shipment()->create($attributes);

        return $shipment;
    }

    public function markConfirmed(Order $order): Order
    {
        return $this->markStatus($order, OrderStatus::Confirmed);
    }

    public function markProcessing(Order $order): Order
    {
        return $this->markStatus($order, OrderStatus::Processing);
    }

    public function markDelivered(Order $order): Order
    {
        return $this->markStatus($order, OrderStatus::Delivered);
    }

    public function consumeReservedInventory(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = $this->lockInventory($order->branch_id, $item->product_variant_id);

            if ($inventory === null
                || $inventory->reserved_quantity < $item->quantity
                || $inventory->quantity < $item->quantity) {
                throw new \RuntimeException('Reserved inventory is inconsistent for order '.$order->id);
            }

            $inventory->decrement('quantity', $item->quantity);
            $inventory->decrement('reserved_quantity', $item->quantity);
        }
    }

    private function markStatus(Order $order, OrderStatus $status): Order
    {
        $order->fill(['status' => $status])->save();

        return $this->findForAdmin($order->id, UserRole::SuperAdmin, null)
            ?? $order->refresh();
    }

    /**
     * Scope internal access at query level to prevent cross-branch disclosure.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function adminScope(Builder $query, UserRole $role, ?int $branchId): Builder
    {
        if ($role === UserRole::SuperAdmin) {
            return $query;
        }

        if ($role === UserRole::BranchManager && $branchId !== null) {
            return $query->where('branch_id', $branchId);
        }

        return $query->whereRaw('1 = 0');
    }
}
