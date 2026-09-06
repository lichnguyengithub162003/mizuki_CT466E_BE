<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Collection;

class CatalogMediaMigrationRepository
{
    /**
     * @param  list<int>  $productIds
     * @return Collection<int, int>
     */
    public function productIds(int $limit, ?int $fromId, ?int $toId, array $productIds): Collection
    {
        return Product::query()
            ->when($productIds !== [], fn ($query) => $query->whereIn('id', $productIds))
            ->when($fromId !== null, fn ($query) => $query->where('id', '>=', $fromId))
            ->when($toId !== null, fn ($query) => $query->where('id', '<=', $toId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, ProductImage>
     */
    public function images(Collection $productIds): Collection
    {
        return ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();
    }

    public function replaceReference(ProductImage $image, string $expected, string $replacement): bool
    {
        return ProductImage::query()
            ->whereKey($image->id)
            ->where('image_url', $expected)
            ->update(['image_url' => $replacement]) === 1;
    }
}
