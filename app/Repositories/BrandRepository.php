<?php

namespace App\Repositories;

use App\Models\Brand;
use App\Models\BrandFollow;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Brand>
 */
class BrandRepository extends BaseRepository
{
    public function __construct(Brand $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, Brand>
     */
    public function getActiveOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findActiveBySlugOrFail(string $slug): Brand
    {
        /** @var Brand $brand */
        $brand = $this->query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->firstOrFail();

        return $brand;
    }

    public function createFollowIfMissing(User $user, Brand $brand): bool
    {
        $now = now();

        return BrandFollow::query()->insertOrIgnore([
            'user_id' => $user->getKey(),
            'brand_id' => $brand->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    public function deleteFollow(User $user, Brand $brand): bool
    {
        return BrandFollow::query()
            ->where('user_id', $user->getKey())
            ->where('brand_id', $brand->getKey())
            ->delete() === 1;
    }

    public function isFollowedBy(Brand $brand, User $user): bool
    {
        return BrandFollow::query()
            ->where('user_id', $user->getKey())
            ->where('brand_id', $brand->getKey())
            ->exists();
    }

    public function incrementFollowerCount(Brand $brand): void
    {
        $this->query()->whereKey($brand->getKey())->increment('follower_count');
    }

    public function decrementFollowerCount(Brand $brand): void
    {
        $this->query()
            ->whereKey($brand->getKey())
            ->where('follower_count', '>', 0)
            ->decrement('follower_count');
    }

    public function followerCount(Brand $brand): int
    {
        return (int) $this->query()
            ->whereKey($brand->getKey())
            ->firstOrFail(['follower_count'])
            ->follower_count;
    }
}
