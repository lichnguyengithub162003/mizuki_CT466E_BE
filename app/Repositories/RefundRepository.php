<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReturnStatus;
use App\Enums\UserRole;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Refund> */
class RefundRepository extends BaseRepository
{
    public function __construct(Refund $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback, int $attempts = 3): mixed
    {
        return DB::transaction($callback, $attempts);
    }

    public function existsForOrder(int $orderId): bool
    {
        return $this->query()->where('order_id', $orderId)->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createRefund(array $attributes): Refund
    {
        $attributes['origin'] ??= $this->inferOrigin($attributes);

        /** @var Refund $refund */
        $refund = $this->query()->create($attributes);

        return $refund->load([
            'order:id,order_number,status,subtotal,discount_amount,total_amount',
            'walletTransaction:id',
        ]);
    }

    /** @param array<int, string> $paths */
    public function updateEvidencePaths(Refund $refund, array $paths): Refund
    {
        $refund->fill(['evidence_paths' => $paths])->save();

        return $refund->refresh()->load([
            'order:id,order_number,status,subtotal,discount_amount,total_amount',
            'walletTransaction:id',
        ]);
    }

    public function findForCustomer(int $refundId, int $userId): ?Refund
    {
        return $this->query()
            ->whereKey($refundId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginateForAdmin(
        UserRole $role,
        ?int $branchId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($filters['sort_direction']
            ?? (($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc'));
        $settlementMethod = $filters['settlement_method'] ?? $filters['destination'] ?? null;

        return $this->adminScope($this->query(), $role, $branchId)
            ->when(
                $role === UserRole::SuperAdmin && isset($filters['branch_id']),
                fn(Builder $query): Builder => $query->whereHas(
                    'order',
                    fn(Builder $orderQuery): Builder => $orderQuery->where('branch_id', $filters['branch_id']),
                ),
            )
            ->when(
                isset($filters['status']),
                fn(Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['return_status']),
                fn(Builder $query): Builder => $this->applyReturnStatusFilter(
                    $query,
                    (string) $filters['return_status'],
                ),
            )
            ->when(
                isset($filters['return_inspection_status']),
                fn(Builder $query): Builder => $query->where(
                    'return_inspection_status',
                    $filters['return_inspection_status'],
                ),
            )
            ->when(
                filled($filters['keyword'] ?? null),
                function (Builder $query) use ($filters): void {
                    $keyword = trim((string) $filters['keyword']);
                    $query->where(function (Builder $nested) use ($keyword): void {
                        $nested->where('refund_number', 'like', "%{$keyword}%")
                            ->orWhereHas('order', fn(Builder $orderQuery): Builder => $orderQuery
                                ->where('order_number', 'like', "%{$keyword}%"))
                            ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                                $userQuery->where('name', 'like', "%{$keyword}%")
                                    ->orWhere('email', 'like', "%{$keyword}%")
                                    ->orWhere('phone', 'like', "%{$keyword}%");
                            });
                    });
                },
            )
            ->when(
                $settlementMethod === 'pending',
                fn(Builder $query): Builder => $query->whereNull('settlement_method'),
            )
            ->when(
                $settlementMethod !== null && $settlementMethod !== 'pending',
                function (Builder $query) use ($settlementMethod): Builder {
                    return $query->where(function (Builder $settlementQuery) use ($settlementMethod): void {
                        $settlementQuery->where('settlement_method', $settlementMethod);

                        $settlementQuery->orWhere(function (Builder $legacyQuery) use ($settlementMethod): void {
                            $legacyQuery
                                ->whereNull('settlement_method')
                                ->whereHas('order.payment', function (Builder $paymentQuery) use ($settlementMethod): void {
                                    if ($settlementMethod === 'wallet') {
                                        $paymentQuery->whereIn('method', ['wallet', 'cash', 'vietqr']);
                                    } else {
                                        $paymentQuery->where('method', $settlementMethod);
                                    }
                                });
                        });
                    });
                },
            )
            ->when(
                isset($filters['date_from']),
                fn(Builder $query): Builder => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::parse($filters['date_from'])->startOfDay(),
                ),
            )
            ->when(
                isset($filters['date_to']),
                fn(Builder $query): Builder => $query->where(
                    'created_at',
                    '<=',
                    CarbonImmutable::parse($filters['date_to'])->endOfDay(),
                ),
            )
            ->with($this->adminRelations())
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('id', $sortDirection)
            ->paginate($perPage);
    }

    /** @return array{pending_action: int, requested: int, approved_pending_payout: int} */
    public function countsForAdmin(UserRole $role, ?int $branchId): array
    {
        $query = $this->adminScope($this->query(), $role, $branchId);
        $requested = (clone $query)->where('status', 'requested')->count();
        $approvedPendingPayout = (clone $query)
            ->where('status', 'approved')
            ->whereNull('settlement_method')
            ->whereHas('order.payment', fn(Builder $payment): Builder => $payment
                ->where('status', PaymentStatus::Paid->value))
            ->where(fn(Builder $ready): Builder => $this->applySettlementReadyScope($ready))
            ->count();

        return [
            'pending_action' => $requested + $approvedPendingPayout,
            'requested' => $requested,
            'approved_pending_payout' => $approvedPendingPayout,
        ];
    }

    public function findForAdmin(
        int $refundId,
        UserRole $role,
        ?int $branchId,
    ): ?Refund {
        return $this->adminScope($this->query(), $role, $branchId)
            ->whereKey($refundId)
            ->with($this->adminRelations())
            ->first();
    }

    public function lockForAdmin(
        int $refundId,
        UserRole $role,
        ?int $branchId,
    ): ?Refund {
        return $this->adminScope($this->query(), $role, $branchId)
            ->whereKey($refundId)
            ->with($this->adminRelations())
            ->lockForUpdate()
            ->first();
    }

    public function approve(
        Refund $refund,
        int $approvedAmount,
        int $reviewerId,
        ?string $reviewNote,
        bool $returnRequired,
    ): Refund {
        $refund->fill([
            'status' => 'approved',
            'approved_amount' => $approvedAmount,
            'reviewed_by_user_id' => $reviewerId,
            'review_note' => $reviewNote,
            'reviewed_at' => now(),
            'return_required' => $returnRequired,
            'return_status' => $returnRequired
                ? RefundReturnStatus::AwaitingReturn
                : RefundReturnStatus::NotRequired,
            'return_inspection_status' => $returnRequired ? 'pending' : null,
            'settlement_status' => 'pending',
            'return_received_at' => null,
            'restocked_at' => null,
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function reject(Refund $refund, int $reviewerId, string $reviewNote): Refund
    {
        $refund->fill([
            'status' => 'rejected',
            'approved_amount' => null,
            'reviewed_by_user_id' => $reviewerId,
            'review_note' => $reviewNote,
            'reviewed_at' => now(),
        ])->save();

        if ($refund->order->status === OrderStatus::RefundRequested) {
            $refund->order->fill(['status' => OrderStatus::Delivered])->save();
        }

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    /**
     * @return Collection<int, int>
     */
    public function expiredRequestedIds(CarbonInterface $cutoff, int $limit): Collection
    {
        return $this->query()
            ->where('status', 'requested')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
    }

    public function lockExpiredRequested(int $refundId, CarbonInterface $cutoff): ?Refund
    {
        return $this->query()
            ->whereKey($refundId)
            ->where('status', 'requested')
            ->where('created_at', '<=', $cutoff)
            ->lockForUpdate()
            ->first();
    }

    public function autoApprove(Refund $refund, string $reviewNote): void
    {
        $refund->fill([
            'status' => 'approved',
            'approved_amount' => $refund->requested_amount,
            'reviewed_by_user_id' => null,
            'review_note' => $reviewNote,
            'reviewed_at' => now(),
        ])->save();
    }

    public function markRefunded(
        Refund $refund,
        int $walletTransactionId,
    ): Refund {
        $refund->fill([
            'status' => 'refunded',
            'wallet_transaction_id' => $walletTransactionId,
            'settlement_method' => 'wallet',
            'settlement_reference' => null,
            'settlement_status' => 'succeeded',
            'refunded_at' => now(),
        ])->save();

        $this->synchronizeCompletedRefund($refund);

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function closeWithoutPayout(
        Refund $refund,
        string $settlementMethod = 'no_payout',
        ?string $settlementReference = null,
    ): Refund {
        $refund->fill([
            'status' => 'refunded',
            'wallet_transaction_id' => null,
            'settlement_method' => $settlementMethod,
            'settlement_reference' => $settlementReference,
            'settlement_status' => 'succeeded',
            'refunded_at' => now(),
        ])->save();

        $this->synchronizeCompletedRefund($refund);

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function markReturnReceived(Refund $refund): Refund
    {
        $refund->fill([
            'return_status' => RefundReturnStatus::Received,
            'return_inspection_status' => 'pending',
            'return_received_at' => $refund->return_received_at ?? now(),
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function markReturnNotRestockable(Refund $refund): Refund
    {
        $refund->fill([
            'return_status' => RefundReturnStatus::NotRestockable,
            'return_inspection_status' => 'accepted_not_restockable',
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function restockFullOrder(Refund $refund, int $operatorId): Refund
    {
        $itemsByVariant = $refund->order->items
            ->groupBy('product_variant_id')
            ->sortKeys();

        foreach ($itemsByVariant as $variantId => $items) {
            $inventory = BranchInventory::query()
                ->where('branch_id', $refund->order->branch_id)
                ->where('product_variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                throw new \DomainException('Không tìm thấy tồn kho gốc cho một sản phẩm hoàn trả');
            }

            $quantity = (int) $items->sum('quantity');
            $inventory->increment('quantity', $quantity);
            $inventory->refresh();
            $inventory->transactions()->create([
                'transaction_number' => 'RR-' . substr(hash('sha256', "{$refund->id}:{$inventory->id}"), 0, 20),
                'performed_by_user_id' => $operatorId,
                'type' => 'refund_restock',
                'quantity_delta' => $quantity,
                'reserved_quantity_delta' => 0,
                'quantity_after' => $inventory->quantity,
                'reserved_quantity_after' => $inventory->reserved_quantity,
                'reference_type' => Refund::class,
                'reference_id' => $refund->id,
                'note' => "Nhập lại kho từ {$refund->refund_number}",
            ]);
        }

        $refund->fill([
            'return_status' => RefundReturnStatus::Restocked,
            'return_inspection_status' => 'accepted_restockable',
            'restocked_at' => now(),
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    public function rejectReturnInspection(Refund $refund, int $reviewerId): Refund
    {
        $refund->fill([
            'status' => 'rejected',
            'return_inspection_status' => 'rejected',
            'reviewed_by_user_id' => $reviewerId,
            'reviewed_at' => now(),
            'settlement_status' => null,
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    /** @return array<int, string> */
    private function adminRelations(): array
    {
        return [
            'order:id,order_number,user_id,branch_id,payment_method,status,total_amount',
            'order.branch:id,name',
            'order.payment:id,order_id,method,status',
            'order.items.productVariant.images',
            'order.items.productVariant.product.images',
            'user:id,name,email,phone',
            'reviewedBy:id,name',
            'walletTransaction',
        ];
    }

    private function synchronizeCompletedRefund(Refund $refund): void
    {
        $refund->loadMissing('order.payment');
        $isFullRefund = (int) $refund->approved_amount >= (int) $refund->order->total_amount;

        if (! $isFullRefund) {
            if ($refund->order->status === OrderStatus::RefundRequested) {
                $refund->order->fill(['status' => OrderStatus::Delivered])->save();
            }

            return;
        }

        $refund->order->fill(['status' => OrderStatus::Refunded])->save();

        $payment = $refund->order->payment;

        if ($payment?->status === PaymentStatus::Paid) {
            $payment->fill([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => $refund->refunded_at ?? now(),
            ])->save();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function inferOrigin(array $attributes): string
    {
        $reasonType = (string) ($attributes['reason_type'] ?? '');

        if (in_array($reasonType, ['order_cancellation', 'order_cancelled', 'cancelled'], true)) {
            return 'order_cancellation';
        }

        $orderStatus = isset($attributes['order_id'])
            ? Order::query()->whereKey($attributes['order_id'])->value('status')
            : null;

        return $orderStatus === OrderStatus::Cancelled->value
            ? 'order_cancellation'
            : 'customer_return';
    }

    private function applyReturnStatusFilter(Builder $query, string $returnStatus): Builder
    {
        return $query->where(function (Builder $status) use ($returnStatus): void {
            $status->where('return_status', $returnStatus);

            if ($returnStatus === RefundReturnStatus::AwaitingReturn->value) {
                $status->orWhere(fn(Builder $legacy): Builder => $legacy
                    ->whereNull('return_status')
                    ->where('return_required', true));
            }

            if ($returnStatus === RefundReturnStatus::NotRequired->value) {
                $status->orWhere(fn(Builder $legacy): Builder => $legacy
                    ->whereNull('return_status')
                    ->where(fn(Builder $required): Builder => $required
                        ->whereNull('return_required')
                        ->orWhere('return_required', false)));
            }
        });
    }

    private function applySettlementReadyScope(Builder $query): Builder
    {
        return $query
            ->whereNull('return_required')
            ->orWhere('return_required', false)
            ->orWhereIn('return_inspection_status', [
                'accepted_restockable',
                'accepted_not_restockable',
            ])
            ->orWhere(function (Builder $legacy): void {
                $legacy->whereNull('return_inspection_status')
                    ->whereIn('return_status', [
                        RefundReturnStatus::Restocked->value,
                        RefundReturnStatus::NotRestockable->value,
                    ]);
            });
    }

    /**
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    private function adminScope(Builder $query, UserRole $role, ?int $branchId): Builder
    {
        if ($role === UserRole::SuperAdmin) {
            return $query;
        }

        if ($role === UserRole::BranchManager && $branchId !== null) {
            return $query->whereHas(
                'order',
                fn(Builder $orderQuery): Builder => $orderQuery->where('branch_id', $branchId),
            );
        }

        return $query->whereRaw('1 = 0');
    }
}
