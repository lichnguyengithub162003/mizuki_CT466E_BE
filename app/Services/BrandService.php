<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\User;
use App\Repositories\BrandRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandService extends BaseService
{
    public function __construct(
        private readonly BrandRepository $brands,
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
        $logosByKey = collect(Storage::disk('public')->files('catalog/brands'))
            ->filter(fn (string $path): bool => in_array(
                Str::lower(pathinfo($path, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp', 'svg'],
                true,
            ))
            ->sort()
            ->mapWithKeys(fn (string $path): array => [
                Str::slug(pathinfo($path, PATHINFO_FILENAME)) => $path,
            ]);

        return $brands->map(function (Brand $brand) use ($logosByKey): Brand {
            $logoPath = collect([$brand->slug, $brand->name])
                ->map(fn (string $value): string => Str::slug($value))
                ->filter()
                ->map(fn (string $key): ?string => $logosByKey->get($key))
                ->first(fn (?string $path): bool => $path !== null);

            $brand->setAttribute(
                'resolved_logo_url',
                $logoPath === null ? null : url(Storage::disk('public')->url($logoPath)),
            );

            return $brand;
        });
    }
}
