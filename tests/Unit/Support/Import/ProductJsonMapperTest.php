<?php

use App\Support\Import\ProductJsonMapper;

beforeEach(function (): void {
    $this->mapper = new ProductJsonMapper;
    $this->record = static fn (array $overrides = []): array => array_replace([
        'productId' => '00123',
        'name' => 'Serum phục hồi da',
        'brand' => '  Mizuki   Lab  ',
        'url' => 'https://example.test/products/00123',
        'image' => 'https://example.test/images/card.jpg',
        'price' => 180_000,
        'originalPrice' => 250_000,
        'subName' => 'Serum dịu nhẹ',
        'categoryPaths' => [
            ['Chăm Sóc Da Mặt', 'Đặc Trị', 'Serum'],
        ],
        'breadcrumbPath' => [
            'Sức Khỏe - Làm Đẹp',
            'Chăm Sóc Da Mặt',
            'Đặc Trị',
            'Serum',
            'Serum phục hồi da',
        ],
        'variants' => [
            [
                'label' => 'Dung Tích:',
                'selected' => '50ml',
                'options' => ['30ml', '50ml'],
            ],
        ],
        'specifications' => [
            'Barcode' => '8931234567890',
            'Xuất xứ thương hiệu' => 'Việt Nam',
        ],
        'description' => '<p><strong>Mô tả HTML</strong></p>',
        'ingredients' => '<p>Niacinamide</p>',
        'usageInstructions' => '<ol><li>Dùng mỗi tối</li></ol>',
        'images' => [
            'https://example.test/images/one.jpg',
            'https://example.test/images/two.png',
        ],
        'localImages' => ['images/00123/1.jpg'],
    ], $overrides);
});

test('mapper creates deterministic product and synthetic variant identities', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect($mapped['status'])->toBe('valid')
        ->and($mapped['source_id'])->toBe('00123')
        ->and($mapped['product_slug'])->toBe('hasaki-product-00123')
        ->and($mapped['synthetic_sku'])->toBe('HS-00123')
        ->and($mapped['variant']['sku'])->toBe('HS-00123');
});

test('a name change does not alter product or variant identity', function (): void {
    $first = $this->mapper->map(($this->record)(['name' => 'Tên ban đầu']));
    $renamed = $this->mapper->map(($this->record)(['name' => 'Tên đã đổi']));

    expect($renamed['product_slug'])->toBe($first['product_slug'])
        ->and($renamed['synthetic_sku'])->toBe($first['synthetic_sku']);
});

test('current and original prices map to price and sale price correctly', function (): void {
    $discounted = $this->mapper->map(($this->record)());
    $regular = $this->mapper->map(($this->record)([
        'price' => 180_000,
        'originalPrice' => null,
    ]));

    expect($discounted['variant']['price'])->toBe(250_000)
        ->and($discounted['variant']['sale_price'])->toBe(180_000)
        ->and($regular['variant']['price'])->toBe(180_000)
        ->and($regular['variant']['sale_price'])->toBeNull();
});

test('brand whitespace is normalized and a deterministic slug is produced', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect($mapped['brand'])->toMatchArray([
        'name' => 'Mizuki Lab',
        'slug' => 'mizuki-lab',
    ]);
});

test('breadcrumb produces the canonical category hierarchy', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect(array_column($mapped['categories'], 'name'))->toBe([
        'Chăm Sóc Da Mặt',
        'Đặc Trị',
        'Serum',
    ])->and($mapped['category_slug'])->toBe($mapped['categories'][2]['slug']);
});

test('category paths provide a fallback when breadcrumb has no category', function (): void {
    $mapped = $this->mapper->map(($this->record)([
        'breadcrumbPath' => ['Gift', 'Serum phục hồi da'],
        'categoryPaths' => [
            null,
            ['Trang Điểm', 'Trang Điểm Mặt', 'Kem Lót'],
        ],
    ]));

    expect(array_column($mapped['categories'], 'name'))->toBe([
        'Trang Điểm',
        'Trang Điểm Mặt',
        'Kem Lót',
    ]);
});

test('selected variant attributes are mapped without expanding options', function (): void {
    $mapped = $this->mapper->map(($this->record)([
        'variants' => [
            [
                'label' => 'Dung Tích:',
                'selected' => '50ml',
                'options' => ['30ml', '50ml', '100ml'],
            ],
            [
                'label' => 'Loại Da:',
                'selected' => 'Da nhạy cảm',
                'options' => ['Da dầu', 'Da nhạy cảm'],
            ],
        ],
    ]));

    expect($mapped['variant']['attributes'])->toBe([
        'dung_tich' => '50ml',
        'loai_da' => 'Da nhạy cảm',
    ])->and($mapped)->toHaveKey('variant')
        ->and($mapped)->not->toHaveKey('variants')
        ->and($mapped['metadata']['variant_options'])->toHaveCount(2);
});

test('an invalid optional barcode is dropped without quarantining the product', function (): void {
    $mapped = $this->mapper->map(($this->record)([
        'specifications' => [
            'Barcode' => '&#39;) WHERE 1=(select sleep(2))--',
        ],
    ]));

    expect($mapped['status'])->toBe('valid')
        ->and($mapped['variant']['barcode'])->toBeNull()
        ->and($mapped['warnings'])->toContain('invalid_barcode');
});

test('image order is preserved and only the first image is primary', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect(array_column($mapped['images'], 'image_url'))->toBe([
        'https://example.test/images/one.jpg',
        'https://example.test/images/two.png',
    ])->and(array_column($mapped['images'], 'sort_order'))->toBe([0, 1])
        ->and(array_column($mapped['images'], 'is_primary'))->toBe([true, false]);
});

test('HTML content is preserved exactly in the dry-run DTO', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect($mapped['product']['description'])->toBe('<p><strong>Mô tả HTML</strong></p>')
        ->and($mapped['product']['ingredients'])->toBe('<p>Niacinamide</p>')
        ->and($mapped['product']['usage_instructions'])->toBe(
            '<ol><li>Dùng mỗi tối</li></ol>',
        );
});

test('shipping weight is never invented and missing policy is reported', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect($mapped['variant']['weight'])->toBeNull()
        ->and($mapped['warnings'])->toContain('missing_weight_policy');
});
