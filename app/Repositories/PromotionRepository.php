<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\Promotion;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<Promotion>
 */
class PromotionRepository extends BaseRepository
{
    public function __construct(Promotion $model)
    {
        parent::__construct($model);
    }

    public function findByCodeForUser(string $code, int $userId): ?Promotion
    {
        return $this->withEligibilityData($userId)
            ->where('code', $code)
            ->first();
    }

    /**
     * @return Collection<int, Promotion>
     */
    public function getCandidatesForUser(int $userId): Collection
    {
        return $this->withEligibilityData($userId)
            ->whereNotNull('code')
            ->where('applies_to', 'order')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Promotion>
     */
    public function paginateForAdmin(
        UserRole $role,
        ?int $branchId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        $query = $this->query()
            ->with('branches:id')
            ->withCount('usages');

        if ($role === UserRole::BranchManager) {
            if ($branchId === null) {
                $query->whereKey([]);
            } else {
                $query
                    ->whereHas('branches', fn (Builder $branchQuery): Builder => $branchQuery
                        ->where('branches.id', $branchId))
                    ->where(function (Builder $scopeQuery): void {
                        $scopeQuery
                            ->whereNull('scope')
                            ->orWhereNull('scope->user_ids')
                            ->orWhereJsonLength('scope->user_ids', 0);
                    });
            }
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL));
        }

        if (isset($filters['discount_type'])) {
            $query->where('discount_type', $filters['discount_type']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function findForAdmin(int $id): ?Promotion
    {
        return $this->query()
            ->with('branches:id')
            ->withCount('usages')
            ->find($id);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, int> $branchIds
     */
    public function createWithBranches(array $attributes, array $branchIds): Promotion
    {
        return DB::transaction(function () use ($attributes, $branchIds): Promotion {
            /** @var Promotion $promotion */
            $promotion = $this->query()->create($attributes);
            $promotion->branches()->sync($branchIds);

            return $this->findForAdmin($promotion->id) ?? $promotion;
        });
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, int>|null $branchIds
     */
    public function updateWithBranches(
        Promotion $promotion,
        array $attributes,
        ?array $branchIds,
    ): Promotion {
        return DB::transaction(function () use ($promotion, $attributes, $branchIds): Promotion {
            if ($attributes !== []) {
                $promotion->fill($attributes)->save();
            }

            if ($branchIds !== null) {
                $promotion->branches()->sync($branchIds);
            }

            return $this->findForAdmin($promotion->id) ?? $promotion->refresh();
        });
    }

    public function deletePromotion(Promotion $promotion): bool
    {
        return DB::transaction(function () use ($promotion): bool {
            $promotion->branches()->detach();

            return (bool) $promotion->delete();
        });
    }

    public function findWithUsageStats(int $id): ?Promotion
    {
        return $this->query()
            ->with('branches:id')
            ->withCount('usages')
            ->find($id);
    }

    public function lockForCheckout(int $promotionId, int $userId): ?Promotion
    {
        return $this->withEligibilityData($userId)
            ->whereKey($promotionId)
            ->lockForUpdate()
            ->first();
    }

    public function recordUsage(Promotion $promotion, User $user, Order $order, int $discountAmount): void
    {
        $promotion->increment('usage_count');
        $promotion->usages()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'promotion_code' => $promotion->code,
            'promotion_name' => $promotion->name,
            'discount_amount' => $discountAmount,
            'used_at' => now(),
        ]);
    }

    /**
     * @return Builder<Promotion>
     */
    private function withEligibilityData(int $userId): Builder
    {
        return $this->query()
            ->with([
                'branches' => fn (Builder|BelongsToMany $query): Builder|BelongsToMany => $query
                    ->select('branches.id'),
            ])
            ->withCount('usages')
            ->withCount([
                'usages as user_usage_count' => fn (Builder $query): Builder => $query
                    ->where('user_id', $userId),
            ]);
    }
}
