<?php

namespace App\Services\Admin;

use App\Models\Promotion;
use App\Models\User;
use App\Repositories\PromotionRepository;
use App\Services\BaseService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PromotionService extends BaseService
{
    public function __construct(
        private readonly PromotionRepository $promotions,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Promotion>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Promotion::class);

        return $this->promotions->paginateForAdmin(
            role: $user->role,
            branchId: $user->branch_id,
            filters: $filters,
            perPage: (int) ($filters['per_page'] ?? 15),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(User $user, array $data): Promotion
    {
        Gate::forUser($user)->authorize('create', Promotion::class);

        $branchIds = $this->integerIds($data['branch_ids'] ?? []);
        $userIds = $this->integerIds($data['user_ids'] ?? []);

        if ($userIds !== []) {
            Gate::forUser($user)->authorize('createPersonal', Promotion::class);
        }

        $this->authorizeBranches($user, $branchIds);

        unset($data['branch_ids'], $data['user_ids']);
        $data['scope'] = $userIds === [] ? null : ['user_ids' => $userIds];
        $data['usage_count'] ??= 0;

        return $this->promotions->createWithBranches($data, $branchIds);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $user, int $id, array $data): ?Promotion
    {
        $promotion = $this->promotions->findForAdmin($id);

        if ($promotion === null) {
            return null;
        }

        Gate::forUser($user)->authorize('update', $promotion);

        $branchIds = array_key_exists('branch_ids', $data)
            ? $this->integerIds($data['branch_ids'])
            : null;
        $userIds = array_key_exists('user_ids', $data)
            ? $this->integerIds($data['user_ids'])
            : null;

        if ($userIds !== null) {
            Gate::forUser($user)->authorize('createPersonal', Promotion::class);
            $branchIds = [];
            $data['scope'] = ['user_ids' => $userIds];
        } elseif ($branchIds !== null) {
            $data['scope'] = null;
        }

        if ($branchIds !== null) {
            $this->authorizeBranches($user, $branchIds);
        }

        unset($data['branch_ids'], $data['user_ids']);
        $this->validateEffectiveValues($promotion, $data);

        return $this->promotions->updateWithBranches($promotion, $data, $branchIds);
    }

    public function delete(User $user, int $id): ?bool
    {
        $promotion = $this->promotions->findForAdmin($id);

        if ($promotion === null) {
            return null;
        }

        Gate::forUser($user)->authorize('delete', $promotion);

        return $this->promotions->deletePromotion($promotion);
    }

    public function usageStats(User $user, int $id): ?Promotion
    {
        $promotion = $this->promotions->findWithUsageStats($id);

        if ($promotion === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $promotion);

        return $promotion;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function integerIds(array $ids): array
    {
        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * @param array<int, int> $branchIds
     */
    private function authorizeBranches(User $user, array $branchIds): void
    {
        foreach ($branchIds as $branchId) {
            Gate::forUser($user)->authorize('assignBranch', [Promotion::class, $branchId]);
        }
    }

    /**
     * Validate values that depend on both persisted and submitted state.
     *
     * @param array<string, mixed> $data
     */
    private function validateEffectiveValues(Promotion $promotion, array $data): void
    {
        $discountType = $data['discount_type'] ?? $promotion->discount_type;
        $discountValue = (int) ($data['discount_value'] ?? $promotion->discount_value);

        if ($discountType === 'percentage' && $discountValue > 100) {
            throw ValidationException::withMessages([
                'discount_value' => ['Giá trị giảm theo phần trăm phải từ 1 đến 100'],
            ]);
        }

        $startsAt = array_key_exists('starts_at', $data)
            ? CarbonImmutable::parse($data['starts_at'])
            : $promotion->starts_at->toImmutable();
        $endsAt = array_key_exists('ends_at', $data)
            ? ($data['ends_at'] === null ? null : CarbonImmutable::parse($data['ends_at']))
            : $promotion->ends_at?->toImmutable();

        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['Thời gian kết thúc phải sau thời gian bắt đầu'],
            ]);
        }
    }
}
