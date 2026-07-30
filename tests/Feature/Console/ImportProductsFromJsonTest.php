<?php

use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Import\ProductJsonImportService;
use App\Support\Import\ProductJsonMapper;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productJsonImportRecord(array $overrides = []): array
{
    return array_replace([
        'productId' => '1001',
        'name' => 'Imported Product 1001',
        'brand' => 'Import Brand',
        'url' => 'https://example.test/products/1001',
        'image' => 'https://example.test/images/card-1001.jpg',
        'price' => 150_000,
        'originalPrice' => 200_000,
        'subName' => 'Short description',
        'categoryPaths' => [
            ['Skin Care', 'Serum'],
        ],
        'breadcrumbPath' => [
            'Beauty',
            'Skin Care',
            'Serum',
            'Imported Product 1001',
        ],
        'variants' => [
            [
                'label' => 'Capacity:',
                'selected' => '50ml',
                'options' => ['30ml', '50ml'],
            ],
        ],
        'specifications' => [
            'Barcode' => '8931234567890',
            'Xuất xứ thương hiệu' => 'Việt Nam',
        ],
        'description' => '<p>Product description</p>',
        'ingredients' => '<p>Ingredients</p>',
        'usageInstructions' => '<p>Instructions</p>',
        'images' => [
            'https://example.test/images/1001-1.jpg',
            'https://example.test/images/1001-2.jpg',
        ],
        'localImages' => [],
    ], $overrides);
}

test('product import command requires dry-run mode', function (): void {
    $this->artisan('import:products')
        ->expectsOutput('The --dry-run option is required. Product write mode is not implemented.')
        ->assertExitCode(Command::INVALID);
});

test('missing and invalid product sources fail clearly', function (): void {
    $service = app(ProductJsonImportService::class);

    expect(fn () => $service->analyzeFile(base_path('missing-products.json')))
        ->toThrow(RuntimeException::class, 'Product source file is missing or unreadable.')
        ->and(fn () => $service->analyzeJson('{"broken":'))
        ->toThrow(UnexpectedValueException::class, 'Product source JSON is invalid:')
        ->and(fn () => $service->analyzeJson('{"products":[]}'))
        ->toThrow(UnexpectedValueException::class, 'Product source JSON root must be an array.');
});

test('dry-run performs zero database and storage writes', function (): void {
    Storage::fake('local');
    $before = [
        Brand::query()->withTrashed()->count(),
        Category::query()->withTrashed()->count(),
        Product::query()->withTrashed()->count(),
        ProductVariant::query()->withTrashed()->count(),
        ProductImage::query()->count(),
        BranchInventory::query()->count(),
    ];

    $result = app(ProductJsonImportService::class)->analyzeJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
    );

    expect($result->get('valid'))->toBe(1)
        ->and([
            Brand::query()->withTrashed()->count(),
            Category::query()->withTrashed()->count(),
            Product::query()->withTrashed()->count(),
            ProductVariant::query()->withTrashed()->count(),
            ProductImage::query()->count(),
            BranchInventory::query()->count(),
        ])->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('offset and limit are applied only after the full source is parsed', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Imported Product 1002',
        ]),
        productJsonImportRecord([
            'productId' => '1003',
            'name' => 'Imported Product 1003',
        ]),
    ];

    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode($records, JSON_THROW_ON_ERROR), 1, 1)
        ->toArray();

    expect($result['source_total'])->toBe(3)
        ->and($result['selected'])->toBe(1)
        ->and($result['planned_records'][0]['source_id'])->toBe('1002');
});

test('duplicates are detected globally even outside the selected window', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord(['name' => 'Renamed duplicate']),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Imported Product 1002',
        ]),
    ];

    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode($records, JSON_THROW_ON_ERROR), 2, 1)
        ->toArray();

    expect($result['selected'])->toBe(1)
        ->and($result['valid'])->toBe(1)
        ->and($result['duplicate_source_ids'])->toBe(1)
        ->and($result['duplicate_product_slugs'])->toBe(1)
        ->and($result['duplicate_skus'])->toBe(1);
});

test('every valid source record has stable identities and one synthetic variant', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Imported Product 1002',
        ]),
    ];

    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode($records, JSON_THROW_ON_ERROR))
        ->toArray();

    expect($result['planned_records'])->toHaveCount(2);

    foreach ($result['planned_records'] as $record) {
        expect($record)->toHaveKey('variant')
            ->and($record)->not->toHaveKey('variants')
            ->and($record['product_slug'])->toBe('hasaki-product-'.$record['source_id'])
            ->and($record['synthetic_sku'])->toBe('HS-'.$record['source_id']);
    }
});

test('invalid and duplicate optional barcodes are dropped without quarantine', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Imported Product 1002',
        ]),
        productJsonImportRecord([
            'productId' => '1003',
            'name' => 'Imported Product 1003',
            'specifications' => ['Barcode' => 'invalid barcode'],
        ]),
    ];

    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode($records, JSON_THROW_ON_ERROR))
        ->toArray();

    expect($result['valid'])->toBe(3)
        ->and($result['quarantined'])->toBe(0)
        ->and($result['duplicate_barcodes'])->toBe(1)
        ->and(array_column($result['planned_records'], 'variant'))->each(
            fn ($variant) => $variant->barcode->toBeNull(),
        );
});

test('existing catalog entities are planned read-only', function (): void {
    $record = productJsonImportRecord();
    $mapped = app(ProductJsonMapper::class)->map($record);
    $brand = Brand::query()->create($mapped['brand']);
    $parentId = null;

    foreach ($mapped['categories'] as $attributes) {
        unset($attributes['parent_slug']);
        $category = Category::query()->create($attributes + ['parent_id' => $parentId]);
        $parentId = $category->id;
    }

    $product = Product::query()->create($mapped['product'] + [
        'brand_id' => $brand->id,
        'category_id' => $parentId,
    ]);
    $variantAttributes = $mapped['variant'];
    $variantAttributes['weight'] = 100;
    $variant = ProductVariant::query()->create($variantAttributes + [
        'product_id' => $product->id,
    ]);

    foreach ($mapped['images'] as $image) {
        ProductImage::query()->create($image + [
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);
    }

    $timestamps = [
        $brand->updated_at?->toISOString(),
        $product->updated_at?->toISOString(),
        $variant->updated_at?->toISOString(),
    ];
    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode([$record], JSON_THROW_ON_ERROR))
        ->toArray();

    expect($result['plans']['brands']['unchanged'])->toBe(1)
        ->and($result['plans']['categories']['unchanged'])->toBe(
            count($mapped['categories']),
        )
        ->and($result['plans']['products']['unchanged'])->toBe(1)
        ->and($result['plans']['variants']['unchanged'])->toBe(1)
        ->and($result['plans']['images']['unchanged'])->toBe(count($mapped['images']))
        ->and([
            $brand->refresh()->updated_at?->toISOString(),
            $product->refresh()->updated_at?->toISOString(),
            $variant->refresh()->updated_at?->toISOString(),
        ])->toBe($timestamps);
});

test('malformed required records are quarantined', function (): void {
    $records = [
        productJsonImportRecord(['name' => '']),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Invalid Price',
            'price' => 0,
        ]),
    ];

    $result = app(ProductJsonImportService::class)
        ->analyzeJson(json_encode($records, JSON_THROW_ON_ERROR))
        ->toArray();

    expect($result['valid'])->toBe(0)
        ->and($result['quarantined'])->toBe(2)
        ->and(array_column($result['quarantine_examples'], 'reason'))->toBe([
            'missing_name',
            'invalid_price',
        ]);
});

test('dry-run never plans or creates branch inventories', function (): void {
    $result = app(ProductJsonImportService::class)->analyzeJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
    );

    expect($result->get('plans'))->not->toHaveKey('inventories')
        ->and(BranchInventory::query()->count())->toBe(0);
});

test('real product source dry-run with limit 50 succeeds', function (): void {
    $this->artisan('import:products', [
        '--dry-run' => true,
        '--offset' => 0,
        '--limit' => 50,
    ])
        ->expectsOutput('Source total: 2391')
        ->expectsOutput('Selected records: 50')
        ->expectsOutput('Missing weight policy: 50')
        ->expectsOutput('Dry-run complete: no database or storage writes were performed.')
        ->assertSuccessful();
});
