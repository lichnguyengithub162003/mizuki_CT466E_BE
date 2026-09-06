<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\User;
use App\Repositories\BrandRepository;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BrandService extends BaseService
{
    public function __construct(
        private readonly BrandRepository $brands,
        private readonly MediaUrl $mediaUrl,
    ) {}

    /**
     * @return Collection<int, Brand>
     */
    public function getActiveBrands(): Collection
    {
        return $this->attachLogoUrls($this->brands->getActiveOrdered());
    }

    public function getActiveBrand(string $slug): Brand
    {
        $brands = $this->attachLogoUrls(new Collection([
            $this->brands->findActiveBySlugOrFail($slug),
        ]));

        /** @var Brand $brand */
        $brand = $brands->first();

        return $brand;
    }

    /**
     * @return array{follower_count: int, is_following: true}
     */
    public function follow(User $user, Brand $brand): array
    {
        return DB::transaction(function () use ($user, $brand): array {
            if ($this->brands->createFollowIfMissing($user, $brand)) {
                $this->brands->incrementFollowerCount($brand);
            }

            return [
                'follower_count' => $this->brands->followerCount($brand),
                'is_following' => true,
            ];
        });
    }

    /**
     * @return array{follower_count: int, is_following: false}
     */
    public function unfollow(User $user, Brand $brand): array
    {
        return DB::transaction(function () use ($user, $brand): array {
            if ($this->brands->deleteFollow($user, $brand)) {
                $this->brands->decrementFollowerCount($brand);
            }

            return [
                'follower_count' => $this->brands->followerCount($brand),
                'is_following' => false,
            ];
        });
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @return Collection<int, Brand>
     */
    private function attachLogoUrls(Collection $brands): Collection
    {
        return $brands->map(function (Brand $brand): Brand {
            $brand->setAttribute(
                'resolved_logo_url',
                $this->mediaUrl->brandLogo($brand->logo_url, $brand->slug, $brand->name),
            );

            return $brand;
        });
    }
}
