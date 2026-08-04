<?php

use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Repositories\Import\ProductImportRepository;
use App\Services\Import\ProductJsonImportService;
use App\Support\Import\ProductJsonMapper;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

test('write mode requires an explicit limit', function (): void {
    $this->artisan('import:products')
        ->expectsOutput('Write mode requires --limit between 1 and 50.')
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
        ->and($result['duplicate_product_slugs'])->toBe(0)
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
            ->and($record['product_slug'])->toEndWith('-'.$record['source_id'])
            ->and($record['product_slug'])->not->toStartWith('hasaki-product-')
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
test('write mode rejects a limit above fifty', function (): void {
    $this->artisan('import:products', [
        '--force' => true,
        '--limit' => 51,
        '--default-weight' => 500,
    ])
        ->expectsOutput('Write mode requires --limit between 1 and 50.')
        ->assertExitCode(Command::INVALID);
});

test('write mode requires a practical explicit default weight', function (mixed $weight): void {
    $arguments = ['--force' => true, '--limit' => 1];

    if ($weight !== null) {
        $arguments['--default-weight'] = $weight;
    }

    $this->artisan('import:products', $arguments)
        ->expectsOutput('Write mode requires --default-weight between 1 and 100000 grams.')
        ->assertExitCode(Command::INVALID);
})->with([
    'missing' => [null],
    'zero' => [0],
    'too large' => [100_001],
    'not integer' => ['500.5'],
]);

test('dry-run and force cannot execute together', function (): void {
    $this->artisan('import:products', [
        '--dry-run' => true,
        '--force' => true,
        '--limit' => 1,
    ])
        ->expectsOutput('The --dry-run and --force options cannot be used together.')
        ->assertExitCode(Command::INVALID);
});

test('declined write confirmation performs zero writes', function (): void {
    $this->artisan('import:products', [
        '--offset' => 0,
        '--limit' => 1,
        '--default-weight' => 500,
    ])
        ->expectsConfirmation(
            'Import 1 products from offset 0 with default weight 500g?',
            'no',
        )
        ->expectsOutput('Product import cancelled: no database or storage writes were performed.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(ProductImage::query()->count())->toBe(0);
});

test('force performs a real-source controlled write in the isolated test database', function (): void {
    $this->artisan('import:products', [
        '--force' => true,
        '--offset' => 0,
        '--limit' => 1,
        '--default-weight' => 500,
    ])
        ->expectsOutput('Transaction result: committed')
        ->expectsOutput('Controlled product import committed successfully.')
        ->assertSuccessful();

    $product = Product::query()
        ->where('source', 'hasaki')
        ->where('external_id', '96589')
        ->firstOrFail();

    expect($product->slug)->toEndWith('-96589')
        ->and($product->slug)->not->toStartWith('hasaki-product-');
    $variant = ProductVariant::query()->where('sku', 'HS-96589')->firstOrFail();

    expect($product->id)->toBeGreaterThan(0)
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->weight)->toBe(500)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(BranchInventory::query()->count())->toBe(0);
});

test('a controlled batch creates the complete catalog graph but no inventory or files', function (): void {
    Storage::fake('local');
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Imported Product 1002',
            'images' => ['https://example.test/images/1002-1.jpg'],
        ]),
    ];

    $result = app(ProductJsonImportService::class)->importJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        0,
        2,
        500,
    )->toArray();

    expect($result['transaction_result'])->toBe('committed')
        ->and($result['write_counters']['brands']['created'])->toBe(1)
        ->and($result['write_counters']['categories']['created'])->toBe(2)
        ->and($result['write_counters']['products']['created'])->toBe(2)
        ->and($result['write_counters']['variants']['created'])->toBe(2)
        ->and($result['write_counters']['images']['created'])->toBe(3)
        ->and(Product::query()->count())->toBe(2)
        ->and(ProductVariant::query()->count())->toBe(2)
        ->and(BranchInventory::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('source options never create additional persisted variants', function (): void {
    app(ProductJsonImportService::class)->importJson(
        json_encode([productJsonImportRecord([
            'variants' => [[
                'label' => 'Capacity:',
                'selected' => '50ml',
                'options' => ['10ml', '30ml', '50ml', '100ml'],
            ]],
        ])], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductVariant::query()->firstOrFail()->attributes)->toMatchArray([
            'capacity' => '50ml',
        ]);
});

test('write mode is idempotent and preserves product and variant IDs', function (): void {
    $json = json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR);
    $service = app(ProductJsonImportService::class);
    $first = $service->importJson($json, 0, 1, 500)->toArray();
    $productId = Product::query()->value('id');
    $variantId = ProductVariant::query()->value('id');
    $second = $service->importJson($json, 0, 1, 500)->toArray();

    expect($first['write_counters']['products']['created'])->toBe(1)
        ->and($second['write_counters']['brands']['unchanged'])->toBe(1)
        ->and($second['write_counters']['categories']['unchanged'])->toBe(2)
        ->and($second['write_counters']['products']['unchanged'])->toBe(1)
        ->and($second['write_counters']['variants']['unchanged'])->toBe(1)
        ->and($second['write_counters']['images']['unchanged'])->toBe(2)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe(2)
        ->and(Product::query()->value('id'))->toBe($productId)
        ->and(ProductVariant::query()->value('id'))->toBe($variantId);
});

test('changed source data updates deterministic product and variant without duplication', function (): void {
    $service = app(ProductJsonImportService::class);
    $service->importJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );
    $productId = Product::query()->value('id');
    $variantId = ProductVariant::query()->value('id');
    $changed = productJsonImportRecord([
        'name' => 'Renamed Imported Product',
        'price' => 175_000,
        'originalPrice' => 220_000,
        'variants' => [[
            'label' => 'Capacity:',
            'selected' => '75ml',
            'options' => ['50ml', '75ml'],
        ]],
        'images' => ['https://example.test/images/new-primary.jpg'],
    ]);

    $result = $service->importJson(
        json_encode([$changed], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    )->toArray();
    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();

    expect($result['write_counters']['products']['updated'])->toBe(1)
        ->and($result['write_counters']['variants']['updated'])->toBe(1)
        ->and($result['write_counters']['images']['created'])->toBe(1)
        ->and($result['write_counters']['images']['stale_skipped'])->toBe(2)
        ->and($product->id)->toBe($productId)
        ->and($product->name)->toBe('Renamed Imported Product')
        ->and($variant->id)->toBe($variantId)
        ->and($variant->price)->toBe(220_000)
        ->and($variant->sale_price)->toBe(175_000)
        ->and($variant->attributes['capacity'])->toBe('75ml')
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe(3);
});

test('curated brand metadata and existing product featured status are preserved', function (): void {
    $mapped = app(ProductJsonMapper::class)->map(productJsonImportRecord());
    $brand = Brand::query()->create($mapped['brand'] + [
        'logo_url' => 'curated/logo.png',
        'banner_image' => 'curated/banner.jpg',
        'description' => 'Curated description',
    ]);
    $service = app(ProductJsonImportService::class);
    $service->importJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );
    Product::query()->firstOrFail()->update(['is_featured' => true]);
    $service->importJson(
        json_encode([productJsonImportRecord(['name' => 'Updated Name'])], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );

    expect($brand->refresh()->logo_url)->toBe('curated/logo.png')
        ->and($brand->banner_image)->toBe('curated/banner.jpg')
        ->and($brand->description)->toBe('Curated description')
        ->and(Product::query()->firstOrFail()->is_featured)->toBeTrue();
});

test('invalid and duplicate source barcodes persist as null without failing products', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord([
            'productId' => '1002',
            'name' => 'Duplicate Barcode Product',
        ]),
        productJsonImportRecord([
            'productId' => '1003',
            'name' => 'Invalid Barcode Product',
            'specifications' => ['Barcode' => 'javascript:alert(1)'],
        ]),
    ];

    $result = app(ProductJsonImportService::class)->importJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        0,
        3,
        500,
    )->toArray();

    expect($result['valid'])->toBe(3)
        ->and($result['quality']['duplicate_barcode'])->toBe(1)
        ->and($result['quality']['invalid_barcode'])->toBe(1)
        ->and(ProductVariant::query()->whereNotNull('barcode')->count())->toBe(0);
});

test('an existing database barcode conflict is dropped for a new deterministic variant', function (): void {
    $brand = Brand::query()->create(['name' => 'Existing', 'slug' => 'existing']);
    $category = Category::query()->create(['name' => 'Existing', 'slug' => 'existing']);
    $product = Product::query()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Existing Product',
        'slug' => 'existing-product',
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Existing Variant',
        'sku' => 'EXISTING-SKU',
        'barcode' => '8931234567890',
        'price' => 100_000,
        'weight' => 100,
    ]);

    $result = app(ProductJsonImportService::class)->importJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    )->toArray();

    expect($result['quality']['existing_barcode_conflict'])->toBe(1)
        ->and(ProductVariant::query()->where('sku', 'HS-1001')->value('barcode'))->toBeNull()
        ->and(ProductVariant::query()->where('sku', 'EXISTING-SKU')->value('barcode'))
        ->toBe('8931234567890');
});

test('write mode sanitizes HTML before persistence', function (): void {
    $malicious = '<p onclick="evil()" style="color:red">Readable<strong> text</strong>'
        .'<script>alert(1)</script><a href="javascript:evil()">link</a>'
        .'<iframe src="https://evil.test">frame</iframe></p>';

    app(ProductJsonImportService::class)->importJson(
        json_encode([productJsonImportRecord([
            'description' => $malicious,
            'ingredients' => $malicious,
            'usageInstructions' => $malicious,
        ])], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );
    $product = Product::query()->firstOrFail();

    foreach ([$product->description, $product->ingredients, $product->usage_instructions] as $html) {
        expect($html)->toContain('Readable')
            ->and($html)->toContain('<strong> text</strong>')
            ->and($html)->toContain('link')
            ->and($html)->not->toContain('script')
            ->and($html)->not->toContain('iframe')
            ->and($html)->not->toContain('onclick')
            ->and($html)->not->toContain('style=')
            ->and($html)->not->toContain('javascript:');
    }
});

test('a fatal persistence failure rolls back the complete selected batch', function (): void {
    $createdVariants = 0;
    $eventName = 'eloquent.creating: '.ProductVariant::class;
    Event::listen($eventName, function () use (&$createdVariants): void {
        $createdVariants++;

        if ($createdVariants === 2) {
            throw new RuntimeException('Forced repository persistence failure.');
        }
    });
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord(['productId' => '1002', 'name' => 'Second Product']),
    ];

    try {
        expect(fn () => app(ProductJsonImportService::class)->importJson(
            json_encode($records, JSON_THROW_ON_ERROR),
            0,
            2,
            500,
        ))->toThrow(RuntimeException::class, 'Forced repository persistence failure.');
    } finally {
        Event::forget($eventName);
    }

    expect(Brand::query()->withTrashed()->count())->toBe(0)
        ->and(Category::query()->withTrashed()->count())->toBe(0)
        ->and(Product::query()->withTrashed()->count())->toBe(0)
        ->and(ProductVariant::query()->withTrashed()->count())->toBe(0)
        ->and(ProductImage::query()->count())->toBe(0);
});

test('write offset and limit persist only the selected product window', function (): void {
    $records = [
        productJsonImportRecord(),
        productJsonImportRecord(['productId' => '1002', 'name' => 'Selected Product']),
        productJsonImportRecord(['productId' => '1003', 'name' => 'Third Product']),
    ];

    app(ProductJsonImportService::class)->importJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        1,
        1,
        500,
    );

    expect(Product::query()->pluck('slug')->all())->toBe(['selected-product-1002'])
        ->and(ProductVariant::query()->pluck('sku')->all())->toBe(['HS-1002']);
});

test('soft-deleted deterministic entities are restored with stable IDs', function (): void {
    $json = json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR);
    $service = app(ProductJsonImportService::class);
    $service->importJson($json, 0, 1, 500);
    $brand = Brand::query()->firstOrFail();
    $categories = Category::query()->orderByDesc('id')->get();
    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $ids = [$brand->id, $product->id, $variant->id];
    $variant->delete();
    $product->delete();

    foreach ($categories as $category) {
        $category->delete();
    }

    $brand->delete();
    $result = $service->importJson($json, 0, 1, 500)->toArray();

    expect($result['write_counters']['brands']['restored'])->toBe(1)
        ->and($result['write_counters']['categories']['restored'])->toBe(2)
        ->and($result['write_counters']['products']['restored'])->toBe(1)
        ->and($result['write_counters']['variants']['restored'])->toBe(1)
        ->and([
            Brand::query()->value('id'),
            Product::query()->value('id'),
            ProductVariant::query()->value('id'),
        ])->toBe($ids);
});

test('image synchronization is idempotent and preserves stale unmanaged images', function (): void {
    $json = json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR);
    $service = app(ProductJsonImportService::class);
    $service->importJson($json, 0, 1, 500);
    $product = Product::query()->firstOrFail();
    ProductImage::query()->create([
        'product_id' => $product->id,
        'product_variant_id' => null,
        'image_url' => 'https://manual.test/curated.jpg',
        'alt_text' => 'Curated image',
        'sort_order' => 99,
        'is_primary' => false,
    ]);

    $result = $service->importJson($json, 0, 1, 500)->toArray();

    expect($result['write_counters']['images']['created'])->toBe(0)
        ->and($result['write_counters']['images']['unchanged'])->toBe(2)
        ->and($result['write_counters']['images']['stale_skipped'])->toBe(1)
        ->and(ProductImage::query()->count())->toBe(3)
        ->and(ProductImage::query()->where('image_url', 'https://manual.test/curated.jpg')->exists())
        ->toBeTrue();
});
test('an existing deterministic variant keeps its valid barcode when incoming barcode is invalid', function (): void {
    $service = app(ProductJsonImportService::class);
    $service->importJson(
        json_encode([productJsonImportRecord()], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );
    $service->importJson(
        json_encode([productJsonImportRecord([
            'specifications' => ['Barcode' => 'invalid incoming barcode'],
        ])], JSON_THROW_ON_ERROR),
        0,
        1,
        500,
    );

    expect(ProductVariant::query()->where('sku', 'HS-1001')->value('barcode'))
        ->toBe('8931234567890');
});

test('an identical second write safely compares persisted attributes when source variants are absent', function (): void {
    $json = json_encode([
        productJsonImportRecord(['variants' => []]),
    ], JSON_THROW_ON_ERROR);
    $service = app(ProductJsonImportService::class);

    $service->importJson($json, 0, 1, 500);
    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $productId = $product->id;
    $variantId = $variant->id;
    $attributes = $variant->attributes;

    $result = $service->importJson($json, 0, 1, 500)->toArray();

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe(2)
        ->and(Product::query()->value('id'))->toBe($productId)
        ->and(ProductVariant::query()->value('id'))->toBe($variantId)
        ->and(ProductVariant::query()->firstOrFail()->attributes)->toBe($attributes)
        ->and($result['write_counters']['variants']['unchanged'])->toBe(1);
});
test('nested variant attributes compare canonically without losing list order', function (): void {
    $record = app(ProductJsonMapper::class)->map(productJsonImportRecord());
    $record['variant']['weight'] = 500;
    $record['variant']['attributes'] = [
        'profile' => [
            'type' => 'sensitive',
            'details' => ['zone' => 'T', 'level' => 2],
        ],
        'concerns' => ['dryness', 'redness'],
    ];
    $repository = app(ProductImportRepository::class);

    $repository->transaction(fn (): array => $repository->persistBatch([$record]));
    $productId = Product::query()->value('id');
    $variantId = ProductVariant::query()->value('id');
    $storedAttributes = ProductVariant::query()->firstOrFail()->attributes;

    $sameRecord = $record;
    $sameRecord['variant']['attributes'] = [
        'concerns' => ['dryness', 'redness'],
        'profile' => [
            'details' => ['level' => 2, 'zone' => 'T'],
            'type' => 'sensitive',
        ],
    ];
    $plans = $repository->plan([$sameRecord]);
    $second = $repository->transaction(
        fn (): array => $repository->persistBatch([$sameRecord]),
    );

    expect($plans['variants']['unchanged'])->toBe(1)
        ->and($second['write_counters']['variants']['unchanged'])->toBe(1)
        ->and(Product::query()->value('id'))->toBe($productId)
        ->and(ProductVariant::query()->value('id'))->toBe($variantId)
        ->and(ProductVariant::query()->firstOrFail()->attributes)->toBe($storedAttributes)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe(2);

    $differentListOrder = $sameRecord;
    $differentListOrder['variant']['attributes']['concerns'] = ['redness', 'dryness'];
    expect($repository->plan([$differentListOrder])['variants']['update'])->toBe(1);

    ProductVariant::query()->firstOrFail()->forceFill(['attributes' => null])->save();
    $emptyAttributes = $sameRecord;
    $emptyAttributes['variant']['attributes'] = [];
    expect($repository->plan([$emptyAttributes])['variants']['update'])->toBe(1);
});
test('the identical controlled command succeeds twice without duplicate rows', function (): void {
    $arguments = [
        '--force' => true,
        '--offset' => 0,
        '--limit' => 1,
        '--default-weight' => 500,
    ];

    $this->artisan('import:products', $arguments)
        ->expectsOutput('Transaction result: committed')
        ->assertSuccessful();

    $productId = Product::query()->value('id');
    $variantId = ProductVariant::query()->value('id');
    $attributes = ProductVariant::query()->firstOrFail()->attributes;
    $imageCount = ProductImage::query()->count();

    $this->artisan('import:products', $arguments)
        ->expectsOutput('Transaction result: committed')
        ->expectsOutput('Controlled product import committed successfully.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe($imageCount)
        ->and(Product::query()->value('id'))->toBe($productId)
        ->and(ProductVariant::query()->value('id'))->toBe($variantId)
        ->and(ProductVariant::query()->firstOrFail()->attributes)->toBe($attributes);
});
