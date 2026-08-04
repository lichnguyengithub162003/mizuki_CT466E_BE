<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NumberedProductImageRepository
{
    /**
     * @return Collection<int, Product>
     */
    public function importedProducts(string $source, ?string $externalId = null): Collection
    {
        return Product::query()
            ->where('source', $source)
            ->whereNotNull('external_id')
            ->when(
                $externalId !== null,
                fn ($query) => $query->where('external_id', $externalId),
            )
            ->orderBy('id')
            ->get(['id', 'name', 'source', 'external_id']);
    }

    /**
     * @param  list<array{image_url: string, sort_order: int, is_primary: bool}>  $targets
     * @return array{inserted: int, updated: int, deleted: int}
     */
    public function reconcile(Product $product, array $targets, bool $dryRun): array
    {
        if ($dryRun) {
            $images = ProductImage::query()
                ->where('product_id', $product->id)
                ->whereNull('product_variant_id')
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return $this->reconciliationPlan($product, $images, $targets);
        }

        return DB::transaction(function () use ($product, $targets): array {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $images = ProductImage::query()
                ->where('product_id', $product->id)
                ->whereNull('product_variant_id')
                ->lockForUpdate()
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            $result = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

            foreach ($targets as $index => $target) {
                $image = $images->get($index);
                $attributes = $target + [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'alt_text' => filled($image?->alt_text) ? $image->alt_text : $product->name,
                ];

                if ($image === null) {
                    ProductImage::query()->create($attributes);
                    $result['inserted']++;

                    continue;
                }

                $image->fill($attributes);

                if ($image->isDirty()) {
                    $image->save();
                    $result['updated']++;
                }
            }

            foreach ($images->slice(count($targets)) as $image) {
                $image->delete();
                $result['deleted']++;
            }

            return $result;
        });
    }

    /**
     * @return list<string>
     */
    public function referencedImageUrls(string $externalId): array
    {
        $relative = "catalog/products/{$externalId}/";

        return ProductImage::query()
            ->where(function ($query) use ($relative): void {
                $query->where('image_url', 'like', $relative.'%')
                    ->orWhere('image_url', 'like', '/storage/'.$relative.'%')
                    ->orWhere('image_url', 'like', '%/storage/'.$relative.'%');
            })
            ->pluck('image_url')
            ->map(static fn (mixed $url): string => (string) $url)
            ->all();
    }

    /**
     * @param  Collection<int, ProductImage>  $images
     * @param  list<array{image_url: string, sort_order: int, is_primary: bool}>  $targets
     * @return array{inserted: int, updated: int, deleted: int}
     */
    private function reconciliationPlan(Product $product, Collection $images, array $targets): array
    {
        $result = [
            'inserted' => max(0, count($targets) - $images->count()),
            'updated' => 0,
            'deleted' => max(0, $images->count() - count($targets)),
        ];

        foreach ($targets as $index => $target) {
            $image = $images->get($index);

            if ($image === null) {
                continue;
            }

            $attributes = $target + [
                'product_id' => $product->id,
                'product_variant_id' => null,
                'alt_text' => filled($image->alt_text) ? $image->alt_text : $product->name,
            ];
            $image->fill($attributes);

            if ($image->isDirty()) {
                $result['updated']++;
            }
        }

        return $result;
    }
}
