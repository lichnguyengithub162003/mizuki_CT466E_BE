<?php

use App\Support\Import\ProductVariantGroupingPolicy;

function groupingProduct(array $overrides = []): array
{
    static $id = 0;
    $id++;

    return array_replace_recursive([
        'id' => $id,
        'source' => 'hasaki',
        'external_id' => (string) (10_000 + $id),
        'brand_id' => 10,
        'brand_name' => 'Mizuki Test Brand',
        'category_id' => 20,
        'category_name' => 'Serum',
        'name' => 'Serum cấp ẩm 50ml',
        'ingredients' => '<p>Hyaluronic acid</p>',
        'usage_instructions' => '<p>Dùng mỗi tối</p>',
        'origin_country' => 'Việt Nam',
        'specifications' => [],
        'source_variant_groups' => [],
        'variant_ids' => [$id * 10],
        'skus' => ['HS-'.(10_000 + $id)],
        'barcodes' => [(string) (8_930_000_000_000 + $id)],
        'variant_attributes' => [['dung_tich' => '50ml']],
    ], $overrides);
}

function evaluateGrouping(array $products, array $operations = []): array
{
    $policy = new ProductVariantGroupingPolicy;

    return $policy->evaluate(
        array_map($policy->profile(...), $products),
        $operations,
    );
}

test('clear volume variants are classified auto safe', function (): void {
    $result = evaluateGrouping([
        groupingProduct(['id' => 101, 'external_id' => '101', 'name' => 'Serum cấp ẩm 50ml', 'variant_ids' => [1001], 'skus' => ['HS-101'], 'barcodes' => ['8930000000101'], 'variant_attributes' => [['dung_tich' => '50ml']]]),
        groupingProduct(['id' => 102, 'external_id' => '102', 'name' => 'Serum cấp ẩm 100ml', 'variant_ids' => [1002], 'skus' => ['HS-102'], 'barcodes' => ['8930000000102'], 'variant_attributes' => [['dung_tich' => '100ml']]]),
    ]);

    expect($result['classification'])->toBe('auto_safe')
        ->and($result['failed_hard_gates'])->toBe([])
        ->and($result['detected_variant_dimensions']['varying'])->toBe(['volume'])
        ->and($result['recommended_canonical_product_id'])->toBe(101);
});

test('same-name products without a clear variant dimension are rejected', function (): void {
    $result = evaluateGrouping([
        groupingProduct(['id' => 201, 'external_id' => '201', 'name' => 'Sữa rửa mặt dịu nhẹ', 'variant_ids' => [2001], 'skus' => ['HS-201'], 'barcodes' => ['8930000000201'], 'variant_attributes' => [[]]]),
        groupingProduct(['id' => 202, 'external_id' => '202', 'name' => 'Sữa rửa mặt dịu nhẹ', 'variant_ids' => [2002], 'skus' => ['HS-202'], 'barcodes' => ['8930000000202'], 'variant_attributes' => [[]]]),
    ]);

    expect($result['classification'])->toBe('rejected')
        ->and($result['failed_hard_gates'])->toContain('differences_explainable_by_variant_dimensions');
});

test('volume removed from names alone is not sufficient grouping evidence', function (): void {
    $result = evaluateGrouping([
        groupingProduct(['id' => 211, 'external_id' => '211', 'name' => 'Sữa rửa mặt 50ml', 'variant_ids' => [2101], 'skus' => ['HS-211'], 'barcodes' => ['8930000000211'], 'variant_attributes' => [[]]]),
        groupingProduct(['id' => 212, 'external_id' => '212', 'name' => 'Sữa rửa mặt 100ml', 'variant_ids' => [2102], 'skus' => ['HS-212'], 'barcodes' => ['8930000000212'], 'variant_attributes' => [[]]]),
    ]);

    expect($result['detected_variant_dimensions']['varying'])->toBe(['volume'])
        ->and($result['detected_variant_dimensions']['attribute_backed_varying'])->toBe([])
        ->and($result['classification'])->toBe('rejected')
        ->and($result['failed_hard_gates'])->toContain('differences_explainable_by_variant_dimensions');
});

test('bundle or combo composition is rejected', function (): void {
    $result = evaluateGrouping([
        groupingProduct(['id' => 301, 'external_id' => '301', 'name' => 'Combo Serum cấp ẩm 50ml', 'variant_ids' => [3001], 'skus' => ['HS-301'], 'barcodes' => ['8930000000301']]),
        groupingProduct(['id' => 302, 'external_id' => '302', 'name' => 'Combo Serum cấp ẩm 100ml', 'variant_ids' => [3002], 'skus' => ['HS-302'], 'barcodes' => ['8930000000302'], 'variant_attributes' => [['dung_tich' => '100ml']]]),
    ]);

    expect($result['classification'])->toBe('rejected')
        ->and($result['bundle_combo_flags'])->toContain('combo')
        ->and($result['failed_hard_gates'])->toContain('not_bundle_combo_gift_set_or_pack');
});

test('category brand and source conflicts each fail their hard gate', function (string $field, mixed $value, string $gate): void {
    $first = groupingProduct(['id' => 401, 'external_id' => '401', 'name' => 'Kem dưỡng 50ml', 'variant_ids' => [4001], 'skus' => ['HS-401'], 'barcodes' => ['8930000000401']]);
    $second = groupingProduct(['id' => 402, 'external_id' => '402', 'name' => 'Kem dưỡng 100ml', 'variant_ids' => [4002], 'skus' => ['HS-402'], 'barcodes' => ['8930000000402'], 'variant_attributes' => [['dung_tich' => '100ml']], $field => $value]);
    $result = evaluateGrouping([$first, $second]);

    expect($result['classification'])->toBe('rejected')
        ->and($result['failed_hard_gates'])->toContain($gate);
})->with([
    'category conflict' => ['category_id', 99, 'compatible_leaf_category'],
    'brand conflict' => ['brand_id', 99, 'same_brand'],
    'source conflict' => ['source', 'another-source', 'same_source'],
]);

test('operational conflicts are reported and require manual review without being mutated', function (): void {
    $products = [
        groupingProduct(['id' => 501, 'external_id' => '501', 'name' => 'Toner 50ml', 'variant_ids' => [5001], 'skus' => ['HS-501'], 'barcodes' => ['8930000000501']]),
        groupingProduct(['id' => 502, 'external_id' => '502', 'name' => 'Toner 100ml', 'variant_ids' => [5002], 'skus' => ['HS-502'], 'barcodes' => ['8930000000502'], 'variant_attributes' => [['dung_tich' => '100ml']]]),
    ];
    $operations = [
        501 => ['inventory' => 6, 'cart_items' => 1, 'order_items' => 2, 'reviews' => 3, 'questions' => 4, 'favorites' => 5],
    ];
    $result = evaluateGrouping($products, $operations);

    expect($result['classification'])->toBe('manual_review')
        ->and($result['operational_conflicts']['by_product'][501])->toBe($operations[501])
        ->and($result['operational_conflicts']['totals']['order_items'])->toBe(2)
        ->and($result['evidence']['hard_gates']['operational_conflicts_detected'])->toBeTrue();
});

test('policy output is deterministic regardless of input product order', function (): void {
    $products = [
        groupingProduct(['id' => 601, 'external_id' => '601', 'name' => 'Gel 50ml', 'variant_ids' => [6001], 'skus' => ['HS-601'], 'barcodes' => ['8930000000601']]),
        groupingProduct(['id' => 602, 'external_id' => '602', 'name' => 'Gel 100ml', 'variant_ids' => [6002], 'skus' => ['HS-602'], 'barcodes' => ['8930000000602'], 'variant_attributes' => [['dung_tich' => '100ml']]]),
    ];

    expect(evaluateGrouping($products))->toBe(evaluateGrouping(array_reverse($products)));
});
