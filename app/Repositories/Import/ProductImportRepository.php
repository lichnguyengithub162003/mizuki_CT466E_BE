<?php

namespace App\Repositories\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductImportRepository
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array{create: int, update: int, unchanged: int}>
     */
    public function plan(array $records): array
    {
        $brands = $this->uniqueByNestedKey($records, 'brand', 'slug');
        $categories = $this->uniqueCategories($records);
        $products = $this->uniqueByNestedKey($records, 'product', 'slug');
        $variants = $this->uniqueByNestedKey($records, 'variant', 'sku');

        $existingBrands = Brand::query()
            ->withTrashed()
            ->whereIn('slug', array_keys($brands))
            ->get()
            ->keyBy('slug');
        $existingCategories = Category::query()
            ->withTrashed()
            ->whereIn('slug', array_keys($categories))
            ->get()
            ->keyBy('slug');
        $existingProducts = Product::query()
            ->withTrashed()
            ->whereIn('slug', array_keys($products))
            ->get()
            ->keyBy('slug');
        $existingVariants = ProductVariant::query()
            ->withTrashed()
            ->whereIn('sku', array_keys($variants))
            ->get()
            ->keyBy('sku');

        $plans = [
            'brands' => $this->planModels($brands, $existingBrands),
            'categories' => $this->planModels($categories, $existingCategories, ['parent_slug']),
            'products' => $this->planProducts(
                $records,
                $existingProducts,
                $existingBrands,
                $existingCategories,
            ),
            'variants' => $this->planVariants($records, $existingVariants, $existingProducts),
            'images' => $this->planImages($records, $existingProducts),
        ];

        return $plans;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function uniqueByNestedKey(array $records, string $section, string $key): array
    {
        $unique = [];

        foreach ($records as $record) {
            /** @var array<string, mixed> $attributes */
            $attributes = $record[$section];
            $unique[(string) $attributes[$key]] = $attributes;
        }

        return $unique;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function uniqueCategories(array $records): array
    {
        $categories = [];

        foreach ($records as $record) {
            foreach ($record['categories'] as $category) {
                $categories[(string) $category['slug']] = $category;
            }
        }

        return $categories;
    }

    /**
     * @param  array<string, array<string, mixed>>  $desired
     * @param  Collection<string, Model>  $existing
     * @param  list<string>  $ignored
     * @return array{create: int, update: int, unchanged: int}
     */
    private function planModels(array $desired, Collection $existing, array $ignored = []): array
    {
        $counters = $this->emptyCounters();

        foreach ($desired as $key => $attributes) {
            $model = $existing->get($key);
            $operation = $model === null
                ? 'create'
                : ($this->matches($model, $attributes, $ignored) ? 'unchanged' : 'update');
            $counters[$operation]++;
        }

        return $counters;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  Collection<string, Product>  $existingProducts
     * @param  Collection<string, Brand>  $existingBrands
     * @param  Collection<string, Category>  $existingCategories
     * @return array{create: int, update: int, unchanged: int}
     */
    private function planProducts(
        array $records,
        Collection $existingProducts,
        Collection $existingBrands,
        Collection $existingCategories,
    ): array {
        $desired = [];

        foreach ($records as $record) {
            $attributes = $record['product'];
            $brand = $existingBrands->get($record['brand']['slug']);
            $category = $existingCategories->get($record['category_slug']);

            if ($brand !== null) {
                $attributes['brand_id'] = $brand->id;
            }

            if ($category !== null) {
                $attributes['category_id'] = $category->id;
            }

            $desired[$record['product_slug']] = $attributes;
        }

        return $this->planModels($desired, $existingProducts);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  Collection<string, ProductVariant>  $existingVariants
     * @param  Collection<string, Product>  $existingProducts
     * @return array{create: int, update: int, unchanged: int}
     */
    private function planVariants(
        array $records,
        Collection $existingVariants,
        Collection $existingProducts,
    ): array {
        $desired = [];

        foreach ($records as $record) {
            $attributes = array_filter(
                $record['variant'],
                fn (mixed $value, string $key): bool => $key !== 'weight' || $value !== null,
                ARRAY_FILTER_USE_BOTH,
            );
            $product = $existingProducts->get($record['product_slug']);

            if ($product !== null) {
                $attributes['product_id'] = $product->id;
            }

            $desired[$record['synthetic_sku']] = $attributes;
        }

        return $this->planModels($desired, $existingVariants);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  Collection<string, Product>  $existingProducts
     * @return array{create: int, update: int, unchanged: int}
     */
    private function planImages(array $records, Collection $existingProducts): array
    {
        $counters = $this->emptyCounters();
        $productIds = $existingProducts->pluck('id')->map(
            static fn (mixed $id): int => (int) $id,
        )->all();
        $existingImages = $productIds === []
            ? collect()
            : ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy(fn (ProductImage $image): string => $image->product_id.'|'.$image->image_url);

        foreach ($records as $record) {
            $product = $existingProducts->get($record['product_slug']);

            foreach ($record['images'] as $image) {
                if ($product === null) {
                    $counters['create']++;

                    continue;
                }

                $existing = $existingImages->get($product->id.'|'.$image['image_url']);

                if ($existing === null) {
                    $counters['create']++;
                } elseif ($this->matches($existing, $image)) {
                    $counters['unchanged']++;
                } else {
                    $counters['update']++;
                }
            }
        }

        return $counters;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $ignored
     */
    private function matches(Model $model, array $attributes, array $ignored = []): bool
    {
        if ($model->getAttribute('deleted_at') !== null) {
            return false;
        }

        foreach ($attributes as $key => $expected) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $actual = $model->getAttribute($key);

            if (is_array($expected)) {
                if ($actual !== $expected) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{create: int, update: int, unchanged: int}
     */
    private function emptyCounters(): array
    {
        return ['create' => 0, 'update' => 0, 'unchanged' => 0];
    }
}
