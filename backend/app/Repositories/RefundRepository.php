<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\Refund;
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

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function existsForOrder(int $orderId): bool
    {
        return $this->query()->where('order_id', $orderId)->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createRefund(array $attributes): Refund
    {
        /** @var Refund $refund */
        $refund = $this->query()->create($attributes);

        return $refund->load('order:id,order_number,status,total_amount');
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
        return $this->adminScope($this->query(), $role, $branchId)
            ->when(
                $role === UserRole::SuperAdmin && isset($filters['branch_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'order',
                    fn (Builder $orderQuery): Builder => $orderQuery->where('branch_id', $filters['branch_id']),
                ),
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
                        $nested->where('refund_number', 'like', "%{$keyword}%")
                            ->orWhereHas('order', fn (Builder $orderQuery): Builder => $orderQuery
                                ->where('order_number', 'like', "%{$keyword}%"))
                            ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                                $userQuery->where('name', 'like', "%{$keyword}%")
                                    ->orWhere('email', 'like', "%{$keyword}%");
                            });
                    });
                },
            )
            ->with($this->adminRelations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
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
    ): Refund {
        $refund->fill([
            'status' => 'approved',
            'approved_amount' => $approvedAmount,
            'reviewed_by_user_id' => $reviewerId,
            'review_note' => $reviewNote,
            'reviewed_at' => now(),
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
            'refunded_at' => now(),
        ])->save();

        return $this->findForAdmin($refund->id, UserRole::SuperAdmin, null)
            ?? $refund->refresh();
    }

    /** @return array<int, string> */
    private function adminRelations(): array
    {
        return [
            'order:id,order_number,user_id,branch_id,status,total_amount',
            'order.branch:id,name',
            'user:id,name,email,phone',
            'reviewedBy:id,name',
            'walletTransaction',
        ];
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
                fn (Builder $orderQuery): Builder => $orderQuery->where('branch_id', $branchId),
            );
        }

        return $query->whereRaw('1 = 0');
    }
}
