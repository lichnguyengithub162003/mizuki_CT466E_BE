<?php

use App\Support\Import\ProductVariantContentMovePlanner;

function movePlanGroup(array $overrides = []): array
{
    return array_replace_recursive([
        'group_identifier' => 'pvg-move-test',
        'recommended_merge_decision' => 'merge_after_variant_content_move',
        'candidate_product_ids' => [1, 2],
        'candidate_variant_ids' => [11, 12],
        'canonical_recommendation' => 1,
        'per_field_classification' => array_fill_keys([
            'short_description', 'description', 'ingredients', 'usage_instructions',
            'origin_country', 'brand_id', 'category_id',
        ], ['classification' => 'shared_exact']),
    ], $overrides);
}

function movePlanData(array $productOverrides = [], array $variantOverrides = [], array $images = []): array
{
    $products = [];
    foreach ([1 => '30ml', 2 => '50ml'] as $id => $volume) {
        $products[] = array_replace([
            'id' => $id,
            'source' => 'hasaki',
            'external_id' => (string) (1000 + $id),
            'name' => 'Serum phục hồi '.$volume,
            'short_description' => 'Dịu nhẹ',
            'description' => 'Phục hồi da',
            'ingredients' => 'Hyaluronic acid',
            'usage_instructions' => 'Dùng mỗi tối',
            'specifications' => ['Dung tích' => $volume, 'Xuất xứ' => 'Việt Nam'],
            'origin_country' => 'Việt Nam',
            'brand_id' => 10,
            'category_id' => 20,
        ], $productOverrides[$id] ?? []);
    }
    $variants = [];
    foreach ([11 => 1, 12 => 2] as $id => $productId) {
        $variants[] = array_replace([
            'id' => $id,
            'product_id' => $productId,
            'source_product_id' => $productId,
            'source' => 'hasaki',
            'external_id' => (string) (1000 + $productId),
            'name' => null,
            'sku' => 'HS-'.$id,
            'barcode' => null,
            'weight' => 500,
            'attributes' => [],
        ], $variantOverrides[$id] ?? []);
    }

    return [
        'products' => $products,
        'variants' => $variants,
        'images' => $images,
        'reviews' => [],
        'questions' => [],
        'favorites' => [],
    ];
}

function planMove(array $data, ?array $group = null): array
{
    $group ??= movePlanGroup();
    $group['per_field_classification']['images']['by_product'] = [];
    foreach ($data['images'] as $image) {
        $group['per_field_classification']['images']['by_product'][$image['source_product_id']][] = ['id' => $image['id']];
    }

    return (new ProductVariantContentMovePlanner)->plan($group, $data);
}

test('volume and color specifications are proposed as Variant attributes', function (): void {
    $volume = planMove(movePlanData());
    $color = planMove(movePlanData([
        1 => ['name' => 'Son dưỡng Màu Đỏ', 'specifications' => ['Màu sắc' => 'Đỏ']],
        2 => ['name' => 'Son dưỡng Màu Hồng', 'specifications' => ['Màu sắc' => 'Hồng']],
    ]));

    expect($volume['variant_attribute_changes'][0]['attributes_after']['volume'])->toBe('30ml')
        ->and($volume['product_specification_keys_moving_to_variant']['Dung tích']['axis'])->toBe('volume')
        ->and($color['variant_attribute_changes'][0]['attributes_after']['color'])->toBe('Đỏ')
        ->and($color['product_specification_keys_moving_to_variant']['Màu sắc']['axis'])->toBe('color');
});

test('shared specifications remain Product level', function (): void {
    $plan = planMove(movePlanData());

    expect($plan['product_specification_keys_remaining_shared']['Xuất xứ']['value'])->toBe('Việt Nam')
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->not->toHaveKey('xuat_xu');
});

test('missing specification preserves known values without fabrication', function (): void {
    $plan = planMove(movePlanData([
        2 => ['specifications' => ['Xuất xứ' => 'Việt Nam']],
    ]));

    expect($plan['missing_or_incomplete_values']['Dung tích']['preserve_known_values'])->toBe([1 => '30ml'])
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->not->toHaveKey('volume')
        ->and($plan['variant_attribute_changes'][1]['attributes_after'])->not->toHaveKey('volume');
});

test('duplicate semantic axes require a manual rule', function (): void {
    $plan = planMove(movePlanData([
        1 => ['specifications' => ['Dung tích' => '30g', 'Kích thước' => '30g']],
        2 => ['specifications' => ['Dung tích' => '50g', 'Kích thước' => '50g']],
    ]));

    expect($plan['duplicate_semantic_axes'])->not->toBeEmpty()
        ->and($plan['readiness'])->toBe('needs_manual_rule');
});

test('inconsistent counting units require an explicit rule', function (): void {
    $plan = planMove(movePlanData([
        1 => ['specifications' => ['Dung tích' => '1 cái']],
        2 => ['specifications' => ['Dung tích' => '1 cây']],
    ]));

    expect($plan['ambiguous_units'])->not->toBeEmpty()
        ->and($plan['unresolved_ambiguities'])->toContain('inconsistent_unit_requires_rule')
        ->and($plan['readiness'])->toBe('needs_manual_rule');
});

test('equal Variant attribute aliases form one semantic axis without changing raw attributes', function (): void {
    $plan = planMove(movePlanData(variantOverrides: [
        11 => ['attributes' => ['dung_tich' => '30ml', 'spec_dung_tich' => '30ml']],
        12 => ['attributes' => ['dung_tich' => '50ml', 'spec_dung_tich' => '50ml']],
    ]));

    expect($plan['duplicate_semantic_axes'])->toBeEmpty()
        ->and($plan['equivalent_semantic_aliases'])->toHaveCount(2)
        ->and($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->toBe(['volume' => '30ml'])
        ->and($plan['variant_attribute_changes'][0]['attributes_before'])->toBe([
            'dung_tich' => '30ml',
            'spec_dung_tich' => '30ml',
        ])
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe([
            'dung_tich' => '30ml',
            'spec_dung_tich' => '30ml',
        ])
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0);
});

test('formatting-only differences between explicit aliases normalize to one semantic value', function (): void {
    $plan = planMove(movePlanData(variantOverrides: [
        11 => ['attributes' => ['dung_tich' => '100ml', 'spec_dung_tich' => '100 ml']],
        12 => ['attributes' => ['dung_tich' => '150 ml', 'spec_dung_tich' => '150ml']],
    ]));

    expect($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->toBe(['volume' => '100ml'])
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe([
            'dung_tich' => '100ml',
            'spec_dung_tich' => '100 ml',
        ]);
});

test('conflicting explicit alias values remain blocked', function (): void {
    $plan = planMove(movePlanData(variantOverrides: [
        11 => ['attributes' => ['dung_tich' => '100ml', 'spec_dung_tich' => '150ml']],
        12 => ['attributes' => ['dung_tich' => '200ml', 'spec_dung_tich' => '200 ml']],
    ]));

    expect($plan['duplicate_semantic_axes'])->toHaveCount(1)
        ->and($plan['duplicate_semantic_axes'][0]['reason'])->toBe('conflicting_variant_attribute_aliases')
        ->and($plan['readiness'])->toBe('blocked')
        ->and($plan['reasons'])->toContain('conflicting_variant_attribute_keys_for_axis:11:volume')
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->not->toHaveKey('volume');
});

test('unrelated Variant attributes are not merged into semantic aliases', function (): void {
    $plan = planMove(movePlanData(variantOverrides: [
        11 => ['attributes' => ['dung_tich' => '30ml', 'custom_label' => '30 ml']],
        12 => ['attributes' => ['dung_tich' => '50ml', 'custom_label' => '50 ml']],
    ]));

    expect($plan['equivalent_semantic_aliases'])->toBeEmpty()
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->toBe(['volume' => '30ml'])
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toHaveKey('custom_label', '30 ml');
});

test('varying axes already held by Variants remain visible in the plan', function (): void {
    $plan = planMove(movePlanData(
        [
            1 => ['name' => 'Lược Màu Hồng', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
            2 => ['name' => 'Lược Màu Đen', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        ],
        [
            11 => ['attributes' => ['color' => 'Hồng']],
            12 => ['attributes' => ['color' => 'Đen']],
        ],
    ));

    expect($plan['variant_axes'])->toBe(['color'])
        ->and($plan['proposed_canonical_product_name'])->toBe('Lược')
        ->and($plan['variant_naming'][0]['display_value'])->toBe('Hồng');
});

test('two dimensional size labels preserve both dimensions', function (): void {
    $plan = planMove(movePlanData(
        [
            1 => ['name' => 'Khăn mặt 15x20cm', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
            2 => ['name' => 'Khăn mặt 20x20cm', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        ],
        [
            11 => ['attributes' => ['size' => '15x20cm']],
            12 => ['attributes' => ['size' => '20x20cm']],
        ],
    ));

    expect($plan['variant_naming'][0]['display_value'])->toBe('15x20cm')
        ->and($plan['variant_naming'][1]['display_value'])->toBe('20x20cm');
});

test('safe canonical name removes only confirmed variant tokens', function (): void {
    $plan = planMove(movePlanData());

    expect($plan['proposed_canonical_product_name'])->toBe('Serum phục hồi')
        ->and($plan['variant_naming'][0]['display_value'])->toBe('30ml')
        ->and($plan['variant_naming'][0]['removed_tokens'])->toContain('30ml');
});

test('safe canonical name tolerates spacing differences in confirmed measurement tokens', function (): void {
    $plan = planMove(movePlanData([
        1 => ['name' => 'Sáp tạo kiểu 50g', 'specifications' => ['Dung tích' => '50 g']],
        2 => ['name' => 'Sáp tạo kiểu 100g', 'specifications' => ['Dung tích' => '100g']],
    ]));

    expect($plan['proposed_canonical_product_name'])->toBe('Sáp tạo kiểu')
        ->and($plan['variant_naming'][0]['display_value'])->toBe('50g');
});

test('ambiguous canonical names are not chosen', function (): void {
    $plan = planMove(movePlanData([
        1 => ['name' => 'Serum phục hồi 30ml'],
        2 => ['name' => 'Kem chống nắng 50ml'],
    ]));

    expect($plan['proposed_canonical_product_name'])->toBeNull()
        ->and($plan['unresolved_ambiguities'])->toContain('canonical_name_requires_rule')
        ->and($plan['readiness'])->toBe('needs_manual_rule');
});

test('images map to their authoritative source Variants', function (): void {
    $plan = planMove(movePlanData(images: [
        ['id' => 101, 'product_id' => 1, 'source_product_id' => 1, 'product_variant_id' => null, 'image_url' => '/1.jpg', 'is_primary' => true, 'sort_order' => 0],
        ['id' => 102, 'product_id' => 2, 'source_product_id' => 2, 'product_variant_id' => null, 'image_url' => '/2.jpg', 'is_primary' => true, 'sort_order' => 0],
    ]));

    expect($plan['image_attribution_plan'][0]['intended_product_variant_id'])->toBe(11)
        ->and($plan['image_attribution_plan'][1]['intended_product_variant_id'])->toBe(12)
        ->and($plan['t1_3_image_attribution_compatible'])->toBeTrue()
        ->and($plan['metrics']['image_rows_requiring_attribution'])->toBe(2);
});

test('multiple primary images for one target Variant block the group', function (): void {
    $plan = planMove(movePlanData(images: [
        ['id' => 101, 'product_id' => 1, 'source_product_id' => 1, 'product_variant_id' => null, 'image_url' => '/1.jpg', 'is_primary' => true, 'sort_order' => 0],
        ['id' => 102, 'product_id' => 1, 'source_product_id' => 1, 'product_variant_id' => null, 'image_url' => '/2.jpg', 'is_primary' => true, 'sort_order' => 1],
    ]));

    expect($plan['readiness'])->toBe('blocked')
        ->and($plan['reasons'])->toContain('multiple_primary_images_for_variant:11');
});

test('planning output is deterministic for reordered inputs', function (): void {
    $data = movePlanData(images: [
        ['id' => 101, 'product_id' => 1, 'source_product_id' => 1, 'product_variant_id' => null, 'image_url' => '/1.jpg', 'is_primary' => true, 'sort_order' => 0],
    ]);
    $reversed = $data;
    $reversed['products'] = array_reverse($reversed['products']);
    $reversed['variants'] = array_reverse($reversed['variants']);
    $reversed['images'] = array_reverse($reversed['images']);

    expect(planMove($data))->toBe(planMove($reversed));
});

test('T1.12B package naming preserves shared family text and complete Variant quantities', function (
    array $names,
    array $quantities,
    string $canonical,
): void {
    $data = movePlanData(
        [
            1 => ['name' => $names[0], 'specifications' => ['Dung tích' => $quantities[0]]],
            2 => ['name' => $names[1], 'specifications' => ['Dung tích' => $quantities[1]]],
        ],
        [
            11 => ['name' => $quantities[0], 'attributes' => ['dung_tich' => $quantities[0], 'spec_dung_tich' => $quantities[0]]],
            12 => ['name' => $quantities[1], 'attributes' => ['dung_tich' => $quantities[1], 'spec_dung_tich' => $quantities[1]]],
        ],
    );
    $before = $data;
    $plan = planMove($data);
    $reordered = $data;
    $reordered['products'] = array_reverse($data['products']);
    $reordered['variants'] = array_reverse($data['variants']);

    expect($plan['proposed_canonical_product_name'])->toBe($canonical)
        ->and($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['variant_naming'][0]['display_value'])->toBe($quantities[0])
        ->and($plan['variant_naming'][1]['display_value'])->toBe($quantities[1])
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0)
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe($plan['variant_attribute_changes'][0]['attributes_before'])
        ->and($data)->toBe($before)
        ->and(planMove($reordered))->toBe($plan);
})->with([
    'Pantene labeled components' => [
        [
            'Bộ Gội Xả Pantene Ngăn Rụng Tóc (Dầu Gội 650ml + Dầu Xả Siêu Dưỡng 300ml)',
            'Bộ Gội Xả Pantene Ngăn Rụng Tóc (Dầu Gội 900ml + Dầu Xả Siêu Dưỡng 150ml)',
        ],
        ['650ml + 300ml', '900ml + 150ml'],
        'Bộ Gội Xả Pantene Ngăn Rụng Tóc (Dầu Gội + Dầu Xả Siêu Dưỡng)',
    ],
    "L'Oreal all quantities are Variant level" => [
        [
            "Bộ Gội Xả L'Oreal Dưỡng Tóc Suôn Mượt Tóc Cao Cấp 440mlx2",
            "Bộ Gội Xả L'Oreal Dưỡng Tóc Suôn Mượt Tóc Cao Cấp 440mlx2+100ml",
        ],
        ['2x440ml', '440mlx2 + 100ml'],
        "Bộ Gội Xả L'Oreal Dưỡng Tóc Suôn Mượt Tóc Cao Cấp",
    ],
]);

test('T1.12B corroborated volume resolution never changes raw aliases', function (string $measurement, string $alias): void {
    $raw = ['dung_tich' => $measurement, 'spec_dung_tich' => $alias];
    $data = movePlanData(
        [
            1 => ['name' => 'Xịt khoáng '.$measurement, 'specifications' => ['Dung tích' => $alias]],
            2 => ['name' => 'Xịt khoáng 150ml', 'specifications' => ['Dung tích' => '150ml']],
        ],
        [
            11 => ['name' => $measurement, 'attributes' => $raw],
            12 => ['name' => '150ml', 'attributes' => ['dung_tich' => '150ml', 'spec_dung_tich' => '150ml']],
        ],
    );
    $before = $data;
    $plan = planMove($data);

    expect($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->toBe(['volume' => $measurement])
        ->and($plan['variant_attribute_changes'][0]['attributes_before'])->toBe($raw)
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe($raw)
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0)
        ->and($data)->toBe($before)
        ->and(planMove($data))->toBe($plan);
})->with([
    '300ml versus bare 10' => ['300ml', '10'],
    '400ml versus bare 27' => ['400ml', '27'],
    '300g versus bare 10 without conversion' => ['300g', '10'],
    'equal 50ml aliases' => ['50ml', '50ml'],
]);

test('T1.12B unsafe alias conflicts stay blocked', function (
    string $value,
    string $alias,
    string $productName,
    string $variantName,
): void {
    $raw = ['dung_tich' => $value, 'spec_dung_tich' => $alias];
    $plan = planMove(movePlanData(
        [1 => ['name' => $productName, 'specifications' => ['Dung tích' => $alias]]],
        [11 => ['name' => $variantName, 'attributes' => $raw]],
    ));

    expect($plan['readiness'])->toBe('blocked')
        ->and($plan['variant_attribute_changes'][0]['normalized_semantic_attributes'])->not->toHaveKey('volume')
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe($raw)
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(1);
})->with([
    'two unit-bearing values' => ['300ml', '150ml', 'Xịt khoáng 300ml', '300ml'],
    'bare versus bare' => ['10', '27', 'Xịt khoáng 10', '10'],
    'missing Product corroboration' => ['300ml', '10', 'Xịt khoáng bản lớn', '300ml'],
    'missing Variant corroboration' => ['300ml', '10', 'Xịt khoáng 300ml', 'Bản lớn'],
    'different units are not converted' => ['300g', '300ml', 'Xịt khoáng 300g', '300g'],
]);

test('Carslan audit exception uses the exact approved canonical name', function (): void {
    $data = movePlanData(
        [
            1 => [
                'name' => 'Phấn Phủ Carslan Dạng Nén Bản Thường Màu Tím 8g',
                'specifications' => ['Màu sắc' => 'Tím', 'Dung tích' => '8g'],
            ],
            2 => [
                'name' => 'Phấn Phủ Carslan Dạng Nén Bản Thường Màu Hồng 8g',
                'specifications' => ['Màu sắc' => 'Hồng', 'Dung tích' => '8g'],
            ],
        ],
        [
            11 => ['name' => 'Tím / Phiên Bản Thường', 'attributes' => ['color' => 'Tím', 'spec_dung_tich' => '8g']],
            12 => ['name' => 'Hồng / Phiên Bản Thường', 'attributes' => ['color' => 'Hồng', 'spec_dung_tich' => '8g']],
        ],
    );
    $before = $data;
    $plan = planMove($data, movePlanGroup(['group_identifier' => 'pvg-13ee293c047c417a']));

    expect($plan['proposed_canonical_product_name'])->toBe('Phấn Phủ Carslan Dạng Nén Bản Thường 8g')
        ->and($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0)
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe($plan['variant_attribute_changes'][0]['attributes_before'])
        ->and($data)->toBe($before);
});

test('Carslan canonical exception does not apply to another name set', function (): void {
    $data = movePlanData([
        1 => ['name' => 'Phấn Phủ Carslan Dòng Khác Màu Tím 8g', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        2 => ['name' => 'Phấn Phủ Carslan Dòng Khác Màu Hồng 8g', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
    ]);

    $plan = planMove($data, movePlanGroup(['group_identifier' => 'pvg-13ee293c047c417a']));

    expect($plan['proposed_canonical_product_name'])->toBeNull()
        ->and($plan['readiness'])->toBe('needs_manual_rule');
});

test('Hotosu audit exception treats one cai and one cay as the same count without raw writes', function (): void {
    $rawOne = ['color' => 'Đen', 'spec_dung_tich' => '1 cái'];
    $rawTwo = ['color' => 'Trắng', 'spec_dung_tich' => '1 cây'];
    $data = movePlanData(
        [
            1 => [
                'name' => 'Chà Gót Chân Hotosu Đa Chiều Cao Cấp Màu Đen',
                'specifications' => ['Dung tích' => '1 cái'],
            ],
            2 => [
                'name' => 'Chà Gót Chân Hotosu Đa Chiều Cao Cấp Màu Trắng',
                'specifications' => ['Dung tích' => '1 cây'],
            ],
        ],
        [
            11 => ['name' => 'Đen', 'attributes' => $rawOne],
            12 => ['name' => 'Trắng', 'attributes' => $rawTwo],
        ],
    );
    $before = $data;
    $plan = planMove($data, movePlanGroup(['group_identifier' => 'pvg-9b8a55eed1089cc8']));

    expect($plan['readiness'])->toBe('ready_for_variant_content_apply')
        ->and($plan['ambiguous_units'])->toBe([])
        ->and($plan['variant_axes'])->toBe(['color'])
        ->and($plan['product_specification_keys_remaining_shared']['Dung tích']['audit_resolution'])->toBe('group_specific_equivalent_count_unit')
        ->and($plan['variant_attribute_changes'][0]['attributes_before'])->toBe($rawOne)
        ->and($plan['variant_attribute_changes'][0]['attributes_after'])->toBe($rawOne)
        ->and($plan['variant_attribute_changes'][1]['attributes_before'])->toBe($rawTwo)
        ->and($plan['variant_attribute_changes'][1]['attributes_after'])->toBe($rawTwo)
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0)
        ->and($data)->toBe($before);
});

test('one cai and one cay remain ambiguous outside the Hotosu group', function (): void {
    $plan = planMove(movePlanData([
        1 => ['specifications' => ['Dung tích' => '1 cái']],
        2 => ['specifications' => ['Dung tích' => '1 cây']],
    ]));

    expect($plan['readiness'])->toBe('needs_manual_rule')
        ->and($plan['ambiguous_units'])->not->toBeEmpty();
});

test('exact audited canonical name overrides are applied without Variant writes', function (
    int $productId,
    string $currentName,
    string $suggestedName,
): void {
    $data = movePlanData([
        1 => ['name' => $currentName, 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        2 => ['name' => $currentName, 'specifications' => ['Xuất xứ' => 'Việt Nam']],
    ]);
    $data['products'][0]['id'] = $productId;
    $data['products'][0]['external_id'] = 'product-'.$productId;
    $data['variants'][0]['product_id'] = $productId;
    $data['variants'][0]['source_product_id'] = $productId;
    $data['variants'][0]['external_id'] = 'product-'.$productId;
    $group = movePlanGroup([
        'candidate_product_ids' => [$productId, 2],
        'candidate_variant_ids' => [11, 12],
        'canonical_recommendation' => $productId,
        'per_field_classification' => [],
    ]);
    $before = $data;
    $plan = planMove($data, $group);
    $reordered = $data;
    $reordered['products'] = array_reverse($reordered['products']);
    $reordered['variants'] = array_reverse($reordered['variants']);

    expect($plan['proposed_canonical_product_name'])->toBe($suggestedName)
        ->and($plan['metrics']['variant_attribute_writes_proposed'])->toBe(0)
        ->and($plan['variant_attribute_changes'][0]['attributes_before'])->toBe($plan['variant_attribute_changes'][0]['attributes_after'])
        ->and($plan['variant_attribute_changes'][1]['attributes_before'])->toBe($plan['variant_attribute_changes'][1]['attributes_after'])
        ->and($data)->toBe($before)
        ->and(planMove($reordered, $group))->toBe($plan);
})->with([
    'Arrahan' => [45, 'Gel Tẩy Tế Bào Chết Arrahan Hương 180ml', 'Gel Tẩy Tế Bào Chết Arrahan 180ml'],
    'Calla deep clean' => [537, 'Bông Tẩy Trang Bông Bạch Tuyết Calla Giúp Sạch Sâu Túi', 'Bông Tẩy Trang Bông Bạch Tuyết Calla Giúp Sạch Sâu'],
    'Calla soft' => [541, 'Bông Tẩy Trang Bông Bạch Tuyết Calla Mềm Mịn Túi', 'Bông Tẩy Trang Bông Bạch Tuyết Calla Mềm Mịn'],
    'Maybelline' => [645, 'Kem Nền Maybelline Bắt Sáng Che Phủ Siêu Nhẹ # 35ml', 'Kem Nền Maybelline Bắt Sáng Che Phủ Siêu Nhẹ 35ml'],
    'Laneige' => [693, 'Phấn Nước Laneige Cho Lớp Nền Mịn Lì 50H # 15g (Mới)', 'Phấn Nước Laneige Cho Lớp Nền Mịn Lì 50H 15g (Mới)'],
    'Australis' => [854, 'Phấn Phủ Australis Kiềm Dầu 2in1 # 12g', 'Phấn Phủ Australis Kiềm Dầu 2in1 12g'],
    'Menitems' => [1565, 'Sáp Tạo Kiểu Menitems Clay Wax - 45g', 'Sáp Tạo Kiểu Menitems Clay Wax 45g'],
    'Hotosu' => [1584, 'Lược Gội Đầu Hotosu Massage Cao Cấp ( )', 'Lược Gội Đầu Hotosu Massage Cao Cấp'],
    'Old Spice' => [1842, 'Sáp Khử Mùi Old Spice Giảm Tiết Mồ Hôi Hương 73g', 'Sáp Khử Mùi Old Spice Giảm Tiết Mồ Hôi 73g'],
    'Remos' => [2082, 'Kem Chống Muỗi Rohto Remos Hương 70g', 'Kem Chống Muỗi Rohto Remos 70g'],
]);

test('canonical name override requires exact current name and audited Product identity', function (): void {
    $nearMatch = movePlanData([
        1 => ['name' => 'Gel Tẩy Tế Bào Chết Arrahan Hương 180ml!', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        2 => ['name' => 'Gel Tẩy Tế Bào Chết Arrahan Hương 180ml!', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
    ]);
    $nearMatch['products'][0]['id'] = 45;
    $nearMatch['variants'][0]['product_id'] = 45;
    $nearMatch['variants'][0]['source_product_id'] = 45;
    $nearGroup = movePlanGroup([
        'candidate_product_ids' => [45, 2],
        'candidate_variant_ids' => [11, 12],
        'canonical_recommendation' => 45,
        'per_field_classification' => [],
    ]);
    $unrelated = movePlanData([
        1 => ['name' => 'Gel Tẩy Tế Bào Chết Arrahan Hương 180ml', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
        2 => ['name' => 'Gel Tẩy Tế Bào Chết Arrahan Hương 180ml', 'specifications' => ['Xuất xứ' => 'Việt Nam']],
    ]);

    expect(planMove($nearMatch, $nearGroup)['proposed_canonical_product_name'])
        ->toBe('Gel Tẩy Tế Bào Chết Arrahan Hương 180ml!')
        ->and(planMove($unrelated)['proposed_canonical_product_name'])
        ->toBe('Gel Tẩy Tế Bào Chết Arrahan Hương 180ml');
});
