<?php

use App\Support\Import\ProductVariantContentReconciler;

function reconciliationGroup(array $overrides = []): array
{
    return array_replace_recursive([
        'group_identifier' => 'pvg-test-content',
        'classification' => 'auto_safe',
        'candidate_product_ids' => [1, 2],
        'candidate_variant_ids' => [11, 12],
        'recommended_canonical_product_id' => 1,
        'source_external_ids' => [
            ['product_id' => 1, 'source' => 'hasaki', 'external_id' => '1001'],
            ['product_id' => 2, 'source' => 'hasaki', 'external_id' => '1002'],
        ],
        'detected_variant_dimensions' => [
            'values' => ['volume' => ['30ml', '50ml']],
        ],
    ], $overrides);
}

function reconciliationProduct(int $id, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'source' => 'hasaki',
        'external_id' => (string) (1000 + $id),
        'name' => 'Serum phục hồi',
        'short_description' => '<p>Dịu nhẹ cho da</p>',
        'description' => '<p>Hỗ trợ phục hồi da</p>',
        'ingredients' => 'Hyaluronic acid',
        'usage_instructions' => 'Dùng mỗi tối',
        'specifications' => ['Xuất xứ' => 'Việt Nam'],
        'origin_country' => 'Việt Nam',
        'category_id' => 10,
        'brand_id' => 20,
        'external_rating' => '4.50',
        'external_review_count' => 10,
        'is_active' => true,
        'source_variant_groups' => [],
        'deleted_at' => null,
    ], $overrides);
}

function reconciliationVariant(int $id, int $productId, string $volume): array
{
    return [
        'id' => $id,
        'product_id' => $productId,
        'source_product_id' => $productId,
        'source' => 'hasaki',
        'external_id' => (string) (1000 + $productId),
        'sku' => 'HS-'.$id,
        'barcode' => '8930000000'.$id,
        'weight' => 500,
        'attributes' => ['dung_tich' => $volume],
        'deleted_at' => null,
    ];
}

function reconciliationData(array $productOverrides = [], array $extra = []): array
{
    return array_replace([
        'products' => [
            reconciliationProduct(1, $productOverrides[1] ?? []),
            reconciliationProduct(2, $productOverrides[2] ?? []),
        ],
        'variants' => [
            reconciliationVariant(11, 1, '30ml'),
            reconciliationVariant(12, 2, '50ml'),
        ],
        'images' => [],
        'reviews' => [],
        'questions' => [],
        'favorites' => [],
    ], $extra);
}

function reconcileContent(array $data, ?array $group = null): array
{
    return (new ProductVariantContentReconciler)->reconcile($group ?? reconciliationGroup(), $data);
}

function normalizedReconciliationData(array $overrides = []): array
{
    $data = reconciliationData([
        1 => ['name' => 'Serum phuc hoi'],
        2 => [
            'is_active' => false,
            'deleted_at' => '2026-09-11T10:00:00+07:00',
            'source_variant_groups' => [
                '_mizuki_variant_normalization' => [
                    'group_identifier' => 'pvg-test-content',
                    'retired_product_id' => 2,
                    'canonical_product_id' => 1,
                ],
            ],
        ],
    ]);
    $data['variants'][0]['product_id'] = 1;
    $data['variants'][1]['product_id'] = 1;

    return array_replace_recursive($data, $overrides);
}

test('an actually normalized group is reported as already normalized', function (): void {
    $result = reconcileContent(normalizedReconciliationData());

    expect($result['recommended_merge_decision'])->toBe('already_normalized')
        ->and($result['normalization_state']['is_already_normalized'])->toBeTrue()
        ->and($result['normalization_state']['failed_checks'])->toBe([])
        ->and($result['reasons'])->toBe(['authoritative_post_normalization_invariants_hold']);
});

test('an approved canonical rename does not become a critical name conflict', function (): void {
    $data = normalizedReconciliationData();
    $data['products'][0]['name'] = 'Approved canonical family name';

    $result = reconcileContent(
        $data,
        reconciliationGroup(['recommended_canonical_product_id' => null]),
    );

    expect($result['recommended_merge_decision'])->toBe('already_normalized')
        ->and($result['canonical_recommendation'])->toBe(1)
        ->and($result['semantic_conflicts'])->toBe([])
        ->and($result['reasons'])->not->toContain('critical_semantic_conflict:name');
});

test('soft deletion and the exact retirement marker are required', function (): void {
    $missingMarker = normalizedReconciliationData();
    $missingMarker['products'][1]['source_variant_groups'] = [];
    $notDeleted = normalizedReconciliationData();
    $notDeleted['products'][1]['deleted_at'] = null;

    $missingMarkerResult = reconcileContent($missingMarker);
    $notDeletedResult = reconcileContent($notDeleted);

    expect($missingMarkerResult['recommended_merge_decision'])->not->toBe('already_normalized')
        ->and($missingMarkerResult['normalization_state']['failed_checks'])->toContain('retirement_markers_are_intact')
        ->and($notDeletedResult['recommended_merge_decision'])->not->toBe('already_normalized')
        ->and($notDeletedResult['normalization_state']['failed_checks'])->toContain('duplicate_products_are_retired');
});

test('partially normalized variant ownership is not treated as normalized', function (): void {
    $data = normalizedReconciliationData();
    $data['variants'][1]['product_id'] = 2;

    $result = reconcileContent($data);

    expect($result['recommended_merge_decision'])->not->toBe('already_normalized')
        ->and($result['normalization_state']['failed_checks'])->toContain('all_variants_on_canonical_product');
});

test('ordinary semantic conflicts remain do not merge', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['ingredients' => 'Hyaluronic acid'],
        2 => ['ingredients' => 'Retinol'],
    ]));

    expect($result['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($result['normalization_state']['is_already_normalized'])->toBeFalse();
});

test('already normalized output is deterministic', function (): void {
    $data = normalizedReconciliationData();
    $reversed = $data;
    $reversed['products'] = array_reverse($reversed['products']);
    $reversed['variants'] = array_reverse($reversed['variants']);

    expect(reconcileContent($data))->toBe(reconcileContent($reversed));
});

test('exact shared product content is classified shared exact', function (): void {
    $result = reconcileContent(reconciliationData());

    expect($result['per_field_classification']['ingredients']['classification'])->toBe('shared_exact')
        ->and($result['per_field_classification']['usage_instructions']['classification'])->toBe('shared_exact')
        ->and($result['shared_content_candidate'])->toHaveKeys(['ingredients', 'usage_instructions']);
});

test('formatting html whitespace and punctuation noise is classified shared normalized', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['short_description' => '<p>Dịu nhẹ cho da!</p>'],
        2 => ['short_description' => '  dịu nhẹ, cho da  '],
    ]));

    expect($result['per_field_classification']['short_description']['classification'])->toBe('shared_normalized');
});

test('clear variant specification differences are reported as variant specific', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['specifications' => ['Dung tích' => '30ml']],
        2 => ['specifications' => ['Dung tích' => '50ml']],
    ]));

    expect($result['per_field_classification']['specifications']['classification'])->toBe('variant_specific')
        ->and($result['specification_diff']['variant_specific_keys']['Dung tích']['dimension'])->toBe('volume')
        ->and($result['specification_diff']['variant_specific_keys']['Dung tích']['backed_by_variant_attribute'])->toBeTrue();
});

test('other varying variant attribute keys are reported explicitly', function (): void {
    $data = reconciliationData();
    $data['variants'][0]['attributes']['finish'] = 'matte';
    $data['variants'][1]['attributes']['finish'] = 'glossy';

    $result = reconcileContent($data);

    expect($result['variant_attribute_inspection']['other_varying_specification_keys']['finish'])->toBe([
        1 => ['matte'],
        2 => ['glossy'],
    ]);
});

test('ingredient conflict blocks merging', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['ingredients' => 'Hyaluronic acid'],
        2 => ['ingredients' => 'Retinol'],
    ]));

    expect($result['per_field_classification']['ingredients']['classification'])->toBe('semantic_conflict')
        ->and($result['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($result['reasons'])->toContain('critical_semantic_conflict:ingredients');
});

test('usage conflict blocks merging', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['usage_instructions' => 'Rửa lại sau 10 phút'],
        2 => ['usage_instructions' => 'Để qua đêm'],
    ]));

    expect($result['per_field_classification']['usage_instructions']['classification'])->toBe('semantic_conflict')
        ->and($result['recommended_merge_decision'])->toBe('do_not_merge');
});

test('missing content on one member is incomplete rather than conflicting', function (): void {
    $result = reconcileContent(reconciliationData([
        2 => ['ingredients' => null],
    ]));

    expect($result['per_field_classification']['ingredients']['classification'])->toBe('missing_or_incomplete')
        ->and($result['per_field_classification']['ingredients']['missing_state'])->toBe('partial')
        ->and($result['semantic_conflicts'])->not->toHaveKey('ingredients')
        ->and($result['recommended_merge_decision'])->toBe('manual_review');
});

test('audited minor copy difference no longer blocks the approved group', function (): void {
    $result = reconcileContent(
        reconciliationData([
            1 => ['description' => '<p>Thông tin sản phẩm chung.</p><p>Lưu ý nguồn bán hàng.</p>'],
            2 => ['description' => '<p>Thông tin sản phẩm chung.</p>'],
        ]),
        reconciliationGroup(['group_identifier' => 'pvg-256d679626f0d710']),
    );

    expect($result['per_field_classification']['description']['classification'])->toBe('shared_normalized')
        ->and($result['per_field_classification']['description']['audit_resolution'])->toBe('same_meaning_minor_copy_difference')
        ->and($result['recommended_merge_decision'])->not->toBe('manual_review');
});

test('audited variant quantity content is retained as variant specific', function (): void {
    $result = reconcileContent(
        reconciliationData([
            1 => ['short_description' => '100 Viên/Chai'],
            2 => ['short_description' => '60 Viên/Chai'],
        ]),
        reconciliationGroup(['group_identifier' => 'pvg-452148f507f89953']),
    );

    expect($result['per_field_classification']['short_description']['classification'])->toBe('variant_specific')
        ->and($result['per_field_classification']['short_description']['audit_resolution'])->toBe('variant_quantity_only')
        ->and($result['semantic_conflicts'])->not->toHaveKey('short_description');
});

test('audited missing origin is safe only when all present members agree', function (): void {
    $group = reconciliationGroup(['group_identifier' => 'pvg-f3a7f13bfa235171']);
    $consistent = reconcileContent(reconciliationData([
        1 => ['origin_country' => 'Việt Nam'],
        2 => ['origin_country' => null],
    ]), $group);
    $conflictingData = reconciliationData([
        1 => ['origin_country' => 'Việt Nam'],
        2 => ['origin_country' => null],
    ]);
    $conflictingData['products'][] = reconciliationProduct(3, ['origin_country' => 'Nhật Bản']);
    $conflicting = reconcileContent($conflictingData, $group);

    expect($consistent['per_field_classification']['origin_country']['classification'])->toBe('shared_normalized')
        ->and($consistent['per_field_classification']['origin_country']['audit_resolution'])->toBe('missing_member_consistent')
        ->and($conflicting['per_field_classification']['origin_country']['classification'])->toBe('missing_or_incomplete');
});

test('the two substantive audit exceptions always remain do not merge', function (string $groupIdentifier): void {
    $result = reconcileContent(
        reconciliationData(),
        reconciliationGroup(['group_identifier' => $groupIdentifier]),
    );

    expect($result['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($result['reasons'])->toBe(['audited_substantive_content_difference']);
})->with([
    'different Vacosi brush composition' => 'pvg-6851845782b6d22f',
    'unattributed Sunplay Eco version' => 'pvg-f84ae25842e2ca40',
]);

test('an audited content resolution never overrides a critical semantic conflict', function (): void {
    $result = reconcileContent(
        reconciliationData([
            1 => ['description' => 'Nguồn có thêm disclaimer', 'ingredients' => 'Hyaluronic acid'],
            2 => ['description' => 'Nội dung chung', 'ingredients' => 'Retinol'],
        ]),
        reconciliationGroup(['group_identifier' => 'pvg-256d679626f0d710']),
    );

    expect($result['per_field_classification']['description']['classification'])->toBe('shared_normalized')
        ->and($result['per_field_classification']['ingredients']['classification'])->toBe('semantic_conflict')
        ->and($result['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($result['reasons'])->toContain('critical_semantic_conflict:ingredients');
});

test('content missing from every member does not create a false merge conflict', function (): void {
    $result = reconcileContent(reconciliationData([
        1 => ['ingredients' => null, 'external_rating' => null],
        2 => ['ingredients' => null, 'external_rating' => null],
    ]));

    expect($result['per_field_classification']['ingredients']['missing_state'])->toBe('all_missing')
        ->and($result['per_field_classification']['external_rating']['missing_state'])->toBe('all_missing')
        ->and($result['reasons'])->not->toContain('incomplete_critical_content:ingredients')
        ->and($result['reasons'])->not->toContain('external_aggregate_business_decision:external_rating');
});

test('review question and favorite ownership conflicts are reported without transfer', function (): void {
    $result = reconcileContent(reconciliationData([], [
        'reviews' => [
            ['id' => 1, 'product_id' => 1, 'user_id' => 90],
            ['id' => 2, 'product_id' => 2, 'user_id' => 90],
        ],
        'questions' => [
            ['id' => 1, 'product_id' => 1, 'source' => 'hasaki', 'external_key' => 'q-1'],
            ['id' => 2, 'product_id' => 2, 'source' => 'hasaki', 'external_key' => 'q-1'],
        ],
        'favorites' => [
            ['id' => 1, 'product_id' => 1, 'user_id' => 91],
            ['id' => 2, 'product_id' => 2, 'user_id' => 91],
        ],
    ]));

    expect($result['operational_content']['counts_by_product'][1])->toBe([
        'reviews' => 1,
        'questions' => 1,
        'favorites' => 1,
    ])->and($result['operational_content']['ownership_conflicts'])->toBe([
        'review_user_ids' => [90],
        'favorite_user_ids' => [91],
        'question_source_keys' => ['hasaki|q-1'],
    ])->and($result['operational_content']['has_ownership_conflicts'])->toBeTrue()
        ->and($result['recommended_merge_decision'])->toBe('manual_review');
});

test('resolved historical manual review is re-evaluated from current content state', function (): void {
    $result = reconcileContent(
        reconciliationData(),
        reconciliationGroup(['classification' => 'manual_review']),
    );

    expect($result['source_manifest_classification'])->toBe('manual_review')
        ->and($result['recommended_merge_decision'])->toBe('merge_ready')
        ->and($result['reasons'])->toBe(['content_is_shared_or_already_variant_attributed'])
        ->and($result['reasons'])->not->toContain('t1_1_manual_review_required');
});

test('historical manual review with a current ownership conflict remains manual', function (): void {
    $result = reconcileContent(
        reconciliationData([], [
            'reviews' => [
                ['id' => 1, 'product_id' => 1, 'user_id' => 90],
                ['id' => 2, 'product_id' => 2, 'user_id' => 90],
            ],
        ]),
        reconciliationGroup(['classification' => 'manual_review']),
    );

    expect($result['recommended_merge_decision'])->toBe('manual_review')
        ->and($result['reasons'])->toContain('review_question_or_favorite_ownership_conflict')
        ->and($result['reasons'])->not->toContain('t1_1_manual_review_required');
});

test('historical manual review with a real semantic conflict remains blocked', function (): void {
    $result = reconcileContent(
        reconciliationData([
            1 => ['ingredients' => 'Hyaluronic acid'],
            2 => ['ingredients' => 'Retinol'],
        ]),
        reconciliationGroup(['classification' => 'manual_review']),
    );

    expect($result['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($result['reasons'])->toContain('critical_semantic_conflict:ingredients');
});

test('rejected T1.1 group and historical hard failures stay rejected', function (): void {
    $rejected = reconcileContent(
        reconciliationData(),
        reconciliationGroup(['classification' => 'rejected']),
    );
    $failedGate = reconcileContent(
        reconciliationData(),
        reconciliationGroup([
            'classification' => 'manual_review',
            'failed_hard_gates' => ['same_brand'],
        ]),
    );

    expect($rejected['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($rejected['reasons'])->toContain('t1_1_hard_gate_rejected')
        ->and($failedGate['recommended_merge_decision'])->toBe('do_not_merge')
        ->and($failedGate['reasons'])->toContain('t1_1_hard_gate_failed:same_brand');
});

test('reconciliation output is deterministic regardless of row input order', function (): void {
    $data = reconciliationData([], [
        'images' => [
            ['id' => 101, 'product_id' => 1, 'source_product_id' => 1, 'product_variant_id' => 11, 'image_url' => '/one.jpg', 'sort_order' => 0, 'is_primary' => true],
            ['id' => 102, 'product_id' => 2, 'source_product_id' => 2, 'product_variant_id' => 12, 'image_url' => '/two.jpg', 'sort_order' => 0, 'is_primary' => true],
        ],
    ]);
    $reversed = $data;
    $reversed['products'] = array_reverse($reversed['products']);
    $reversed['variants'] = array_reverse($reversed['variants']);
    $reversed['images'] = array_reverse($reversed['images']);

    expect(reconcileContent($data))->toBe(reconcileContent($reversed));
});
