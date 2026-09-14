<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('owned engagement audit command is deterministic and performs no database mutation', function (): void {
    $brand = Brand::query()->create(['name' => 'Engagement Brand', 'slug' => 'engagement-brand', 'is_active' => true]);
    $category = Category::query()->create(['name' => 'Serum', 'slug' => 'engagement-serum', 'is_active' => true, 'sort_order' => 0]);
    $products = [];
    $variants = [];
    foreach ([8101 => '30ml', 8102 => '50ml'] as $externalId => $volume) {
        $product = Product::query()->create([
            'source' => 'hasaki',
            'external_id' => (string) $externalId,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Serum '.$volume,
            'slug' => 'engagement-product-'.$externalId,
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
    ProductQuestion::query()->create([
        'product_id' => $products[1]->id,
        'source' => 'hasaki',
        'external_key' => 'question-1',
        'author_name' => 'Khách',
        'question' => 'Sản phẩm dùng thế nào?',
        'sort_order' => 0,
    ]);

    $manifestPath = storage_path('framework/testing/t1-1-engagement.json');
    $contentPath = storage_path('framework/testing/t1-5-engagement.json');
    $outputPath = storage_path('framework/testing/t1-6-engagement.json');
    $group = [
        'group_identifier' => 'pvg-engagement-command',
        'classification' => 'auto_safe',
        'candidate_product_ids' => array_column($products, 'id'),
        'candidate_variant_ids' => array_column($variants, 'id'),
        'recommended_canonical_product_id' => $products[0]->id,
    ];
    File::put($manifestPath, json_encode(['groups' => [$group]], JSON_THROW_ON_ERROR));
    File::put($contentPath, json_encode(['groups' => [[
        'group_identifier' => $group['group_identifier'],
        'recommended_merge_decision' => 'manual_review',
    ]]], JSON_THROW_ON_ERROR));
    File::delete($outputPath);
    $tables = ['products', 'product_variants', 'reviews', 'product_questions', 'product_question_answers', 'product_favorites'];
    $snapshot = fn (): array => collect($tables)->mapWithKeys(
        fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()],
    )->all();
    $before = $snapshot();

    $this->artisan('products:audit-owned-engagement', [
        '--manifest' => $manifestPath,
        '--content-report' => $contentPath,
        '--output' => $outputPath,
    ])->expectsOutput('Groups with engagement: 1')
        ->assertExitCode(Command::SUCCESS);
    $first = File::get($outputPath);
    $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('products:audit-owned-engagement', [
        '--manifest' => $manifestPath,
        '--content-report' => $contentPath,
        '--output' => $outputPath,
    ])->assertExitCode(Command::SUCCESS);

    expect(File::get($outputPath))->toBe($first)
        ->and($report['summary']['total_candidate_groups_analyzed'])->toBe(1)
        ->and($report['groups'][0])->toHaveKeys([
            'group_identifier', 'reviews', 'questions', 'favorites',
            'variant_attribution_opportunities', 'unique_key_conflicts', 'blockers',
            'resulting_engagement_decision',
        ])->and($snapshot())->toBe($before);

    File::delete([$manifestPath, $contentPath, $outputPath]);
});
