<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Repositories\Import\ProductOwnedEngagementWriteRepository;
use App\Services\Import\ProductOwnedEngagementReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function writeApplyFixtureReports(array $group, string $suffix): array
{
    $manifest = storage_path('framework/testing/t1-1-apply-'.$suffix.'.json');
    $content = storage_path('framework/testing/t1-5-apply-'.$suffix.'.json');
    $engagement = storage_path('framework/testing/t1-6-apply-'.$suffix.'.json');
    File::put($manifest, json_encode(['groups' => [$group]], JSON_THROW_ON_ERROR));
    File::put($content, json_encode(['groups' => [[
        'group_identifier' => $group['group_identifier'],
        'recommended_merge_decision' => 'manual_review',
        'reasons' => ['review_question_or_favorite_ownership_conflict', 't1_1_manual_review_required'],
    ]]], JSON_THROW_ON_ERROR));
    $result = app(ProductOwnedEngagementReconciliationService::class)->audit($manifest, $content);
    File::put($engagement, $result->toJson());

    return compact('manifest', 'content', 'engagement');
}

function engagementApplyFixture(string $suffix): array
{
    $brand = Brand::query()->create(['name' => 'Apply Brand '.$suffix, 'slug' => 'apply-brand-'.$suffix, 'is_active' => true]);
    $category = Category::query()->create(['name' => 'Serum '.$suffix, 'slug' => 'apply-serum-'.$suffix, 'is_active' => true, 'sort_order' => 0]);
    $products = [];
    $variants = [];
    foreach ([9101 => '30ml', 9102 => '50ml'] as $externalId => $volume) {
        $product = Product::query()->create([
            'source' => 'hasaki', 'external_id' => (string) $externalId,
            'category_id' => $category->id, 'brand_id' => $brand->id,
            'name' => 'Apply '.$volume, 'slug' => 'apply-'.$suffix.'-'.$externalId,
            'is_active' => true, 'is_featured' => false,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'source' => 'hasaki', 'external_id' => (string) $externalId,
            'name' => $volume, 'sku' => 'APPLY-'.$suffix.'-'.$externalId,
            'attributes' => ['dung_tich' => $volume], 'price' => 100_000,
            'weight' => 500, 'sort_order' => 0, 'is_active' => true,
        ]);
        $products[] = $product;
        $variants[] = $variant;
    }
    $group = [
        'group_identifier' => 'pvg-apply-'.$suffix,
        'classification' => 'auto_safe',
        'candidate_product_ids' => array_column($products, 'id'),
        'candidate_variant_ids' => array_column($variants, 'id'),
        'recommended_canonical_product_id' => $products[0]->id,
    ];

    return compact('products', 'variants', 'group');
}

function reconciliationCommandArguments(array $paths, string $group, bool $apply = false): array
{
    return [
        '--manifest' => $paths['manifest'],
        '--content-report' => $paths['content'],
        '--engagement-report' => $paths['engagement'],
        '--group' => [$group],
        '--apply' => $apply,
    ];
}

test('dry run validates engagement plan and makes zero database mutations', function (): void {
    $fixture = engagementApplyFixture('dry');
    Review::query()->create([
        'source' => 'hasaki', 'source_key' => 'dry-review', 'user_id' => null,
        'product_id' => $fixture['products'][1]->id, 'rating' => 5, 'comment' => 'Tốt', 'is_visible' => true,
    ]);
    $paths = writeApplyFixtureReports($fixture['group'], 'dry');
    $before = DB::table('reviews')->get()->map(fn (object $row): array => (array) $row)->all();

    $this->artisan('products:reconcile-owned-engagement', reconciliationCommandArguments(
        $paths,
        $fixture['group']['group_identifier'],
    ))->expectsOutput('Groups validated: 1')->assertExitCode(Command::SUCCESS);

    expect(DB::table('reviews')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before);
});

test('apply safely attributes deduplicates and transfers engagement then reruns idempotently', function (): void {
    $fixture = engagementApplyFixture('success');
    [$canonical, $duplicate] = $fixture['products'];
    Review::query()->create([
        'source' => 'hasaki', 'source_key' => 'review-a', 'user_id' => null,
        'product_id' => $canonical->id, 'rating' => 5, 'comment' => 'Rất tốt', 'is_visible' => true,
    ]);
    Review::query()->create([
        'source' => 'hasaki', 'source_key' => 'review-b', 'user_id' => null,
        'product_id' => $duplicate->id, 'rating' => 5, 'comment' => 'Rất tốt', 'is_visible' => true,
    ]);
    $questionA = ProductQuestion::query()->create([
        'product_id' => $canonical->id, 'source' => 'hasaki', 'external_key' => 'same-question',
        'author_name' => 'Khách', 'question' => 'Dùng được không?', 'sort_order' => 0,
    ]);
    $questionB = ProductQuestion::query()->create([
        'product_id' => $duplicate->id, 'source' => 'hasaki', 'external_key' => 'same-question',
        'author_name' => 'Khách', 'question' => 'Dùng được không?', 'sort_order' => 0,
    ]);
    foreach ([$questionA, $questionB] as $question) {
        ProductQuestionAnswer::query()->create([
            'product_question_id' => $question->id, 'source' => 'hasaki',
            'external_key' => 'answer-'.$question->id, 'author_name' => 'Mizuki',
            'answer' => 'Dùng được', 'sort_order' => 0,
        ]);
    }
    $user = User::factory()->create();
    ProductFavorite::query()->create(['user_id' => $user->id, 'product_id' => $canonical->id]);
    ProductFavorite::query()->create(['user_id' => $user->id, 'product_id' => $duplicate->id]);
    $paths = writeApplyFixtureReports($fixture['group'], 'success');
    $arguments = reconciliationCommandArguments($paths, $fixture['group']['group_identifier'], true);

    $this->artisan('products:reconcile-owned-engagement', $arguments)
        ->expectsOutput('Groups reconciled: 1')
        ->assertExitCode(Command::SUCCESS);

    expect(Review::query()->whereNull('deleted_at')->count())->toBe(1)
        ->and(Review::query()->first()->product_id)->toBe($canonical->id)
        ->and(Review::query()->first()->product_variant_id)->toBe($fixture['variants'][0]->id)
        ->and(ProductQuestion::query()->count())->toBe(1)
        ->and(ProductQuestion::query()->first()->product_id)->toBe($canonical->id)
        ->and(ProductQuestionAnswer::query()->count())->toBe(1)
        ->and(ProductFavorite::query()->count())->toBe(1)
        ->and(ProductFavorite::query()->first()->product_id)->toBe($canonical->id);

    $this->artisan('products:reconcile-owned-engagement', $arguments)
        ->expectsOutput('Already reconciled: 1')
        ->assertExitCode(Command::SUCCESS);
});

test('non identical reviews by one user block apply', function (): void {
    $fixture = engagementApplyFixture('review-conflict');
    $user = User::factory()->create();
    Review::query()->create(['user_id' => $user->id, 'product_id' => $fixture['products'][0]->id, 'rating' => 5, 'comment' => 'Tốt', 'is_visible' => true]);
    Review::query()->create(['user_id' => $user->id, 'product_id' => $fixture['products'][1]->id, 'rating' => 1, 'comment' => 'Không hợp', 'is_visible' => true]);
    $paths = writeApplyFixtureReports($fixture['group'], 'review-conflict');

    $this->artisan('products:reconcile-owned-engagement', reconciliationCommandArguments($paths, $fixture['group']['group_identifier'], true))
        ->expectsOutputToContain('Unapproved engagement reconciliation group')
        ->assertExitCode(Command::FAILURE);
});

test('same question key with a different answer set blocks apply', function (): void {
    $fixture = engagementApplyFixture('answer-conflict');
    foreach ($fixture['products'] as $index => $product) {
        $question = ProductQuestion::query()->create([
            'product_id' => $product->id, 'source' => 'hasaki', 'external_key' => 'same-key',
            'author_name' => 'Khách', 'question' => 'Dùng được không?', 'sort_order' => 0,
        ]);
        ProductQuestionAnswer::query()->create([
            'product_question_id' => $question->id, 'source' => 'hasaki', 'external_key' => 'answer-'.$index,
            'author_name' => 'Mizuki', 'answer' => $index === 0 ? 'Có' : 'Không', 'sort_order' => 0,
        ]);
    }
    $paths = writeApplyFixtureReports($fixture['group'], 'answer-conflict');

    $this->artisan('products:reconcile-owned-engagement', reconciliationCommandArguments($paths, $fixture['group']['group_identifier'], true))
        ->expectsOutputToContain('Unapproved engagement reconciliation group')
        ->assertExitCode(Command::FAILURE);
});

test('stale report state is rejected before mutation', function (): void {
    $fixture = engagementApplyFixture('stale');
    $review = Review::query()->create([
        'source' => 'hasaki', 'source_key' => 'stale-review', 'user_id' => null,
        'product_id' => $fixture['products'][1]->id, 'rating' => 5, 'comment' => 'Ban đầu', 'is_visible' => true,
    ]);
    $paths = writeApplyFixtureReports($fixture['group'], 'stale');
    $review->update(['comment' => 'Đã thay đổi sau report']);

    $this->artisan('products:reconcile-owned-engagement', reconciliationCommandArguments($paths, $fixture['group']['group_identifier'], true))
        ->expectsOutputToContain('Stale T1.6 report or engagement state')
        ->assertExitCode(Command::FAILURE);

    expect($review->fresh()->product_id)->toBe($fixture['products'][1]->id)
        ->and($review->fresh()->product_variant_id)->toBeNull();
});

test('unknown group and apply without an explicit group are rejected', function (): void {
    $fixture = engagementApplyFixture('unknown');
    $paths = writeApplyFixtureReports($fixture['group'], 'unknown');

    $this->artisan('products:reconcile-owned-engagement', reconciliationCommandArguments($paths, 'pvg-unknown', true))
        ->expectsOutputToContain('Unknown reconciliation group')
        ->assertExitCode(Command::FAILURE);

    $arguments = reconciliationCommandArguments($paths, $fixture['group']['group_identifier'], true);
    $arguments['--group'] = [];
    $this->artisan('products:reconcile-owned-engagement', $arguments)
        ->expectsOutputToContain('--apply requires at least one explicit --group option')
        ->assertExitCode(Command::FAILURE);
});

test('write repository transaction rolls back an intermediate engagement update', function (): void {
    $fixture = engagementApplyFixture('rollback');
    $favorite = ProductFavorite::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $fixture['products'][1]->id,
    ]);
    $repository = app(ProductOwnedEngagementWriteRepository::class);

    try {
        $repository->transaction(function (ProductOwnedEngagementWriteRepository $repository) use ($favorite, $fixture): void {
            $repository->transferFavorite($favorite->id, $fixture['products'][0]->id);
            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('forced rollback');
    }

    expect($favorite->fresh()->product_id)->toBe($fixture['products'][1]->id);
});
