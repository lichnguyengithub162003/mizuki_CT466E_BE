<?php

use App\Support\Import\ProductReviewJsonMapper;

beforeEach(function (): void {
    $this->mapper = new ProductReviewJsonMapper;
});

test('maps complete imported review fields and normalizes reply branding', function (): void {
    $result = $this->mapper->map('185708', [[
        'author' => 'hoàng văn Nhật',
        'verifiedPurchase' => true,
        'ratingScore' => '5',
        'ratingLabel' => 'Rất hài lòng',
        'date' => '2025-10-20, 13:19',
        'variantPurchased' => 'Dung tích 500ml',
        'comment' => 'Sản phẩm dùng rất tốt.',
        'images' => [' https://example.test/a.jpg ', '', null, 'https://example.test/a.jpg', 'https://example.test/b.jpg'],
        'hasakiReply' => 'Hasaki xin chào! Xem thêm tại hasaki.vn và HASAKI.',
    ]]);
    $review = $result['records'][0];

    expect($result['stats'])->toBe(['skipped' => 0, 'duplicate_collapsed' => 0, 'failed' => 0])
        ->and($review['source'])->toBe('hasaki')
        ->and($review['source_key'])->toHaveLength(64)
        ->and($review['source_author_name'])->toBe('hoàng văn Nhật')
        ->and($review['source_verified_purchase'])->toBeTrue()
        ->and($review['rating'])->toBe(5)
        ->and($review['title'])->toBe('Rất hài lòng')
        ->and($review['comment'])->toBe('Sản phẩm dùng rất tốt.')
        ->and($review['source_date'])->toBe('2025-10-20, 13:19')
        ->and($review['created_at'])->toBe('2025-10-20 13:19:00')
        ->and($review['variant_purchased'])->toBe('Dung tích 500ml')
        ->and($review['images'])->toBe([
            'https://example.test/a.jpg',
            'https://example.test/b.jpg',
        ])
        ->and($review['mizuki_response_content'])->toBe(
            'Mizuki xin chào! Xem thêm tại Mizuki và Mizuki.',
        )
        ->and($review['user_id'])->toBeNull()
        ->and($review['order_item_id'])->toBeNull();
});

test('preserves malformed source date and safely normalizes malformed images and empty reply', function (): void {
    $result = $this->mapper->map('100', [[
        'ratingScore' => 4.0,
        'comment' => 'Usable comment',
        'date' => 'unknown date',
        'images' => 'not-an-array',
        'hasakiReply' => '   ',
    ]]);
    $review = $result['records'][0];

    expect($review['source_date'])->toBe('unknown date')
        ->and($review['created_at'])->toBeNull()
        ->and($review['images'])->toBe([])
        ->and($review['mizuki_response_content'])->toBeNull()
        ->and($review['source_author_name'])->toBeNull()
        ->and($review['source_verified_purchase'])->toBeNull();
});

test('skips invalid ratings and empty comments without failing the product', function (): void {
    $result = $this->mapper->map('100', [
        ['ratingScore' => 6, 'comment' => 'Invalid rating'],
        ['ratingScore' => 5, 'comment' => '   '],
        'malformed',
    ]);

    expect($result['records'])->toBe([])
        ->and($result['stats'])->toBe([
            'skipped' => 2,
            'duplicate_collapsed' => 0,
            'failed' => 1,
        ]);
});

test('collapses duplicate identities and keeps source identity stable across repeated mapping', function (): void {
    $review = [
        'author' => 'Customer',
        'verifiedPurchase' => false,
        'ratingScore' => '5',
        'date' => '2025-10-20, 13:19',
        'variantPurchased' => '500ml',
        'comment' => 'Same review',
    ];
    $first = $this->mapper->map('185708', [$review, $review]);
    $second = $this->mapper->map('185708', [$review]);

    expect($first['records'])->toHaveCount(1)
        ->and($first['stats']['duplicate_collapsed'])->toBe(1)
        ->and($first['records'][0]['source_key'])->toBe($second['records'][0]['source_key']);
});

test('null and malformed review collections are handled consistently', function (): void {
    expect($this->mapper->map('100', null))->toBe([
        'records' => [],
        'stats' => ['skipped' => 0, 'duplicate_collapsed' => 0, 'failed' => 0],
    ])->and($this->mapper->map('100', 'malformed')['stats']['failed'])->toBe(1);
});
