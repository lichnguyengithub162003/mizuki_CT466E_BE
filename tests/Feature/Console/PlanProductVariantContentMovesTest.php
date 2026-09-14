<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('variant content move command is deterministic and makes zero database mutations', function (): void {
    $brand = Brand::query()->create(['name' => 'Plan Brand', 'slug' => 'plan-brand', 'is_active' => true]);
    $category = Category::query()->create(['name' => 'Serum', 'slug' => 'plan-serum', 'is_active' => true, 'sort_order' => 0]);
    $products = [];
    $variants = [];
    foreach ([9001 => '30ml', 9002 => '50ml'] as $externalId => $volume) {
        $product = Product::query()->create([
            'source' => 'hasaki', 'external_id' => (string) $externalId,
            'category_id' => $category->id, 'brand_id' => $brand->id,
            'name' => 'Serum phục hồi '.$volume, 'slug' => 'plan-'.$externalId,
            'short_description' => 'Dịu nhẹ', 'description' => 'Phục hồi da',
            'ingredients' => 'Hyaluronic acid', 'usage_instructions' => 'Dùng mỗi tối',
            'specifications' => ['Dung tích' => $volume, 'Xuất xứ' => 'Việt Nam'],
            'origin_country' => 'Việt Nam', 'is_active' => true, 'is_featured' => false,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'source' => 'hasaki', 'external_id' => (string) $externalId,
            'name' => $volume, 'sku' => 'HS-'.$externalId, 'attributes' => [],
            'price' => 100000, 'weight' => 500, 'sort_order' => 0, 'is_active' => true,
        ]);
        ProductImage::query()->create([
            'product_id' => $product->id, 'image_url' => '/'.$externalId.'.jpg',
            'is_primary' => true, 'sort_order' => 0,
        ]);
        $products[] = $product;
        $variants[] = $variant;
    }
    $images = ProductImage::query()->orderBy('id')->get();
    $reportPath = storage_path('framework/testing/t1-5-move-source.json');
    $outputPath = storage_path('framework/testing/t1-9-move-plan.json');
    $group = [
        'group_identifier' => 'pvg-command-move',
        'recommended_merge_decision' => 'merge_after_variant_content_move',
        'candidate_product_ids' => array_map(fn (Product $product): int => $product->id, $products),
        'candidate_variant_ids' => array_map(fn (ProductVariant $variant): int => $variant->id, $variants),
        'canonical_recommendation' => $products[0]->id,
        'per_field_classification' => array_fill_keys([
            'short_description', 'description', 'ingredients', 'usage_instructions',
            'origin_country', 'brand_id', 'category_id',
        ], ['classification' => 'shared_exact']),
    ];
    $group['per_field_classification']['images']['by_product'] = [
        $products[0]->id => [['id' => $images[0]->id]],
        $products[1]->id => [['id' => $images[1]->id]],
    ];
    File::put($reportPath, json_encode(['groups' => [$group]], JSON_THROW_ON_ERROR));
    File::delete($outputPath);
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'images' => DB::table('product_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];

    $this->artisan('products:plan-variant-content-moves', [
        '--content-report' => $reportPath,
        '--output' => $outputPath,
    ])->expectsOutput('Groups analyzed: 1')->assertExitCode(Command::SUCCESS);
    $first = File::get($outputPath);
    $this->artisan('products:plan-variant-content-moves', [
        '--content-report' => $reportPath,
        '--output' => $outputPath,
    ])->assertExitCode(Command::SUCCESS);
    $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);

    expect(File::get($outputPath))->toBe($first)
        ->and($report['summary']['groups_analyzed'])->toBe(1)
        ->and($report['groups'][0]['readiness'])->toBe('ready_for_variant_content_apply')
        ->and([
            'products' => DB::table('products')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'images' => DB::table('product_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->toBe($before);

    File::delete([$reportPath, $outputPath]);
});

test('variant content move command rejects a missing T1.5 report', function (): void {
    $this->artisan('products:plan-variant-content-moves', [
        '--content-report' => storage_path('framework/testing/missing-t1-5.json'),
        '--output' => storage_path('framework/testing/unused-t1-9.json'),
    ])->expectsOutputToContain('The latest T1.5 content reconciliation report is missing or unreadable')
        ->assertExitCode(Command::FAILURE);
});
