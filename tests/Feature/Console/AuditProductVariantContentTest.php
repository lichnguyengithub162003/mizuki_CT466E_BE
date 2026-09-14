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

test('content reconciliation command is deterministic and does not mutate the database', function (): void {
    $brand = Brand::query()->create(['name' => 'Audit Brand', 'slug' => 'audit-brand', 'is_active' => true]);
    $category = Category::query()->create([
        'name' => 'Serum',
        'slug' => 'audit-serum',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $products = [];
    $variants = [];

    foreach ([7001 => '30ml', 7002 => '50ml'] as $externalId => $volume) {
        $product = Product::query()->create([
            'source' => 'hasaki',
            'external_id' => (string) $externalId,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Serum phục hồi '.$volume,
            'slug' => 'audit-product-'.$externalId,
            'short_description' => 'Dịu nhẹ',
            'description' => 'Hỗ trợ phục hồi da',
            'ingredients' => 'Hyaluronic acid',
            'usage_instructions' => 'Dùng mỗi tối',
            'specifications' => ['Dung tích' => $volume],
            'origin_country' => 'Việt Nam',
            'external_rating' => 4.5,
            'external_review_count' => 10,
            'is_active' => true,
            'is_featured' => false,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'source' => 'hasaki',
            'external_id' => (string) $externalId,
            'name' => $volume,
            'sku' => 'HS-'.$externalId,
            'attributes' => ['dung_tich' => $volume],
            'price' => 100_000,
            'weight' => 500,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $products[] = $product;
        $variants[] = $variant;
    }

    $manifestPath = storage_path('framework/testing/t1-1-content-manifest.json');
    $outputPath = storage_path('framework/testing/t1-5-content-report.json');
    File::put($manifestPath, json_encode([
        'groups' => [[
            'group_identifier' => 'pvg-content-command',
            'classification' => 'manual_review',
            'candidate_product_ids' => array_column($products, 'id'),
            'candidate_variant_ids' => array_column($variants, 'id'),
            'recommended_canonical_product_id' => $products[0]->id,
            'detected_variant_dimensions' => ['values' => ['volume' => ['30ml', '50ml']]],
        ]],
    ], JSON_THROW_ON_ERROR));
    File::delete($outputPath);
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'images' => DB::table('product_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'reviews' => DB::table('reviews')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'questions' => DB::table('product_questions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'favorites' => DB::table('product_favorites')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];

    $this->artisan('products:audit-variant-content', [
        '--manifest' => $manifestPath,
        '--output' => $outputPath,
    ])->expectsOutput('Candidate groups analyzed: 1')
        ->assertExitCode(Command::SUCCESS);

    $firstReport = File::get($outputPath);
    $report = json_decode($firstReport, true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('products:audit-variant-content', [
        '--manifest' => $manifestPath,
        '--output' => $outputPath,
    ])->assertExitCode(Command::SUCCESS);

    expect(File::get($outputPath))->toBe($firstReport)
        ->and($report['summary']['total_candidate_groups_analyzed'])->toBe(1)
        ->and($report['summary']['merge_ready'])->toBe(1)
        ->and($report['groups'][0]['source_manifest_classification'])->toBe('manual_review')
        ->and($report['groups'][0]['recommended_merge_decision'])->toBe('merge_ready')
        ->and($report['groups'][0]['reasons'])->not->toContain('t1_1_manual_review_required')
        ->and($report['groups'][0])->toHaveKeys([
            'group_identifier',
            'candidate_product_ids',
            'canonical_recommendation',
            'per_field_classification',
            'shared_content_candidate',
            'variant_specific_content_candidate',
            'semantic_conflicts',
            'specification_diff',
            'operational_content',
            'recommended_merge_decision',
            'reasons',
        ])->and([
            'products' => DB::table('products')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'images' => DB::table('product_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'reviews' => DB::table('reviews')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'questions' => DB::table('product_questions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'favorites' => DB::table('product_favorites')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->toBe($before);

    File::delete([$manifestPath, $outputPath]);
});

test('content reconciliation command rejects a missing manifest', function (): void {
    $this->artisan('products:audit-variant-content', [
        '--manifest' => storage_path('framework/testing/missing-t1-1-manifest.json'),
        '--output' => storage_path('framework/testing/unused-t1-5-report.json'),
    ])->expectsOutputToContain('The T1.1 normalization manifest is missing or unreadable')
        ->assertExitCode(Command::FAILURE);
});

test('content reconciliation reports normalized database state without mutation', function (): void {
    $brand = Brand::query()->create(['name' => 'Normalized Brand', 'slug' => 'normalized-brand', 'is_active' => true]);
    $category = Category::query()->create([
        'name' => 'Normalized Category',
        'slug' => 'normalized-category',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $canonical = Product::query()->create([
        'source' => 'hasaki',
        'external_id' => 'normalized-1',
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Approved canonical name',
        'slug' => 'approved-canonical-name',
        'is_active' => true,
        'is_featured' => false,
    ]);
    $retired = Product::query()->create([
        'source' => 'hasaki',
        'external_id' => 'normalized-2',
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Legacy source name 50ml',
        'slug' => 'legacy-source-name-50ml',
        'is_active' => false,
        'is_featured' => false,
        'source_variant_groups' => [
            '_mizuki_variant_normalization' => [
                'group_identifier' => 'pvg-normalized-command',
                'retired_product_id' => 0,
                'canonical_product_id' => $canonical->id,
            ],
        ],
    ]);
    $retired->source_variant_groups = [
        '_mizuki_variant_normalization' => [
            'group_identifier' => 'pvg-normalized-command',
            'retired_product_id' => $retired->id,
            'canonical_product_id' => $canonical->id,
        ],
    ];
    $retired->save();
    $retired->delete();
    $variants = [];
    foreach ([
        ['external_id' => 'normalized-1', 'sku' => 'NORMALIZED-1', 'volume' => '30ml'],
        ['external_id' => 'normalized-2', 'sku' => 'NORMALIZED-2', 'volume' => '50ml'],
    ] as $attributes) {
        $variants[] = ProductVariant::query()->create([
            'product_id' => $canonical->id,
            'source' => 'hasaki',
            'external_id' => $attributes['external_id'],
            'name' => $attributes['volume'],
            'sku' => $attributes['sku'],
            'attributes' => ['dung_tich' => $attributes['volume']],
            'price' => 100_000,
            'weight' => 500,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }
    $manifestPath = storage_path('framework/testing/t1-1-normalized-content-manifest.json');
    $outputPath = storage_path('framework/testing/t1-5-normalized-content-report.json');
    File::put($manifestPath, json_encode(['groups' => [[
        'group_identifier' => 'pvg-normalized-command',
        'classification' => 'manual_review',
        'candidate_product_ids' => [$canonical->id, $retired->id],
        'candidate_variant_ids' => array_column($variants, 'id'),
        'recommended_canonical_product_id' => $canonical->id,
        'source_external_ids' => [
            ['product_id' => $canonical->id, 'source' => 'hasaki', 'external_id' => 'normalized-1'],
            ['product_id' => $retired->id, 'source' => 'hasaki', 'external_id' => 'normalized-2'],
        ],
        'detected_variant_dimensions' => ['values' => ['volume' => ['30ml', '50ml']]],
    ]]], JSON_THROW_ON_ERROR));
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
    ];

    $this->artisan('products:audit-variant-content', [
        '--manifest' => $manifestPath,
        '--output' => $outputPath,
    ])->expectsOutput('Already normalized: 1')
        ->assertSuccessful();

    $report = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($report['summary']['already_normalized'])->toBe(1)
        ->and($report['summary']['do_not_merge'])->toBe(0)
        ->and($report['groups'][0]['recommended_merge_decision'])->toBe('already_normalized')
        ->and($report['groups'][0]['reasons'])->not->toContain('critical_semantic_conflict:name')
        ->and([
            'products' => DB::table('products')->orderBy('id')->get()->toJson(),
            'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        ])->toBe($before);

    File::delete([$manifestPath, $outputPath]);
});
