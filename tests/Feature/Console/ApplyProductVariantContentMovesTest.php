<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Services\Import\ProductJsonImportService;
use App\Services\Import\ProductVariantContentMoveApplyService;
use App\Support\Import\ProductReviewJsonMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function t110Fixture(int $members = 2, string $axis = 'volume'): array
{
    $token = Str::lower(Str::random(8));
    $brand = Brand::query()->create([
        'name' => "T110 Brand {$token}", 'slug' => "t110-brand-{$token}", 'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => "T110 Category {$token}", 'slug' => "t110-category-{$token}", 'is_active' => true,
    ]);
    $products = [];
    $variants = [];
    $images = [];
    $values = $axis === 'color' ? ['Đỏ', 'Hồng', 'Nâu'] : ['30ml', '50ml', '100ml'];
    $specificationKey = $axis === 'color' ? 'Màu sắc' : 'Dung tích';
    $attributeKey = $axis === 'color' ? 'color' : 'dung_tich';
    $canonicalName = $axis === 'color' ? 'Son dưỡng Mizuki' : 'Serum Mizuki';

    for ($index = 0; $index < $members; $index++) {
        $externalId = 'T110-'.$token.'-'.$index;
        $value = $values[$index];
        $product = Product::query()->create([
            'source' => 'hasaki', 'external_id' => $externalId,
            'source_url' => "https://example.test/{$externalId}", 'source_variant_groups' => [],
            'brand_id' => $brand->id, 'category_id' => $category->id,
            'name' => "{$canonicalName} {$value}", 'slug' => "t110-{$token}-{$index}",
            'specifications' => [$specificationKey => $value, 'Xuất xứ' => 'Việt Nam'],
            'is_active' => true, 'is_featured' => false,
        ]);
        $attributes = $axis === 'volume'
            ? ['dung_tich' => $value, 'spec_dung_tich' => str_replace('ml', ' ml', $value)]
            : [$attributeKey => $value];
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'source' => 'hasaki', 'external_id' => $externalId,
            'source_url' => $product->source_url, 'name' => $value,
            'sku' => 'T110-'.Str::upper($token).'-'.$index, 'attributes' => $attributes,
            'price' => 100000 + $index, 'weight' => 100, 'sort_order' => $index, 'is_active' => true,
        ]);
        foreach ([0, 1] as $sortOrder) {
            $image = ProductImage::query()->create([
                'product_id' => $product->id, 'product_variant_id' => null,
                'image_url' => "/storage/t110/{$externalId}-{$sortOrder}.jpg",
                'alt_text' => "Image {$externalId} {$sortOrder}",
                'sort_order' => $sortOrder, 'is_primary' => $sortOrder === 0,
            ]);
            $images[] = $image;
        }
        $products[] = $product;
        $variants[] = $variant;
    }

    $productIds = array_map(static fn (Product $product): int => $product->id, $products);
    $variantIds = array_map(static fn (ProductVariant $variant): int => $variant->id, $variants);
    $identifier = 'pvg-'.substr(hash('sha256', implode('|', array_map(
        static fn (Product $product): string => $product->source.':'.$product->external_id,
        $products,
    ))), 0, 16);
    $plan = [
        'group_identifier' => $identifier,
        'candidate_product_ids' => $productIds,
        'candidate_variant_ids' => $variantIds,
        'source_external_ids' => array_map(static fn (Product $product): array => [
            'product_id' => $product->id, 'source' => $product->source, 'external_id' => $product->external_id,
        ], $products),
        'proposed_canonical_product_id' => $products[0]->id,
        'existing_product_names' => collect($products)->mapWithKeys(
            static fn (Product $product): array => [$product->id => $product->name],
        )->all(),
        'proposed_canonical_product_name' => $canonicalName,
        'shared_product_fields' => [
            'brand_id' => ['values_by_product' => array_fill_keys($productIds, $brand->id)],
            'category_id' => ['values_by_product' => array_fill_keys($productIds, $category->id)],
        ],
        'product_specification_keys_remaining_shared' => [
            'Xuất xứ' => ['value' => 'Việt Nam', 'classification' => 'shared_family_level'],
        ],
        'product_specification_keys_moving_to_variant' => [
            $specificationKey => [
                'axis' => $axis,
                'values_by_product' => collect($products)->mapWithKeys(
                    static fn (Product $product, int $index): array => [$product->id => $values[$index]],
                )->all(),
                'classification' => 'variant_specific',
            ],
        ],
        'variant_attribute_changes' => array_map(static fn (ProductVariant $variant, int $index): array => [
            'source_product_id' => $products[$index]->id,
            'variant_id' => $variant->id,
            'attributes_before' => $variant->attributes,
            'attributes_after' => $variant->attributes,
            'write_required' => false,
        ], $variants, array_keys($variants)),
        'image_attribution_plan' => array_map(static function (ProductImage $image) use ($products, $variants): array {
            $index = array_search($image->product_id, array_column($products, 'id'), true);

            return [
                'image_id' => $image->id,
                'current_product_id' => $image->product_id,
                'current_product_variant_id' => null,
                'intended_canonical_product_id' => $products[0]->id,
                'intended_product_variant_id' => $variants[$index]->id,
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
                'attribution_confidence' => 'high',
                'write_required' => true,
            ];
        }, $images),
        't1_3_image_attribution_compatible' => true,
        'unresolved_ambiguities' => [], 'stale_state_failures' => [],
        'readiness' => 'ready_for_variant_content_apply', 'reasons' => [],
        'metrics' => [
            'variants_affected' => $members,
            'variant_attribute_writes_proposed' => 0,
            'image_rows_requiring_attribution' => count($images),
        ],
    ];
    $path = tempnam(sys_get_temp_dir(), 't110-plan-');
    file_put_contents($path, json_encode(['summary' => [], 'groups' => [$plan]], JSON_THROW_ON_ERROR));

    return compact('identifier', 'path', 'plan', 'products', 'variants', 'images', 'brand', 'category', 'canonicalName');
}

/** @return array<string, mixed> */
function t110Execute(array $fixture, bool $apply = true): array
{
    return app(ProductVariantContentMoveApplyService::class)->execute(
        $fixture['path'], [$fixture['identifier']], $apply,
    );
}

test('default command dry-run validates a two-product volume plan without mutation', function (): void {
    $fixture = t110Fixture();
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'images' => DB::table('product_images')->orderBy('id')->get()->toJson(),
    ];

    $this->artisan('products:apply-variant-content-moves', ['--plan' => $fixture['path']])
        ->expectsOutput('Product–Variant content apply dry-run')
        ->expectsOutput('Groups validated: 1')
        ->assertSuccessful();

    expect([
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'images' => DB::table('product_images')->orderBy('id')->get()->toJson(),
    ])->toBe($before);
});

test('two-product volume apply updates canonical content and preserves raw Variant and image data', function (): void {
    $fixture = t110Fixture();
    [$canonical, $duplicate] = $fixture['products'];
    $attributeHashes = collect($fixture['variants'])->mapWithKeys(
        static fn (ProductVariant $variant): array => [$variant->id => hash('sha256', json_encode($variant->attributes, JSON_THROW_ON_ERROR))],
    )->all();
    $imageData = collect($fixture['images'])->mapWithKeys(static fn (ProductImage $image): array => [$image->id => [
        'image_url' => $image->image_url, 'alt_text' => $image->alt_text,
        'sort_order' => $image->sort_order, 'is_primary' => $image->is_primary,
    ]])->all();

    $result = t110Execute($fixture);

    expect($result['groups_normalized'])->toBe(1)
        ->and($canonical->fresh()->name)->toBe($fixture['canonicalName'])
        ->and($canonical->fresh()->specifications)->toBe(['Xuất xứ' => 'Việt Nam'])
        ->and($duplicate->fresh()->trashed())->toBeTrue()
        ->and($duplicate->fresh()->is_active)->toBeFalse();
    foreach ($fixture['variants'] as $variant) {
        $fresh = $variant->fresh();
        expect($fresh->product_id)->toBe($canonical->id)
            ->and(hash('sha256', json_encode($fresh->attributes, JSON_THROW_ON_ERROR)))->toBe($attributeHashes[$variant->id]);
        $variantImages = ProductImage::query()->where('product_variant_id', $variant->id)->get();
        expect($variantImages)->toHaveCount(2)
            ->and($variantImages->where('is_primary', true))->toHaveCount(1)
            ->and($variantImages->pluck('product_id')->unique()->all())->toBe([$canonical->id]);
    }
    foreach ($fixture['images'] as $image) {
        $fresh = $image->fresh();
        expect([
            'image_url' => $fresh->image_url, 'alt_text' => $fresh->alt_text,
            'sort_order' => $fresh->sort_order, 'is_primary' => $fresh->is_primary,
        ])->toBe($imageData[$image->id]);
    }
});

test('three-product color apply reparents all Variants without collapsing galleries', function (): void {
    $fixture = t110Fixture(3, 'color');
    $result = t110Execute($fixture);

    expect($result['variants_reparented'])->toBe(2)
        ->and($result['products_retired'])->toBe(2)
        ->and(ProductVariant::query()->whereIn('id', collect($fixture['variants'])->pluck('id'))
            ->pluck('product_id')->unique()->all())->toBe([$fixture['products'][0]->id]);
    foreach ($fixture['variants'] as $variant) {
        expect(ProductImage::query()->where('product_variant_id', $variant->id)->count())->toBe(2)
            ->and(ProductImage::query()->where('product_variant_id', $variant->id)->where('is_primary', true)->count())->toBe(1);
    }
});

test('engagement remaining on a noncanonical Product blocks the group', function (): void {
    $fixture = t110Fixture();
    ProductFavorite::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $fixture['products'][1]->id,
    ]);

    expect(fn () => t110Execute($fixture))
        ->toThrow(RuntimeException::class, 'Active product_favorites remains on a noncanonical Product');
    expect($fixture['products'][1]->fresh()->trashed())->toBeFalse()
        ->and($fixture['variants'][1]->fresh()->product_id)->toBe($fixture['products'][1]->id);
});

test('stale Product name blocks the whole group before mutation', function (): void {
    $fixture = t110Fixture();
    $fixture['products'][1]->update(['name' => 'Changed after planning']);

    expect(fn () => t110Execute($fixture))->toThrow(RuntimeException::class, 'Current Product names are stale');
    expect($fixture['products'][0]->fresh()->name)->not->toBe($fixture['canonicalName'])
        ->and($fixture['products'][1]->fresh()->trashed())->toBeFalse();
});

test('unknown non-ready and implicit apply requests are rejected', function (): void {
    $fixture = t110Fixture();

    expect(fn () => app(ProductVariantContentMoveApplyService::class)->execute($fixture['path'], ['unknown'], true))
        ->toThrow(InvalidArgumentException::class, 'Unknown or unapproved')
        ->and(fn () => app(ProductVariantContentMoveApplyService::class)->execute($fixture['path'], [], true))
        ->toThrow(InvalidArgumentException::class, '--apply requires at least one explicit --group');

    $fixture['plan']['readiness'] = 'needs_manual_rule';
    file_put_contents($fixture['path'], json_encode(['groups' => [$fixture['plan']]], JSON_THROW_ON_ERROR));
    expect(fn () => t110Execute($fixture))->toThrow(InvalidArgumentException::class, 'is not approved for apply');
});

test('multiple planned primary images for one Variant block apply', function (): void {
    $fixture = t110Fixture();
    $fixture['plan']['image_attribution_plan'][1]['is_primary'] = true;
    $fixture['images'][1]->update(['is_primary' => true]);
    file_put_contents($fixture['path'], json_encode(['groups' => [$fixture['plan']]], JSON_THROW_ON_ERROR));

    expect(fn () => t110Execute($fixture))->toThrow(RuntimeException::class, 'Primary ProductImage invariant blocks');
    expect($fixture['products'][1]->fresh()->trashed())->toBeFalse();
});

test('failure during Variant reparent rolls back canonical content and images', function (): void {
    $fixture = t110Fixture();
    $throw = true;
    DB::listen(function ($query) use (&$throw): void {
        if ($throw && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'product_variants')) {
            $throw = false;
            throw new RuntimeException('Injected T1.10 failure');
        }
    });

    expect(fn () => t110Execute($fixture))->toThrow(RuntimeException::class, 'Injected T1.10 failure');
    expect($fixture['products'][0]->fresh()->name)->not->toBe($fixture['canonicalName'])
        ->and($fixture['products'][0]->fresh()->specifications)->toHaveKey('Dung tích')
        ->and($fixture['products'][1]->fresh()->trashed())->toBeFalse()
        ->and(ProductImage::query()->whereNotNull('product_variant_id')->count())->toBe(0);
});

test('rerunning an applied T1.10 plan is idempotent', function (): void {
    $fixture = t110Fixture();
    t110Execute($fixture);
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'images' => DB::table('product_images')->orderBy('id')->get()->toJson(),
    ];

    $result = t110Execute($fixture);

    expect($result['already_normalized'])->toBe(1)
        ->and($result['images_attributed'])->toBe(0)
        ->and($result['variants_reparented'])->toBe(0)
        ->and([
            'products' => DB::table('products')->orderBy('id')->get()->toJson(),
            'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
            'images' => DB::table('product_images')->orderBy('id')->get()->toJson(),
        ])->toBe($before);
});

test('importer rerun keeps retired Product and normalized Variant ownership intact', function (): void {
    $fixture = t110Fixture();
    t110Execute($fixture);
    $records = [];
    foreach ($fixture['products'] as $index => $product) {
        $variant = $fixture['variants'][$index];
        $sourceImages = collect($fixture['images'])
            ->where('product_id', $product->id)->pluck('image_url')->values()->all();
        $records[] = [
            'productId' => $product->external_id,
            'name' => $product->name,
            'brand' => $fixture['brand']->name,
            'url' => $product->source_url,
            'image' => $sourceImages[0],
            'price' => $variant->price,
            'categoryPaths' => [[$fixture['category']->name]],
            'variants' => [[
                'label' => 'Dung tích:', 'selected' => $variant->name, 'options' => [$variant->name],
            ]],
            'specifications' => [],
            'images' => $sourceImages,
            'localImages' => [],
        ];
    }

    app(ProductJsonImportService::class)->importJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        offset: 0,
        limit: count($records),
        defaultWeight: 100,
    );

    foreach ($fixture['variants'] as $variant) {
        expect($variant->fresh()->product_id)->toBe($fixture['products'][0]->id);
    }
    expect($fixture['products'][1]->fresh()->trashed())->toBeTrue()
        ->and(Product::query()->withTrashed()->where('external_id', $fixture['products'][1]->external_id)->count())->toBe(1);
});

test('importer rerun preserves a reconciled review tombstone from a retired Product', function (): void {
    $fixture = t110Fixture();
    t110Execute($fixture);
    $retiredProduct = $fixture['products'][1];
    $variant = $fixture['variants'][1];
    $sourceReview = [
        'author' => 'Imported customer',
        'ratingScore' => 5,
        'date' => '2026-09-10, 10:00',
        'variantPurchased' => $variant->name,
        'comment' => 'Duplicate review already reconciled.',
    ];
    $mappedReview = app(ProductReviewJsonMapper::class)
        ->map((string) $retiredProduct->external_id, [$sourceReview])['records'][0];
    $createdAt = $mappedReview['created_at'];
    unset($mappedReview['created_at']);
    $tombstone = new Review;
    $tombstone->fill($mappedReview + [
        'product_id' => $retiredProduct->id,
        'product_variant_id' => $variant->id,
    ]);
    $tombstone->created_at = $createdAt;
    $tombstone->save();
    $tombstone->delete();
    $canonicalReview = Review::query()->create([
        'source' => 'hasaki',
        'source_key' => hash('sha256', 'canonical-source-review'),
        'product_id' => $fixture['products'][0]->id,
        'product_variant_id' => $fixture['variants'][0]->id,
        'rating' => 4,
        'comment' => 'Canonical source review must not become stale.',
        'is_visible' => true,
    ]);
    $record = [
        'productId' => $retiredProduct->external_id,
        'name' => $retiredProduct->name,
        'brand' => $fixture['brand']->name,
        'url' => $retiredProduct->source_url,
        'image' => null,
        'price' => $variant->price,
        'categoryPaths' => [[$fixture['category']->name]],
        'variants' => [[
            'label' => 'Volume:', 'selected' => $variant->name, 'options' => [$variant->name],
        ]],
        'specifications' => [],
        'images' => [],
        'localImages' => [],
        'reviews' => [$sourceReview],
    ];

    app(ProductJsonImportService::class)->importJson(
        json_encode([$record], JSON_THROW_ON_ERROR),
        offset: 0,
        limit: 1,
        defaultWeight: 100,
    );

    expect(Review::query()->withTrashed()->where('source_key', $mappedReview['source_key'])->count())->toBe(1)
        ->and($tombstone->fresh()->trashed())->toBeTrue()
        ->and($tombstone->fresh()->product_id)->toBe($retiredProduct->id)
        ->and($canonicalReview->fresh()->trashed())->toBeFalse()
        ->and($variant->fresh()->product_id)->toBe($fixture['products'][0]->id)
        ->and($retiredProduct->fresh()->trashed())->toBeTrue();
});
