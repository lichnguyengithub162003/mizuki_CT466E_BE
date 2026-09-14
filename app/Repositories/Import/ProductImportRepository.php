<?php

namespace App\Repositories\Import;

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Import\ProductImageImportService;
use App\Support\Import\ProductVariantNormalizationRetirement;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductImportRepository
{
    public function __construct(
        private readonly ProductQuestionImportRepository $questions,
        private readonly ProductReviewImportRepository $reviews,
        private readonly ProductVariantNormalizationRetirement $normalizationRetirement,
    ) {}

    public function disableQueryLog(): void
    {
        DB::connection()->disableQueryLog();
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    /**
     * @return Collection<int, Product>
     */
    public function sourceProductsAfter(string $source, int $afterId, int $limit): Collection
    {
        return Product::query()
            ->withTrashed()
            ->where('source', $source)
            ->whereNotNull('external_id')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'source', 'external_id']);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    public function slugOwnerIds(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Product::query()
            ->withTrashed()
            ->whereIn('slug', array_values(array_unique($slugs)))
            ->pluck('id', 'slug')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, string>  $updates
     * @return array{updated: int, unchanged: int, conflicts: int}
     */
    public function updateImportedProductSlugs(string $source, array $updates): array
    {
        if ($updates === []) {
            return ['updated' => 0, 'unchanged' => 0, 'conflicts' => 0];
        }

        return $this->transaction(function () use ($source, $updates): array {
            $products = Product::query()
                ->withTrashed()
                ->where('source', $source)
                ->whereIn('id', array_keys($updates))
                ->lockForUpdate()
                ->get(['id', 'slug'])
                ->keyBy('id');
            $owners = $this->slugOwnerIds(array_values($updates));
            $result = ['updated' => 0, 'unchanged' => 0, 'conflicts' => 0];

            foreach ($updates as $productId => $slug) {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if ($product === null) {
                    $result['conflicts']++;

                    continue;
                }

                if ($product->slug === $slug) {
                    $result['unchanged']++;

                    continue;
                }

                if (isset($owners[$slug]) && $owners[$slug] !== $productId) {
                    $result['conflicts']++;

                    continue;
                }

                Product::withoutTimestamps(
                    fn (): int => Product::query()->whereKey($productId)->update(['slug' => $slug]),
                );
                $result['updated']++;
            }

            return $result;
        });
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
            'questions' => $this->synchronizationCounters(),
            'question_answers' => $this->synchronizationCounters(),
            'reviews' => $this->reviewSynchronizationCounters(),
        ];
        $brands = $this->persistBrands($records, $counters['brands']);
        $categories = $this->persistCategories($records, $counters['categories']);
        $samples = [];

        foreach ($records as $record) {
            $brand = $brands[(string) $record['brand']['slug']];
            $category = $categories[(string) $record['category_slug']];
            $productResolution = $this->persistProduct(
                $record,
                $brand,
                $category,
                $counters['products'],
            );
            $product = $productResolution['product'];
            $variant = $this->persistVariant($record, $product, $counters['variants']);
            $this->persistImages(
                $record,
                $product,
                $variant,
                $productResolution['normalization_redirected'],
                $counters['images'],
            );
            $this->questions->synchronize(
                $product,
                $record['questions'],
                $counters['questions'],
                $counters['question_answers'],
            );
            $this->reviews->synchronize(
                $product,
                $record['reviews'],
                $record['review_import_stats'],
                $counters['reviews'],
                $productResolution['normalization_redirected'],
            );

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
        $existingVariants = $this->existingVariantsFor($records);

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
     * @return array{product: Product, normalization_redirected: bool}
     */
    private function persistProduct(
        array $record,
        Brand $brand,
        Category $category,
        array &$counters,
    ): array {
        $product = Product::query()->withTrashed()
            ->where(function ($query) use ($record): void {
                $query->where(function ($identity) use ($record): void {
                    $identity->where('source', $record['product']['source'])
                        ->where('external_id', $record['product']['external_id']);
                })->orWhere('slug', $record['product_slug']);
            })
            ->lockForUpdate()
            ->first();
        $attributes = $record['product'] + [
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ];

        if ($product === null) {
            $product = Product::query()->create($attributes);
            $counters['created']++;

            return ['product' => $product, 'normalization_redirected' => false];
        }

        $normalizationMarker = $this->normalizationRetirement->marker($product);
        if ($product->trashed() && $normalizationMarker !== null) {
            $canonical = Product::query()->withTrashed()
                ->whereKey($normalizationMarker['canonical_product_id'])
                ->lockForUpdate()
                ->first();
            if ($canonical === null || $canonical->trashed()) {
                throw new RuntimeException('Normalized product redirect points to a missing or retired canonical product.');
            }

            $counters['unchanged']++;

            return ['product' => $canonical, 'normalization_redirected' => true];
        }

        $restored = $product->trashed();
        unset($attributes['is_featured']);

        if (! $restored && $this->matches($product, $attributes)) {
            $counters['unchanged']++;

            return ['product' => $product, 'normalization_redirected' => false];
        }

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

        return ['product' => $product, 'normalization_redirected' => false];
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
        $matches = ProductVariant::query()
            ->withTrashed()
            ->where(function ($query) use ($record): void {
                $query->where(function ($identity) use ($record): void {
                    $identity->where('source', $record['variant']['source'])
                        ->where('external_id', $record['variant']['external_id']);
                })->orWhere('sku', $record['synthetic_sku']);
            })
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException(
                'Variant source identity and SKU resolve to different database records.',
            );
        }

        $variant = $matches->first();
        $attributes = $record['variant'] + [
            'product_id' => $variant?->product_id ?? $product->id,
        ];

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
    private function persistImages(
        array $record,
        Product $product,
        ProductVariant $variant,
        bool $normalizationRedirected,
        array &$counters,
    ): void {
        $variantScoped = $normalizationRedirected || ProductImage::query()
            ->where('product_variant_id', $variant->id)
            ->exists();
        $imageProductId = $variantScoped ? (int) $variant->product_id : (int) $product->id;
        $imageVariantId = $variantScoped ? (int) $variant->id : null;
        $existing = ProductImage::query()
            ->where('product_id', $imageProductId)
            ->where('product_variant_id', $imageVariantId)
            ->lockForUpdate()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $existingByUrl = $existing->keyBy('image_url');
        $incomingImages = collect($record['images'])->values();
        $incomingRealImages = $incomingImages
            ->reject(fn (array $image): bool => $image['image_url'] === ProductImageImportService::FALLBACK_URL)
            ->values();
        $existingRealImages = $existing
            ->reject(fn (ProductImage $image): bool => $image->image_url === ProductImageImportService::FALLBACK_URL)
            ->values();

        if ($incomingRealImages->isNotEmpty()) {
            $desiredImages = $incomingRealImages->map(
                static fn (array $image, int $index): array => array_merge($image, ['is_primary' => $index === 0]),
            );
            $preferredPrimaryUrl = (string) $desiredImages->first()['image_url'];
        } elseif ($existingRealImages->isNotEmpty()) {
            // A skipped or failed image copy must not restore a placeholder over existing real media.
            $desiredImages = collect();
            $preferredPrimaryUrl = (string) ($existingRealImages->firstWhere('is_primary', true)
                ?? $existingRealImages->first())->image_url;
        } else {
            $desiredImages = $incomingImages
                ->where('image_url', ProductImageImportService::FALLBACK_URL)
                ->take(1)
                ->values();
            $preferredPrimaryUrl = ProductImageImportService::FALLBACK_URL;
        }
        $sourceUrls = [];

        foreach ($desiredImages as $attributes) {
            $sourceUrls[] = $attributes['image_url'];
            $image = $existingByUrl->get($attributes['image_url']);

            if ($image === null) {
                ProductImage::query()->create($attributes + [
                    'product_id' => $imageProductId,
                    'product_variant_id' => $imageVariantId,
                ]);
                $counters['created']++;

                continue;
            }

            $image->fill($attributes + [
                'product_id' => $imageProductId,
                'product_variant_id' => $imageVariantId,
            ]);

            if ($image->isDirty()) {
                $image->save();
                $counters['updated']++;
            } else {
                $counters['unchanged']++;
            }
        }

        foreach ($existingByUrl->keys()->diff($sourceUrls) as $staleUrl) {
            $isSourceHosted = in_array(
                $staleUrl,
                $record['metadata']['source_image_urls'] ?? [],
                true,
            );

            if ($product->source === 'hasaki' && $isSourceHosted) {
                $existingByUrl->get($staleUrl)?->delete();
                $counters['updated']++;

                continue;
            }

            $counters['stale_skipped']++;
        }

        $this->normalizeProductImages(
            productId: $imageProductId,
            productName: (string) $product->name,
            preferredPrimaryUrl: $preferredPrimaryUrl,
            counters: $counters,
            variantId: $imageVariantId,
        );
    }

    /**
     * Keep one row per URL and one primary image while the import transaction holds row locks.
     *
     * @param  array{created: int, updated: int, unchanged: int, stale_skipped: int}  $counters
     */
    private function normalizeProductImages(
        int $productId,
        string $productName,
        string $preferredPrimaryUrl,
        array &$counters,
        ?int $variantId = null,
    ): void {
        $images = ProductImage::query()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $seenUrls = [];

        foreach ($images as $image) {
            if (isset($seenUrls[$image->image_url])) {
                $image->delete();
                $counters['updated']++;

                continue;
            }

            $seenUrls[$image->image_url] = true;
        }

        $images = $images->filter(fn (ProductImage $image): bool => $image->exists)->values();
        $realImages = $images
            ->reject(fn (ProductImage $image): bool => $image->image_url === ProductImageImportService::FALLBACK_URL)
            ->values();

        if ($realImages->isNotEmpty()) {
            foreach ($images->where('image_url', ProductImageImportService::FALLBACK_URL) as $placeholder) {
                $placeholder->delete();
                $counters['updated']++;
            }

            $images = $realImages;
        } elseif ($images->isEmpty()) {
            $images = collect([ProductImage::query()->create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'image_url' => ProductImageImportService::FALLBACK_URL,
                'alt_text' => $productName,
                'sort_order' => 0,
                'is_primary' => true,
            ])]);
            $counters['created']++;
        }

        $primary = $images->firstWhere('image_url', $preferredPrimaryUrl) ?? $images->first();

        foreach ($images as $image) {
            $shouldBePrimary = $image->is($primary);

            if ($image->is_primary !== $shouldBePrimary) {
                $image->update(['is_primary' => $shouldBePrimary]);
                $counters['updated']++;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function onlyMissingProducts(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $existing = Product::query()
            ->withTrashed()
            ->where(function ($query) use ($records): void {
                $query->where(function ($identity) use ($records): void {
                    $identity->where('source', 'hasaki')
                        ->whereIn('external_id', array_column($records, 'source_id'));
                })->orWhereIn('slug', array_column($records, 'product_slug'));
            })
            ->get(['source', 'external_id', 'slug']);
        $identities = [];
        $slugs = [];

        foreach ($existing as $product) {
            if ($product->source === 'hasaki' && $product->external_id !== null) {
                $identities[$product->external_id] = true;
            }

            $slugs[$product->slug] = true;
        }

        return array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($identities[$record['source_id']])
                && ! isset($slugs[$record['product_slug']]),
        ));
    }

    /**
     * Create synthetic development inventory without overwriting curated stock.
     *
     * @param  list<string>  $skus
     * @return array{created: int, unchanged: int, quantity_per_row: int}
     */
    public function seedDevelopmentInventory(array $skus, int $quantity = 20): array
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->whereIn('branch_type', [BranchType::Store->value, BranchType::Hybrid->value])
            ->pluck('id');
        $variants = ProductVariant::query()
            ->whereIn('sku', array_values(array_unique($skus)))
            ->where('is_active', true)
            ->pluck('id');
        $created = 0;
        $unchanged = 0;

        foreach ($branches as $branchId) {
            foreach ($variants as $variantId) {
                $inventory = BranchInventory::query()->firstOrCreate(
                    ['branch_id' => $branchId, 'product_variant_id' => $variantId],
                    ['quantity' => max(0, $quantity), 'reserved_quantity' => 0, 'reorder_level' => 5],
                );

                $inventory->wasRecentlyCreated ? $created++ : $unchanged++;
            }
        }

        return [
            'created' => $created,
            'unchanged' => $unchanged,
            'quantity_per_row' => max(0, $quantity),
        ];
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
            $existingVariant = $existingVariants->get($record['synthetic_sku']);

            if ($existingVariant !== null) {
                $attributes['product_id'] = $existingVariant->product_id;
            } elseif ($product !== null) {
                $attributes['product_id'] = $product->id;
            }

            $desired[$record['synthetic_sku']] = $attributes;
        }

        return $this->planModels($desired, $existingVariants);
    }

    /**
     * Resolve imported variants by authoritative source identity with SKU as a transition fallback.
     *
     * @param  list<array<string, mixed>>  $records
     * @return Collection<string, ProductVariant>
     */
    private function existingVariantsFor(array $records): Collection
    {
        if ($records === []) {
            return collect();
        }

        $identitiesBySource = [];
        $skus = [];

        foreach ($records as $record) {
            $identitiesBySource[$record['variant']['source']][] = $record['variant']['external_id'];
            $skus[] = $record['synthetic_sku'];
        }

        $matches = ProductVariant::query()
            ->withTrashed()
            ->where(function ($query) use ($identitiesBySource, $skus): void {
                $query->whereIn('sku', array_values(array_unique($skus)));

                foreach ($identitiesBySource as $source => $externalIds) {
                    $query->orWhere(function ($identity) use ($source, $externalIds): void {
                        $identity->where('source', $source)
                            ->whereIn('external_id', array_values(array_unique($externalIds)));
                    });
                }
            })
            ->get();
        $resolved = collect();

        foreach ($records as $record) {
            $identityMatch = $matches->first(
                fn (ProductVariant $variant): bool => $variant->source === $record['variant']['source']
                    && $variant->external_id === $record['variant']['external_id'],
            );
            $skuMatch = $matches->firstWhere('sku', $record['synthetic_sku']);

            if ($identityMatch !== null && $skuMatch !== null && ! $identityMatch->is($skuMatch)) {
                throw new RuntimeException(
                    'Variant source identity and SKU resolve to different database records.',
                );
            }

            $match = $identityMatch ?? $skuMatch;

            if ($match !== null) {
                $resolved->put($record['synthetic_sku'], $match);
            }
        }

        return $resolved;
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
     * @return array{created: int, updated: int, unchanged: int, deleted: int}
     */
    private function synchronizationCounters(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0];
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, deleted: int, skipped: int, duplicate_collapsed: int, failed: int}
     */
    private function reviewSynchronizationCounters(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'duplicate_collapsed' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @return array{create: int, update: int, unchanged: int}
     */
    private function emptyCounters(): array
    {
        return ['create' => 0, 'update' => 0, 'unchanged' => 0];
    }
}
