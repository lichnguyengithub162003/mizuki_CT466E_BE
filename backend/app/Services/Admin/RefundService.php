<?php

namespace App\Services\Admin;

use App\Models\Refund;
use App\Models\User;
use App\Repositories\RefundRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RefundService extends BaseService
{
    public function __construct(
        private readonly RefundRepository $refunds,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Refund::class);

        return $this->refunds->paginateForAdmin(
            role: $user->role,
            branchId: $user->branch_id,
            filters: $filters,
            perPage: (int) ($filters['per_page'] ?? 20),
        );
    }

    public function detail(User $user, int $refundId): ?Refund
    {
        $refund = $this->refunds->findForAdmin($refundId, $user->role, $user->branch_id);

        if ($refund === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $refund);

        return $refund;
    }

    /** @param array{approved_amount?: int, review_note?: string|null} $data */
    public function approve(User $user, int $refundId, array $data): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId, $data): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('review', $refund);
            $this->ensureRequested($refund);

            $approvedAmount = (int) ($data['approved_amount'] ?? $refund->requested_amount);

            if ($approvedAmount > $refund->requested_amount) {
                throw ValidationException::withMessages([
                    'approved_amount' => ['Số tiền duyệt không được vượt quá số tiền yêu cầu'],
                ]);
            }

            return $this->refunds->approve(
                refund: $refund,
                approvedAmount: $approvedAmount,
                reviewerId: $user->id,
                reviewNote: $data['review_note'] ?? null,
            );
        });
    }

    /** @param array{review_note: string} $data */
    public function reject(User $user, int $refundId, array $data): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId, $data): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('review', $refund);
            $this->ensureRequested($refund);

            return $this->refunds->reject(
                refund: $refund,
                reviewerId: $user->id,
                reviewNote: $data['review_note'],
            );
        });
    }

    private function ensureRequested(Refund $refund): void
    {
        if ($refund->status !== 'requested') {
            throw ValidationException::withMessages([
                'status' => ['Yêu cầu hoàn tiền đã được xử lý'],
            ]);
        }
    }
}
