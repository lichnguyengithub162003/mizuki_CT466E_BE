<?php

namespace App\Services;

use App\Models\Brand;
use App\Repositories\BrandRepository;
use Illuminate\Database\Eloquent\Collection;
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

    public function follow(Brand $brand): int
    {
        return $this->brands->incrementFollowerCount($brand);
    }

    public function unfollow(Brand $brand): int
    {
        return $this->brands->decrementFollowerCount($brand);
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
