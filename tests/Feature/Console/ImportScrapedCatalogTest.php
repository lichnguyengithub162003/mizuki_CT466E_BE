<?php

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Services\Import\ProductJsonImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function scrapedCatalogRecord(array $overrides = []): array
{
    return array_replace_recursive([
        'productId' => '1001',
        'name' => 'Serum Mizuki nhập thử',
        'brand' => '  MIZUKI   LAB ',
        'url' => 'https://example.test/products/1001',
        'image' => 'https://example.test/images/1001.jpg',
        'price' => 180_000,
        'originalPrice' => 220_000,
        'images' => ['https://example.test/images/1001.jpg'],
        'localImages' => ['images/1001/1.png'],
        'variants' => [[
            'label' => 'Dung Tích:',
            'selected' => '50ml',
            'options' => ['30ml', '50ml'],
        ]],
        'specifications' => ['Barcode' => '8931234567890', 'Loại da' => 'Da nhạy cảm'],
        'description' => '<p>Mô tả <script>bad()</script>an toàn</p>',
        'ingredients' => '<p>Niacinamide</p>',
        'usageInstructions' => '<p>Dùng mỗi tối</p>',
        'breadcrumbPath' => ['Sức Khỏe - Làm Đẹp', 'Chăm Sóc Da', 'Serum', 'Sản phẩm'],
        'categoryPaths' => [['Chăm Sóc Da', 'Serum']],
    ], $overrides);
}

function writeScrapedCatalogFixture(array $records): string
{
    Storage::disk('local')->put(
        'catalog-fixtures/all-products.json',
        json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    );

    return Storage::disk('local')->path('catalog-fixtures/all-products.json');
}

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

test('dry run validates and reports without database or public image writes', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord()]);

    $this->artisan('mizuki:import-scraped-data', [
        '--source' => $source,
        '--dry-run' => true,
        '--limit' => 1,
    ])->expectsOutput('Mizuki scraped catalog dry-run')->assertSuccessful();

    $reportFiles = Storage::disk('local')->allFiles('import-reports');
    $report = json_decode(
        Storage::disk('local')->get($reportFiles[0]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($reportFiles)->toHaveCount(1)
        ->and(array_key_exists('planned_records', $report['result']))->toBeFalse()
        ->and(Storage::disk('local')->size($reportFiles[0]))->toBeLessThan(25_000);
});

test('large fixture crosses bounded chunks and reruns idempotently with a compact report', function (): void {
    $records = [];

    foreach (range(1, 75) as $index) {
        $records[] = scrapedCatalogRecord([
            'productId' => (string) (20_000 + $index),
            'name' => "Bounded product {$index}",
            'description' => '<p>'.str_repeat('bounded-memory-', 500).'</p>',
            'image' => "https://example.test/images/{$index}.jpg",
            'images' => ["https://example.test/images/{$index}.jpg"],
            'localImages' => [],
        ]);
    }

    $source = writeScrapedCatalogFixture($records);
    $arguments = [
        '--source' => $source,
        '--skip-images' => true,
        '--update-existing' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $productIds = Product::query()->orderBy('id')->pluck('id')->all();
    $variantIds = ProductVariant::query()->orderBy('id')->pluck('id')->all();

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();

    $reportFiles = Storage::disk('local')->allFiles('import-reports');
    sort($reportFiles);
    $reportFile = $reportFiles[array_key_last($reportFiles)];
    $report = json_decode(
        Storage::disk('local')->get($reportFile),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(Product::query()->count())->toBe(75)
        ->and(ProductVariant::query()->count())->toBe(75)
        ->and(ProductImage::query()->count())->toBe(75)
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe($productIds)
        ->and(ProductVariant::query()->orderBy('id')->pluck('id')->all())->toBe($variantIds)
        ->and($report['result']['write_counters']['products']['unchanged'])->toBe(75)
        ->and($report['result']['write_counters']['variants']['unchanged'])->toBe(75)
        ->and($report['result']['write_counters']['images']['unchanged'])->toBe(75)
        ->and(array_key_exists('planned_records', $report['result']))->toBeFalse()
        ->and(Storage::disk('local')->size($reportFile))->toBeLessThan(25_000);
});

test('report keeps only five bounded quarantine examples', function (): void {
    $records = [];

    foreach (range(1, 12) as $index) {
        $records[] = scrapedCatalogRecord([
            'productId' => (string) (30_000 + $index),
            'name' => '',
        ]);
    }

    $source = writeScrapedCatalogFixture($records);

    $this->artisan('mizuki:import-scraped-data', [
        '--source' => $source,
        '--dry-run' => true,
    ])->assertSuccessful();

    $reportFile = Storage::disk('local')->allFiles('import-reports')[0];
    $result = json_decode(
        Storage::disk('local')->get($reportFile),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['result'];

    expect($result['selected'])->toBe(12)
        ->and($result['quarantined'])->toBe(12)
        ->and($result['quarantine_examples'])->toHaveCount(5)
        ->and(array_key_exists('planned_records', $result))->toBeFalse();
});
test('first write creates the frontend catalog graph local image and development inventory', function (): void {
    Branch::query()->create([
        'code' => 'BRIDGE-01',
        'name' => 'Mizuki Retail Bridge',
        'branch_type' => BranchType::Store,
        'phone' => '02923888888',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord()]);
    Storage::disk('local')->put(
        'catalog-fixtures/images/1001/1.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    );

    $this->artisan('mizuki:import-scraped-data', [
        '--source' => $source,
        '--limit' => 1,
        '--default-weight' => 500,
    ])->expectsOutput('Mizuki scraped catalog import')->assertSuccessful();

    $product = Product::query()->sole();
    $variant = ProductVariant::query()->sole();

    expect($product->source)->toBe('hasaki')
        ->and($product->external_id)->toBe('1001')
        ->and($product->source_url)->toBe('https://example.test/products/1001')
        ->and($product->specifications)->toMatchArray(['Loại da' => 'Da nhạy cảm'])
        ->and($product->description)->not->toContain('script')
        ->and($variant->sku)->toBe('HS-1001')
        ->and($variant->attributes)->toMatchArray(['dung_tich' => '50ml'])
        ->and($variant->weight)->toBe(500)
        ->and(ProductImage::query()->sole()->image_url)->toStartWith('/storage/catalog/products/1001/')
        ->and(BranchInventory::query()->sole()->quantity)->toBe(20);

    expect(Storage::disk('public')->allFiles('catalog/products/1001'))->toHaveCount(1);
});

test('catalog import persists source family metadata idempotently without creating extra variants', function (): void {
    $variantGroups = [[
        'id' => 143,
        'code' => 'capacity',
        'label' => 'Dung TÃ­ch',
        'selected' => '100ml',
        'options' => [[
            'id' => 15,
            'label' => '100ml',
            'products' => [
                ['productId' => '1001', 'sku' => 'HS-SOURCE-1001'],
                ['productId' => '1002', 'sku' => 'HS-SOURCE-1002'],
            ],
        ]],
    ]];
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'variantGroups' => $variantGroups,
    ])]);
    $arguments = [
        '--source' => $source,
        '--skip-images' => true,
        '--update-existing' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $product = Product::query()->sole();
    $variant = ProductVariant::query()->sole();

    expect($product->source_variant_groups)->toHaveCount(1)
        ->and($product->source_variant_groups[0]['options'][0]['products'])->toHaveCount(2)
        ->and($variant->sku)->toBe('HS-1001')
        ->and(ProductVariant::query()->count())->toBe(1);

    $variantGroups[0]['selected'] = '50ml';
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'variantGroups' => $variantGroups,
    ])]);
    $this->artisan('mizuki:import-scraped-data', $arguments + ['--source' => $source])
        ->assertSuccessful();
    $this->artisan('mizuki:import-scraped-data', $arguments + ['--source' => $source])
        ->assertSuccessful();

    expect(Product::query()->sole()->source_variant_groups[0]['selected'])->toBe('50ml')
        ->and(Product::query()->sole()->id)->toBe($product->id)
        ->and(ProductVariant::query()->sole()->id)->toBe($variant->id)
        ->and(ProductVariant::query()->count())->toBe(1);
});

test('real image import removes placeholder normalizes primary and remains idempotent', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'localImages' => ['images/1001/1.png', 'images/1001/2.png'],
    ])]);
    $baseArguments = [
        '--source' => $source,
        '--limit' => 1,
    ];

    $this->artisan('mizuki:import-scraped-data', $baseArguments + ['--skip-images' => true])
        ->assertSuccessful();
    expect(ProductImage::query()->sole()->image_url)->toBe('/images/product-placeholder.svg');

    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
    Storage::disk('local')->put('catalog-fixtures/images/1001/1.png', $png);
    Storage::disk('local')->put('catalog-fixtures/images/1001/2.png', $png."\0");

    $this->artisan('mizuki:import-scraped-data', $baseArguments + ['--update-existing' => true])
        ->assertSuccessful();

    $images = ProductImage::query()->orderBy('sort_order')->orderBy('id')->get();
    $imageIds = $images->pluck('id')->all();

    expect($images)->toHaveCount(2)
        ->and($images->where('is_primary', true))->toHaveCount(1)
        ->and($images->first()->is_primary)->toBeTrue()
        ->and($images->pluck('sort_order')->all())->toBe([0, 1])
        ->and($images->every(fn (ProductImage $image): bool => str_starts_with(
            $image->image_url,
            '/storage/catalog/products/1001/',
        )))->toBeTrue()
        ->and(ProductImage::query()->where('image_url', '/images/product-placeholder.svg')->exists())->toBeFalse();

    $this->artisan('mizuki:import-scraped-data', $baseArguments + ['--update-existing' => true])
        ->assertSuccessful();

    $rerunImages = ProductImage::query()->orderBy('sort_order')->orderBy('id')->get();

    expect($rerunImages)->toHaveCount(2)
        ->and($rerunImages->pluck('id')->all())->toBe($imageIds)
        ->and($rerunImages->where('is_primary', true))->toHaveCount(1)
        ->and(ProductImage::query()->where('image_url', '/images/product-placeholder.svg')->exists())->toBeFalse();
});
test('reruns are idempotent and update existing is explicit', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord()]);
    $arguments = [
        '--source' => $source,
        '--limit' => 1,
        '--skip-images' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $productId = Product::query()->value('id');
    $variantId = ProductVariant::query()->value('id');
    $changed = scrapedCatalogRecord(['name' => 'Tên mới từ crawler']);
    $source = writeScrapedCatalogFixture([$changed]);

    $this->artisan('mizuki:import-scraped-data', $arguments + ['--source' => $source])
        ->assertSuccessful();
    expect(Product::query()->sole()->name)->toBe('Serum Mizuki nhập thử');

    $this->artisan('mizuki:import-scraped-data', $arguments + [
        '--source' => $source,
        '--update-existing' => true,
    ])->assertSuccessful();

    expect(Product::query()->sole()->name)->toBe('Tên mới từ crawler')
        ->and(Product::query()->value('id'))->toBe($productId)
        ->and(ProductVariant::query()->value('id'))->toBe($variantId)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->count())->toBe(1)
        ->and(Brand::query()->count())->toBe(1)
        ->and(Category::query()->count())->toBe(2);
});

test('scraped import preserves follower count when the source has no follower field', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord()]);
    $arguments = [
        '--source' => $source,
        '--limit' => 1,
        '--skip-images' => true,
        '--update-existing' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $brand = Brand::query()->sole();
    $brand->update(['follower_count' => 321]);

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();

    expect($brand->refresh()->follower_count)->toBe(321)
        ->and(Brand::query()->count())->toBe(1);
});

test('update existing with skipped images updates aggregate ratings without changing related rows', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord()]);
    $arguments = [
        '--source' => $source,
        '--limit' => 1,
        '--skip-images' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();

    $product = Product::query()->sole();
    $productId = $product->id;
    $variantIds = ProductVariant::query()->pluck('id')->all();
    $imageIds = ProductImage::query()->pluck('id')->all();
    $inventoryCount = BranchInventory::query()->count();

    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'ratingScore' => 4.65,
        'ratingCount' => 321,
    ])]);

    $this->artisan('mizuki:import-scraped-data', $arguments + [
        '--source' => $source,
        '--update-existing' => true,
    ])->assertSuccessful();

    $product->refresh();

    expect($product->id)->toBe($productId)
        ->and($product->external_rating)->toBe('4.65')
        ->and($product->external_review_count)->toBe(321)
        ->and(ProductVariant::query()->pluck('id')->all())->toBe($variantIds)
        ->and(ProductImage::query()->pluck('id')->all())->toBe($imageIds)
        ->and(BranchInventory::query()->count())->toBe($inventoryCount);
});
test('invalid records are skipped while valid records and fallback image are imported', function (): void {
    $source = writeScrapedCatalogFixture([
        scrapedCatalogRecord(),
        scrapedCatalogRecord(['productId' => '1002', 'name' => '']),
    ]);

    $this->artisan('mizuki:import-scraped-data', [
        '--source' => $source,
        '--limit' => 2,
        '--skip-images' => true,
    ])->expectsOutput('Invalid records were skipped; inspect the machine-readable report.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductImage::query()->sole()->image_url)->toBe('/images/product-placeholder.svg');
});

test('real Hasaki sample imports five actual questions and answers idempotently', function (): void {
    $service = app(ProductJsonImportService::class);
    $path = base_path('data-import/hasaki/all-products.json');
    $arguments = [
        'path' => $path,
        'offset' => 1296,
        'limit' => 1,
        'defaultWeight' => 500,
        'sourceDirectory' => dirname($path),
        'updateExisting' => true,
        'skipImages' => true,
        'dryRun' => false,
    ];

    $service->processCatalogFile(...$arguments);
    $product = Product::query()->where('external_id', '185708')->sole();
    $questions = ProductQuestion::query()
        ->where('product_id', $product->id)
        ->where('source', 'hasaki')
        ->orderBy('sort_order')
        ->get();
    $questionIds = $questions->pluck('id')->all();
    $answerIds = ProductQuestionAnswer::query()->orderBy('id')->pluck('id')->all();

    expect($product->slug)->toBe('dau-goi-loreal-giam-gay-rung-toc-giup-toc-chac-khoe-440ml-185708')
        ->and($questions)->toHaveCount(5)
        ->and($questions->pluck('author_name')->all())->toBe([
            'Anh Thư',
            'Dương Thanh Na',
            'Nguyệt Phan',
            'Hanh Le',
            'Lan Anh Hoang',
        ])
        ->and($questions->pluck('sort_order')->all())->toBe([0, 1, 2, 3, 4])
        ->and(ProductQuestionAnswer::query()->count())->toBe(5);

    $service->processCatalogFile(...$arguments);

    expect(ProductQuestion::query()->orderBy('id')->pluck('id')->all())->toBe($questionIds)
        ->and(ProductQuestionAnswer::query()->orderBy('id')->pluck('id')->all())->toBe($answerIds)
        ->and(ProductQuestion::query()->count())->toBe(5)
        ->and(ProductQuestionAnswer::query()->count())->toBe(5);
});

test('question synchronization preserves malformed dates and Q&A from another source', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'qa' => [
            [
                'author' => 'Imported A',
                'question' => 'Question removed on next crawl',
                'date' => 'unparseable-date',
                'answers' => [[
                    'author' => 'Hasaki',
                    'text' => 'Imported answer',
                    'date' => 'unknown-answer-date',
                ]],
            ],
            [
                'author' => 'Imported B',
                'question' => 'Question retained',
                'date' => '2026-06-13, 20:11',
                'answers' => [],
            ],
        ],
    ])]);
    $arguments = [
        '--source' => $source,
        '--limit' => 1,
        '--skip-images' => true,
        '--update-existing' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $product = Product::query()->sole();
    $malformed = ProductQuestion::query()
        ->where('question', 'Question removed on next crawl')
        ->sole();

    expect($malformed->asked_at)->toBeNull()
        ->and($malformed->source_date)->toBe('unparseable-date')
        ->and($malformed->answers()->sole()->source_date)->toBe('unknown-answer-date');

    ProductQuestion::query()->create([
        'product_id' => $product->id,
        'source' => 'mizuki',
        'external_key' => 'native-question',
        'author_name' => 'Mizuki customer',
        'question' => 'Native question must survive',
        'sort_order' => 0,
    ]);
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'qa' => [[
            'author' => 'Imported B',
            'question' => 'Question retained',
            'date' => '2026-06-13, 20:11',
            'answers' => [],
        ]],
    ])]);

    $this->artisan('mizuki:import-scraped-data', $arguments + ['--source' => $source])
        ->assertSuccessful();

    expect(ProductQuestion::query()->where('source', 'hasaki')->count())->toBe(1)
        ->and(ProductQuestion::query()->where('source', 'mizuki')->count())->toBe(1)
        ->and(ProductQuestion::query()->where('question', 'Native question must survive')->exists())->toBeTrue()
        ->and(ProductQuestionAnswer::query()->where('source', 'hasaki')->count())->toBe(0);
});

test('catalog import persists actual source reviews idempotently without fake commerce records', function (): void {
    $review = [
        'author' => 'Khách nguồn',
        'verifiedPurchase' => true,
        'ratingScore' => '5',
        'ratingLabel' => 'Rất hài lòng',
        'date' => '2025-10-20, 13:19',
        'variantPurchased' => '50ml',
        'comment' => 'Sản phẩm dùng rất tốt.',
        'images' => ['https://example.test/review.jpg'],
        'hasakiReply' => 'Hasaki cảm ơn bạn đã mua sắm tại hasaki.vn.',
    ];
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'reviewCount' => 99,
        'reviews' => [
            $review,
            $review,
            [
                'author' => 'Khách khác',
                'verifiedPurchase' => false,
                'ratingScore' => '4',
                'date' => 'unknown date',
                'comment' => 'Đánh giá hợp lệ thứ hai.',
                'images' => 'malformed',
            ],
            ['ratingScore' => '5', 'comment' => '   '],
        ],
    ])]);
    $arguments = [
        '--source' => $source,
        '--skip-images' => true,
        '--update-existing' => true,
    ];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $product = Product::query()->sole();
    $variant = ProductVariant::query()->sole();
    $imported = Review::query()->where('source', 'hasaki')->orderBy('id')->get();
    $reviewIds = $imported->pluck('id')->all();
    $entityCounts = [
        Product::class => Product::query()->count(),
        ProductVariant::class => ProductVariant::query()->count(),
        ProductImage::class => ProductImage::query()->count(),
        Order::class => Order::query()->count(),
    ];

    expect($imported)->toHaveCount(2)
        ->and($imported[0]->product_id)->toBe($product->id)
        ->and($imported[0]->product_variant_id)->toBe($variant->id)
        ->and($imported[0]->user_id)->toBeNull()
        ->and($imported[0]->order_item_id)->toBeNull()
        ->and($imported[0]->source_author_name)->toBe('Khách nguồn')
        ->and($imported[0]->source_verified_purchase)->toBeTrue()
        ->and($imported[0]->images)->toBe(['https://example.test/review.jpg'])
        ->and($imported[0]->mizuki_response_content)->toBe('Mizuki cảm ơn bạn đã mua sắm tại Mizuki.')
        ->and($imported[1]->source_date)->toBe('unknown date')
        ->and(User::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0);

    $reportFiles = Storage::disk('local')->allFiles('import-reports');
    sort($reportFiles);
    $firstReport = json_decode(
        Storage::disk('local')->get($reportFiles[array_key_last($reportFiles)]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($firstReport['result']['write_counters']['reviews'])->toMatchArray([
        'created' => 2,
        'skipped' => 1,
        'duplicate_collapsed' => 1,
        'failed' => 0,
    ]);

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $reportFiles = Storage::disk('local')->allFiles('import-reports');
    sort($reportFiles);
    $secondReport = json_decode(
        Storage::disk('local')->get($reportFiles[array_key_last($reportFiles)]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(Review::query()->where('source', 'hasaki')->pluck('id')->all())->toBe($reviewIds)
        ->and($secondReport['result']['write_counters']['reviews'])->toMatchArray([
            'created' => 0,
            'updated' => 0,
            'unchanged' => 2,
        ]);

    foreach ($entityCounts as $model => $count) {
        expect($model::query()->count())->toBe($count);
    }
});

test('source review synchronization removes stale Hasaki rows but preserves native and other sources', function (): void {
    $firstReview = [
        'author' => 'First',
        'verifiedPurchase' => true,
        'ratingScore' => '5',
        'comment' => 'First imported review',
    ];
    $secondReview = [
        'author' => 'Second',
        'verifiedPurchase' => false,
        'ratingScore' => '4',
        'comment' => 'Second imported review',
    ];
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'reviews' => [$firstReview, $secondReview],
    ])]);
    $arguments = ['--source' => $source, '--skip-images' => true, '--update-existing' => true];

    $this->artisan('mizuki:import-scraped-data', $arguments)->assertSuccessful();
    $product = Product::query()->sole();
    $user = User::factory()->create();
    Review::query()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'rating' => 5,
        'comment' => 'Native Mizuki review',
        'is_visible' => true,
    ]);
    Review::query()->create([
        'source' => 'another-source',
        'source_key' => hash('sha256', 'another-source'),
        'product_id' => $product->id,
        'rating' => 3,
        'comment' => 'Other source review',
        'is_visible' => true,
    ]);
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord(['reviews' => [$firstReview]])]);

    $this->artisan('mizuki:import-scraped-data', $arguments + ['--source' => $source])
        ->assertSuccessful();

    expect(Review::query()->where('source', 'hasaki')->count())->toBe(1)
        ->and(Review::onlyTrashed()->where('source', 'hasaki')->count())->toBe(1)
        ->and(Review::query()->whereNull('source')->where('comment', 'Native Mizuki review')->exists())->toBeTrue()
        ->and(Review::query()->where('source', 'another-source')->exists())->toBeTrue();
});

test('database unique source identity prevents duplicate imported review rows', function (): void {
    $source = writeScrapedCatalogFixture([scrapedCatalogRecord([
        'reviews' => [[
            'ratingScore' => '5',
            'comment' => 'Unique imported review',
        ]],
    ])]);

    $this->artisan('mizuki:import-scraped-data', [
        '--source' => $source,
        '--skip-images' => true,
        '--update-existing' => true,
    ])->assertSuccessful();
    $review = Review::query()->where('source', 'hasaki')->sole();

    expect(fn () => Review::query()->create([
        'source' => $review->source,
        'source_key' => $review->source_key,
        'product_id' => $review->product_id,
        'rating' => 5,
        'comment' => 'Attempted duplicate',
        'is_visible' => true,
    ]))->toThrow(QueryException::class);
});
