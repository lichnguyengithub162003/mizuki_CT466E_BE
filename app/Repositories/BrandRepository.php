<?php

namespace App\Repositories;

use App\Models\Brand;
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
}
