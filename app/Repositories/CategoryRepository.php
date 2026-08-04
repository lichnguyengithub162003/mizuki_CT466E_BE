<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\ProductImage;
use App\Services\Import\ProductImageImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Category>
 */
class CategoryRepository extends BaseRepository
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, Category>
     */
    public function getActiveOrdered(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function getActiveCategoryAndDescendantIds(int $categoryId): array
    {
        $categories = $this->getActiveOrdered();

        if (! $categories->contains('id', $categoryId)) {
            return [-1];
        }

        $childrenByParent = $categories->groupBy('parent_id');
        $ids = [];
        $queue = [$categoryId];

        for ($index = 0; $index < count($queue); $index++) {
            $currentId = $queue[$index];
            $ids[] = $currentId;

            /** @var Category $child */
            foreach ($childrenByParent->get($currentId, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, ProductImage>
     */
    public function getActiveThumbnailCandidates(): Collection
    {
        $absoluteStoragePattern = rtrim(url('/storage'), '/').'/%';

        return ProductImage::query()
            ->select(['product_images.image_url', 'products.category_id'])
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->where('product_images.is_primary', true)
            ->where('product_images.image_url', '<>', ProductImageImportService::FALLBACK_URL)
            ->where(function (Builder $query) use ($absoluteStoragePattern): void {
                $query->where('product_images.image_url', 'like', '/storage/%')
                    ->orWhere('product_images.image_url', 'like', 'storage/%')
                    ->orWhere('product_images.image_url', 'like', $absoluteStoragePattern);
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_active', true)
                    ->whereNull('product_variants.deleted_at');
            })
            ->orderBy('products.id')
            ->orderBy('product_images.sort_order')
            ->orderBy('product_images.id')
            ->get()
            ->unique('category_id')
            ->values();
    }
}
