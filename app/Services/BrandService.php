<?php

namespace App\Services;

use App\Models\Brand;
use App\Repositories\BrandRepository;
use Illuminate\Database\Eloquent\Collection;

class BrandService extends BaseService
{
    public function __construct(
        private readonly BrandRepository $brands,
    ) {
    }

    /**
     * @return Collection<int, Brand>
     */
    public function getActiveBrands(): Collection
    {
        return $this->brands->getActiveOrdered();
    }

    public function getActiveBrand(string $slug): Brand
    {
        return $this->brands->findActiveBySlugOrFail($slug);
    }
}
