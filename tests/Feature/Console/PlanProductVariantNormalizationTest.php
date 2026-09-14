<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function createNormalizationProduct(
    Brand $brand,
    Category $category,
    int $externalId,
    string $name,
    string $volume,
): Product {
    $product = Product::query()->create([
        'source' => 'hasaki',
        'external_id' => (string) $externalId,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => 'normalization-'.$externalId,
        'is_active' => true,
        'is_featured' => false,
        'specifications' => [],
        'source_variant_groups' => [],
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => $volume,
        'sku' => 'HS-'.$externalId,
        'attributes' => ['dung_tich' => $volume],
        'price' => 100_000,
        'sale_price' => null,
        'weight' => 500,
        'sort_order' => 0,
        'is_active' => true,
    ]);

    return $product;
}

test('command writes a deterministic manifest and performs no database mutation', function (): void {
    $brand = Brand::query()->create([
        'name' => 'Normalization Brand',
        'slug' => 'normalization-brand',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Serum',
        'slug' => 'normalization-serum',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $first = createNormalizationProduct($brand, $category, 7001, 'Serum phục hồi 30ml', '30ml');
    $second = createNormalizationProduct($brand, $category, 7002, 'Serum phục hồi 50ml', '50ml');
    $path = storage_path('framework/testing/product-variant-normalization.json');
    File::delete($path);
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];

    $this->artisan('products:plan-variant-normalization', ['--output' => $path])
        ->expectsOutput('Products scanned: 2')
        ->expectsOutput('Candidate groups: 1')
        ->expectsOutput('Auto safe: 1')
        ->assertExitCode(Command::SUCCESS);

    $firstManifest = File::get($path);
    $manifest = json_decode($firstManifest, true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('products:plan-variant-normalization', ['--output' => $path])
        ->assertExitCode(Command::SUCCESS);

    expect(File::get($path))->toBe($firstManifest)
        ->and($manifest['summary'])->toMatchArray([
            'products_scanned' => 2,
            'candidate_groups' => 1,
            'auto_safe' => 1,
            'products_involved' => 2,
        ])
        ->and($manifest['groups'][0])->toHaveKeys([
            'group_identifier',
            'classification',
            'candidate_product_ids',
            'candidate_variant_ids',
            'source_external_ids',
            'brand',
            'category',
            'normalized_core_name',
            'detected_variant_dimensions',
            'evidence',
            'failed_hard_gates',
            'bundle_combo_flags',
            'content_conflicts',
            'operational_conflicts',
            'recommended_canonical_product_id',
        ])
        ->and($manifest['groups'][0]['candidate_product_ids'])->toBe([$first->id, $second->id])
        ->and([
            'products' => DB::table('products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])->toBe($before);

    File::delete($path);
});

test('command rejects empty source without touching the database', function (): void {
    $before = Product::query()->count();

    $this->artisan('products:plan-variant-normalization', ['--source' => ''])
        ->expectsOutput('The --source and --output options must not be empty')
        ->assertExitCode(Command::INVALID);

    expect(Product::query()->count())->toBe($before);
});
