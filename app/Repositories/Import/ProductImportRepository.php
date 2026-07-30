<?php

namespace App\Repositories\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductImportRepository
{
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    /**
     * @param  list<string>  $barcodes
     * @return array<string, string>
     */
    public function barcodeOwners(array $barcodes): array
    {
        if ($barcodes === []) {
            return [];
        }

        return ProductVariant::query()
            ->withTrashed()
            ->whereIn('barcode', array_values(array_unique($barcodes)))
            ->pluck('sku', 'barcode')
            ->map(static fn (mixed $sku): string => (string) $sku)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function persistBatch(array $records): array
    {
        $counters = [
            'brands' => $this->writeCounters(),
            'categories' => $this->writeCounters(),
            'products' => $this->writeCounters(),
            'variants' => $this->writeCounters(),
            'images' => [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'stale_skipped' => 0,
            ],
        ];
        $brands = $this->persistBrands($records, $counters['brands']);
        $categories = $this->persistCategories($records, $counters['categories']);
        $samples = [];

        foreach ($records as $record) {
            $brand = $brands[(string) $record['brand']['slug']];
            $category = $categories[(string) $record['category_slug']];
            $product = $this->persistProduct(
                $record,
                $brand,
                $category,
                $counters['products'],
            );
            $variant = $this->persistVariant($record, $product, $counters['variants']);
            $this->persistImages($record, $product, $counters['images']);

            if (count($samples) < 5) {
                $samples[] = [
                    'source_id' => $record['source_id'],
                    'product_id' => $product->id,
                    'product_slug' => $product->slug,
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                ];
            }
        }

        return [
            'write_counters' => $counters,
            'imported_mappings' => $samples,
        ];
    }

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
     * @param  array{created: int, updated: int, restored: int, unchanged: int}  $counters
     * @return array<string, Brand>
     */
    private function persistBrands(array $records, array &$counters): array
    {
        $resolved = [];

        foreach ($this->uniqueByNestedKey($records, 'brand', 'slug') as $slug => $attributes) {
            $brand = Brand::query()->withTrashed()->where('slug', $slug)->lockForUpdate()->first();

            if ($brand === null) {
                $brand = Brand::query()->create($attributes);
                $counters['created']++;
            } else {
                $restored = $brand->trashed();
                $brand->fill(['name' => $attributes['name']]);

                if ($restored) {
                    $brand->is_active = true;
                    $brand->restore();
                    $counters['restored']++;
                } elseif ($brand->isDirty()) {
                    $brand->save();
                    $counters['updated']++;
                } else {
                    $counters['unchanged']++;
                }
            }

            $resolved[$slug] = $brand;
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array{created: int, updated: int, restored: int, unchanged: int}  $counters
     * @return array<string, Category>
     */
    private function persistCategories(array $records, array &$counters): array
    {
        $resolved = [];

        foreach ($this->uniqueCategories($records) as $slug => $attributes) {
            $parentSlug = $attributes['parent_slug'];
            $parentId = $parentSlug === null ? null : $resolved[$parentSlug]->id;
            $category = Category::query()->withTrashed()->where('slug', $slug)->lockForUpdate()->first();

            if ($category === null) {
                unset($attributes['parent_slug']);
                $category = Category::query()->create($attributes + ['parent_id' => $parentId]);
                $counters['created']++;
            } else {
                $restored = $category->trashed();
                $category->fill(['name' => $attributes['name'], 'parent_id' => $parentId]);

                if ($restored) {
                    $category->is_active = true;
                    $category->restore();
                    $counters['restored']++;
                } elseif ($category->isDirty()) {
                    $category->save();
                    $counters['updated']++;
                } else {
                    $counters['unchanged']++;
                }
            }

            $resolved[$slug] = $category;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{created: int, updated: int, restored: int, unchanged: int}  $counters
     */
    private function persistProduct(
        array $record,
        Brand $brand,
        Category $category,
        array &$counters,
    ): Product {
        $product = Product::query()->withTrashed()
            ->where('slug', $record['product_slug'])->lockForUpdate()->first();
        $attributes = $record['product'] + [
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ];

        if ($product === null) {
            $product = Product::query()->create($attributes);
            $counters['created']++;

            return $product;
        }

        $restored = $product->trashed();
        unset($attributes['is_featured']);
        $product->fill($attributes);

        if ($restored) {
            $product->is_active = true;
            $product->restore();
            $counters['restored']++;
        } elseif ($product->isDirty()) {
            $product->save();
            $counters['updated']++;
        } else {
            $counters['unchanged']++;
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{created: int, updated: int, restored: int, unchanged: int}  $counters
     */
    private function persistVariant(
        array $record,
        Product $product,
        array &$counters,
    ): ProductVariant {
        $variant = ProductVariant::query()->withTrashed()
            ->where('sku', $record['synthetic_sku'])->lockForUpdate()->first();
        $attributes = $record['variant'] + ['product_id' => $product->id];

        if ($variant === null) {
            $variant = ProductVariant::query()->create($attributes);
            $counters['created']++;

            return $variant;
        }

        $restored = $variant->trashed();

        if ($attributes['barcode'] === null && $variant->barcode !== null) {
            unset($attributes['barcode']);
        }

        if (! $restored && $this->matches($variant, $attributes)) {
            $counters['unchanged']++;

            return $variant;
        }

        $variant->fill($attributes);

        if ($restored) {
            $variant->is_active = true;
            $variant->restore();
            $counters['restored']++;
        } elseif ($variant->isDirty()) {
            $variant->save();
            $counters['updated']++;
        } else {
            $counters['unchanged']++;
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{created: int, updated: int, unchanged: int, stale_skipped: int}  $counters
     */
    private function persistImages(array $record, Product $product, array &$counters): void
    {
        $existing = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('image_url');
        $sourceUrls = [];

        foreach ($record['images'] as $attributes) {
            $sourceUrls[] = $attributes['image_url'];
            $image = $existing->get($attributes['image_url']);

            if ($image === null) {
                ProductImage::query()->create($attributes + [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                ]);
                $counters['created']++;

                continue;
            }

            $image->fill($attributes + ['product_variant_id' => null]);

            if ($image->isDirty()) {
                $image->save();
                $counters['updated']++;
            } else {
                $counters['unchanged']++;
            }
        }

        $counters['stale_skipped'] += $existing->keys()->diff($sourceUrls)->count();
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

            if (! $this->valuesMatch($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function valuesMatch(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual) || is_array($expected)) {
            return is_array($actual)
                && is_array($expected)
                && $this->normalizeArray($actual) === $this->normalizeArray($expected);
        }

        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }

    /**
     * Canonicalize associative keys recursively while preserving list order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArray($item);
            }
        }

        return $value;
    }

    /**
     * @return array{created: int, updated: int, restored: int, unchanged: int}
     */
    private function writeCounters(): array
    {
        return ['created' => 0, 'updated' => 0, 'restored' => 0, 'unchanged' => 0];
    }

    /**
     * @return array{create: int, update: int, unchanged: int}
     */
    private function emptyCounters(): array
    {
        return ['create' => 0, 'update' => 0, 'unchanged' => 0];
    }
}
