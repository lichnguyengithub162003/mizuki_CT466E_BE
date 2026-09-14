<?php

use App\Support\Import\ProductOwnedEngagementReconciler;

function engagementGroup(): array
{
    return [
        'group_identifier' => 'pvg-engagement-test',
        'classification' => 'auto_safe',
        'candidate_product_ids' => [1, 2],
        'candidate_variant_ids' => [11, 12],
        'recommended_canonical_product_id' => 1,
    ];
}

function engagementReview(int $id, int $productId, ?int $userId, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'product_id' => $productId,
        'product_variant_id' => null,
        'order_item_id' => null,
        'user_id' => $userId,
        'rating' => 5,
        'title' => 'Tốt',
        'comment' => 'Sản phẩm phù hợp',
        'source' => $userId === null ? 'hasaki' : null,
        'source_key' => $userId === null ? 'review-'.$id : null,
        'source_author_name' => $userId === null ? 'Khách' : null,
        'source_verified_purchase' => $userId === null ? 1 : null,
        'source_date' => '01/01/2026',
        'variant_purchased' => null,
        'images' => null,
    ], $overrides);
}

function engagementQuestion(int $id, int $productId, string $key, string $text): array
{
    return [
        'id' => $id,
        'product_id' => $productId,
        'source' => 'hasaki',
        'external_key' => $key,
        'author_name' => 'Khách',
        'question' => $text,
        'asked_at' => null,
        'source_date' => '01/01/2026',
    ];
}

function engagementData(array $overrides = []): array
{
    return array_replace([
        'products' => [
            ['id' => 1, 'source' => 'hasaki', 'external_id' => '1001'],
            ['id' => 2, 'source' => 'hasaki', 'external_id' => '1002'],
        ],
        'variants' => [
            ['id' => 11, 'product_id' => 1, 'source_product_id' => 1, 'source' => 'hasaki', 'external_id' => '1001', 'sku' => 'HS-1001', 'barcode' => '111', 'attributes' => ['dung_tich' => '30ml']],
            ['id' => 12, 'product_id' => 2, 'source_product_id' => 2, 'source' => 'hasaki', 'external_id' => '1002', 'sku' => 'HS-1002', 'barcode' => '222', 'attributes' => ['dung_tich' => '50ml']],
        ],
        'reviews' => [],
        'order_items' => [],
        'questions' => [],
        'answers' => [],
        'favorites' => [],
    ], $overrides);
}

function reconcileEngagement(array $data): array
{
    return (new ProductOwnedEngagementReconciler)->reconcile(
        engagementGroup(),
        $data,
        ['recommended_merge_decision' => 'manual_review'],
    );
}

test('review without a collision is safe to transfer', function (): void {
    $result = reconcileEngagement(engagementData([
        'reviews' => [engagementReview(1, 2, 50)],
    ]));

    expect($result['reviews']['actions'][0]['classification'])->toBe('transfer_safe')
        ->and($result['reviews']['variant_attribution_by_record'][1])->toMatchArray([
            'status' => 'attributable_to_one_variant',
            'variant_id' => 12,
        ]);
});

test('identical reviews by the same user are safe to deduplicate', function (): void {
    $result = reconcileEngagement(engagementData([
        'reviews' => [
            engagementReview(1, 1, 50),
            engagementReview(2, 2, 50),
        ],
    ]));

    expect($result['reviews']['actions'][0]['classification'])->toBe('deduplicate_safe')
        ->and($result['reviews']['actions'][0]['content_relationship'])->toBe('identical')
        ->and($result['reviews']['deduplicated'])->toBe(1)
        ->and($result['unique_key_conflicts']['reviews_user_product'])->toHaveCount(1);
});

test('genuinely different reviews by the same user can be preserved only as separate variant reviews', function (): void {
    $result = reconcileEngagement(engagementData([
        'reviews' => [
            engagementReview(1, 1, 50, ['comment' => 'Bản 30ml rất hợp']),
            engagementReview(2, 2, 50, ['comment' => 'Bản 50ml gây khô']),
        ],
    ]));

    expect($result['reviews']['actions'][0]['classification'])->toBe('preserve_as_separate_variant_reviews')
        ->and($result['reviews']['actions'][0]['content_relationship'])->toBe('genuinely_different')
        ->and($result['resulting_engagement_decision'])->toBe('needs_variant_attribution')
        ->and($result['blockers'])->toContain('reviews_user_product_unique_blocks_separate_variant_reviews');
});

test('duplicate imported question and answers are safe to deduplicate', function (): void {
    $result = reconcileEngagement(engagementData([
        'questions' => [
            engagementQuestion(1, 1, 'same-key', 'Da nhạy cảm dùng được không?'),
            engagementQuestion(2, 2, 'same-key', 'Da nhạy cảm dùng được không?'),
        ],
        'answers' => [
            ['id' => 1, 'product_question_id' => 1, 'author_name' => 'Mizuki', 'answer' => 'Dùng được'],
            ['id' => 2, 'product_question_id' => 2, 'author_name' => 'Mizuki', 'answer' => 'Dùng được'],
        ],
    ]));

    expect($result['questions']['actions'][0]['classification'])->toBe('deduplicate_safe')
        ->and($result['questions']['deduplicated'])->toBe(1);
});

test('same question external key with genuinely different content is a manual conflict', function (): void {
    $result = reconcileEngagement(engagementData([
        'questions' => [
            engagementQuestion(1, 1, 'same-key', 'Có dùng cho da dầu không?'),
            engagementQuestion(2, 2, 'same-key', 'Sản phẩm có gây kích ứng không?'),
        ],
    ]));

    expect($result['questions']['actions'][0]['classification'])->toBe('manual_conflict')
        ->and($result['resulting_engagement_decision'])->toBe('manual_review');
});

test('duplicate product family favorite is safe to deduplicate', function (): void {
    $result = reconcileEngagement(engagementData([
        'favorites' => [
            ['id' => 1, 'product_id' => 1, 'user_id' => 70],
            ['id' => 2, 'product_id' => 2, 'user_id' => 70],
        ],
    ]));

    expect($result['favorites']['actions'][0]['classification'])->toBe('deduplicate_safe')
        ->and($result['favorites']['deduplicated'])->toBe(1)
        ->and($result['unique_key_conflicts']['favorites_user_product'])->toHaveCount(1);
});

test('engagement reconciliation output is deterministic regardless of row order', function (): void {
    $data = engagementData([
        'reviews' => [engagementReview(2, 2, null), engagementReview(1, 1, null)],
        'questions' => [
            engagementQuestion(2, 2, 'same', 'Dung tích bao nhiêu?'),
            engagementQuestion(1, 1, 'same', 'Dung tích bao nhiêu?'),
        ],
        'favorites' => [
            ['id' => 2, 'product_id' => 2, 'user_id' => 70],
            ['id' => 1, 'product_id' => 1, 'user_id' => 70],
        ],
    ]);
    $reversed = $data;
    foreach (['products', 'variants', 'reviews', 'questions', 'favorites'] as $key) {
        $reversed[$key] = array_reverse($reversed[$key]);
    }

    expect(reconcileEngagement($data))->toBe(reconcileEngagement($reversed));
});
