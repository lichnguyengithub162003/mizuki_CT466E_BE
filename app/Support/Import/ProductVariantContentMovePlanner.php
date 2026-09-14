<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

final class ProductVariantContentMovePlanner
{
    /** @var array<string, list<string>> */
    private const AXIS_ALIASES = [
        'volume' => ['dung_tich', 'the_tich', 'volume', 'capacity'],
        'weight' => ['trong_luong', 'khoi_luong', 'weight'],
        'size' => ['kich_thuoc', 'size'],
        'color' => ['mau', 'mau_sac', 'color', 'tone', 'shade'],
        'scent' => ['mui_huong', 'huong', 'scent', 'fragrance'],
        'type' => ['loai', 'phan_loai', 'dang', 'type'],
        'barcode' => ['barcode', 'ma_vach'],
    ];

    /** @var list<string> */
    private const FAMILY_FIELDS = [
        'short_description',
        'description',
        'ingredients',
        'usage_instructions',
        'origin_country',
        'brand_id',
        'category_id',
    ];

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function plan(array $group, array $data): array
    {
        $productIds = $this->integerList($group['candidate_product_ids'] ?? []);
        $variantIds = $this->integerList($group['candidate_variant_ids'] ?? []);
        $products = $this->keyById($data['products'] ?? []);
        $variants = $this->keyById($data['variants'] ?? []);
        $canonicalId = $this->canonicalProductId($group, $productIds);
        $stale = $this->staleStateFailures($group, $data, $productIds, $variantIds, $products, $variants);
        $variantByProduct = $this->authoritativeVariants($productIds, $variantIds, $variants, $stale);
        $specifications = $this->specificationPlan($group, $productIds, $products, $variantByProduct);
        $attributes = $this->attributePlans($productIds, $products, $variantByProduct, $specifications);
        $naming = $this->namingPlan($group, $productIds, $products, $variantByProduct, $specifications, $canonicalId);
        $images = $this->imagePlan($data['images'] ?? [], $productIds, $variantByProduct, $canonicalId);
        $sharedFields = $this->sharedFamilyFields($group, $productIds, $products);
        $engagement = $this->engagementState($data, $productIds, $canonicalId);

        $blockers = array_values(array_unique(array_merge(
            $stale,
            $specifications['semantic_conflicts'] === [] ? [] : ['semantic_specification_conflict'],
            $attributes['blockers'],
            $images['blockers'],
        )));
        sort($blockers, SORT_STRING);
        $manualRules = array_values(array_unique(array_merge(
            $specifications['duplicate_semantic_axes'] === [] ? [] : ['duplicate_semantic_axis_requires_rule'],
            $specifications['ambiguous_units'] === [] ? [] : ['inconsistent_unit_requires_rule'],
            $naming['status'] === 'needs_manual_rule' ? ['canonical_name_requires_rule'] : [],
        )));
        sort($manualRules, SORT_STRING);

        $readiness = match (true) {
            $blockers !== [] => 'blocked',
            $manualRules !== [] => 'needs_manual_rule',
            default => 'ready_for_variant_content_apply',
        };

        return [
            'group_identifier' => (string) $group['group_identifier'],
            'candidate_product_ids' => $productIds,
            'candidate_variant_ids' => $variantIds,
            'source_external_ids' => $this->sourceIdentities($productIds, $products),
            'proposed_canonical_product_id' => $canonicalId,
            'existing_product_names' => $this->productNames($productIds, $products),
            'proposed_canonical_product_name' => $naming['canonical_name'],
            'variant_naming' => $naming['variants'],
            'variant_axes' => $specifications['variant_axes'],
            'shared_product_fields' => $sharedFields,
            'product_specification_keys_remaining_shared' => $specifications['shared'],
            'product_specification_keys_moving_to_variant' => $specifications['variant_specific'],
            'variant_attribute_changes' => $attributes['variants'],
            'missing_or_incomplete_values' => $specifications['missing_or_incomplete'],
            'semantic_conflicts' => $specifications['semantic_conflicts'],
            'duplicate_semantic_axes' => $specifications['duplicate_semantic_axes'],
            'equivalent_semantic_aliases' => $specifications['equivalent_semantic_aliases'],
            'ambiguous_units' => $specifications['ambiguous_units'],
            'image_attribution_plan' => $images['images'],
            't1_3_image_attribution_compatible' => $images['t1_3_compatible'],
            'remaining_engagement_state' => $engagement,
            'unresolved_ambiguities' => $manualRules,
            'stale_state_failures' => $stale,
            'readiness' => $readiness,
            'reasons' => array_values(array_unique(array_merge($blockers, $manualRules))),
            'metrics' => [
                'variants_affected' => count($attributes['variants']),
                'variant_attribute_writes_proposed' => $attributes['writes'],
                'image_rows_requiring_attribution' => $images['writes'],
            ],
        ];
    }

    /** @param array<string, mixed> $group @param list<int> $productIds */
    private function canonicalProductId(array $group, array $productIds): int
    {
        $recommended = (int) ($group['canonical_recommendation'] ?? 0);

        return in_array($recommended, $productIds, true) ? $recommended : min($productIds);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $group
     * @param  list<int>  $productIds
     * @param  list<int>  $variantIds
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, array<string, mixed>>  $variants
     * @return list<string>
     */
    private function staleStateFailures(
        array $group,
        array $data,
        array $productIds,
        array $variantIds,
        array $products,
        array $variants,
    ): array {
        $failures = [];
        if (array_keys($products) !== $productIds) {
            $failures[] = 'candidate_product_set_changed';
        }
        if (array_keys($variants) !== $variantIds) {
            $failures[] = 'candidate_variant_set_changed';
        }

        $reportedImageIds = [];
        foreach (($group['per_field_classification']['images']['by_product'] ?? []) as $items) {
            $reportedImageIds = array_merge($reportedImageIds, array_map(
                static fn (array $image): int => (int) $image['id'],
                is_array($items) ? $items : [],
            ));
        }
        $liveImageIds = array_map(static fn (array $image): int => (int) $image['id'], $data['images'] ?? []);
        sort($reportedImageIds, SORT_NUMERIC);
        sort($liveImageIds, SORT_NUMERIC);
        if ($reportedImageIds !== $liveImageIds) {
            $failures[] = 'image_state_changed_since_t1_5';
        }

        foreach (($group['per_field_classification'] ?? []) as $field => $comparison) {
            if (in_array($field, ['images', 'reviews', 'questions', 'favorites'], true)) {
                continue;
            }
            foreach (($comparison['values'] ?? []) as $productId => $evidence) {
                $productId = (int) $productId;
                $value = $products[$productId][$field] ?? null;
                $fingerprint = $this->missing($value) ? null : hash('sha256', $this->canonicalJson($value));
                if (($evidence['fingerprint'] ?? null) !== $fingerprint) {
                    $failures[] = 'product_content_changed_since_t1_5:'.$productId.':'.$field;
                }
            }
        }

        foreach (['reviews', 'questions', 'favorites'] as $type) {
            $liveCounts = array_fill_keys($productIds, 0);
            foreach ($data[$type] ?? [] as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                if (isset($liveCounts[$productId])) {
                    $liveCounts[$productId]++;
                }
            }
            $reportedCounts = $group['operational_content']['counts_by_product'] ?? [];
            foreach ($productIds as $productId) {
                if (($reportedCounts[$productId][$type] ?? 0) !== $liveCounts[$productId]) {
                    $failures[] = 'engagement_state_changed_since_t1_5:'.$type;
                    break;
                }
            }
        }

        sort($failures, SORT_STRING);

        return $failures;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $variantIds
     * @param  array<int, array<string, mixed>>  $variants
     * @param  list<string>  $stale
     * @return array<int, array<string, mixed>>
     */
    private function authoritativeVariants(array $productIds, array $variantIds, array $variants, array &$stale): array
    {
        $byProduct = [];
        foreach ($variantIds as $variantId) {
            $variant = $variants[$variantId] ?? null;
            if ($variant === null) {
                continue;
            }
            $sourceProductId = (int) ($variant['source_product_id'] ?? 0);
            if (in_array($sourceProductId, $productIds, true)) {
                $byProduct[$sourceProductId][] = $variant;
            }
        }
        $resolved = [];
        foreach ($productIds as $productId) {
            if (count($byProduct[$productId] ?? []) !== 1) {
                $stale[] = 'authoritative_variant_not_unique:'.$productId;

                continue;
            }
            $resolved[$productId] = $byProduct[$productId][0];
        }
        ksort($resolved, SORT_NUMERIC);
        $stale = array_values(array_unique($stale));
        sort($stale, SORT_STRING);

        return $resolved;
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, array<string, mixed>>  $variantByProduct
     * @return array<string, mixed>
     */
    private function specificationPlan(array $group, array $productIds, array $products, array $variantByProduct): array
    {
        $keys = [];
        foreach ($productIds as $productId) {
            foreach (array_keys($products[$productId]['specifications'] ?? []) as $key) {
                $keys[(string) $key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        $shared = [];
        $variantSpecific = [];
        $missing = [];
        $conflicts = [];
        $axisKeys = [];

        foreach ($keys as $key) {
            $values = [];
            foreach ($productIds as $productId) {
                $specifications = $products[$productId]['specifications'] ?? [];
                $values[$productId] = array_key_exists($key, $specifications) ? $specifications[$key] : null;
            }
            $present = array_filter($values, fn (mixed $value): bool => ! $this->missing($value));
            $axis = $this->axisForKey($key);

            if (count($present) !== count($values)) {
                $missing[$key] = ['values_by_product' => $values, 'preserve_known_values' => $present];

                continue;
            }
            if ($this->auditedEquivalentCountValues($group, $axis, $present)) {
                $shared[$key] = [
                    'value' => '1 item',
                    'classification' => 'shared_family_level',
                    'audit_resolution' => 'group_specific_equivalent_count_unit',
                    'raw_values_preserved' => $values,
                ];

                continue;
            }
            if (count(array_unique(array_map($this->normalizedValue(...), $present))) === 1
                && count($present) === count($values)) {
                $shared[$key] = ['value' => reset($present), 'classification' => 'shared_family_level'];

                continue;
            }
            if ($axis !== null || $this->valuesBackedByVariant($values, $variantByProduct)) {
                $axis ??= $this->inferredAxis($values, $variantByProduct) ?? 'other';
                $variantSpecific[$key] = [
                    'axis' => $axis,
                    'values_by_product' => $values,
                    'classification' => 'variant_specific',
                ];
                $axisKeys[$axis][] = $key;

                continue;
            }
            if (count($present) !== count($values)) {
                continue;
            }
            $conflicts[$key] = ['values_by_product' => $values, 'classification' => 'semantic_conflict'];
        }

        $duplicates = [];
        foreach ($axisKeys as $axis => $mappedKeys) {
            $mappedKeys = array_values(array_unique($mappedKeys));
            if (count($mappedKeys) > 1) {
                $duplicates[] = ['axis' => $axis, 'keys' => $mappedKeys, 'reason' => 'multiple_specification_keys_for_axis'];
            }
        }
        foreach ($productIds as $productId) {
            $axisValues = [];
            foreach ($variantSpecific as $key => $item) {
                $value = $item['values_by_product'][$productId] ?? null;
                if (! $this->missing($value)) {
                    $axisValues[$item['axis']][] = ['key' => $key, 'value' => $value];
                }
            }
            $axes = array_keys($axisValues);
            for ($left = 0; $left < count($axes); $left++) {
                for ($right = $left + 1; $right < count($axes); $right++) {
                    foreach ($axisValues[$axes[$left]] as $leftValue) {
                        foreach ($axisValues[$axes[$right]] as $rightValue) {
                            if ($this->normalizedValue($leftValue['value']) === $this->normalizedValue($rightValue['value'])) {
                                $duplicates[] = [
                                    'product_id' => $productId,
                                    'axes' => [$axes[$left], $axes[$right]],
                                    'keys' => [$leftValue['key'], $rightValue['key']],
                                    'value' => $leftValue['value'],
                                    'reason' => 'equivalent_value_under_different_semantic_axes',
                                ];
                            }
                        }
                    }
                }
            }
        }

        $ambiguousUnits = [];
        foreach ($variantSpecific as $key => $item) {
            $units = [];
            foreach ($item['values_by_product'] as $productId => $value) {
                if (is_string($value) && preg_match('/\b(cai|cay)\b/u', $this->normalizedValue($value), $matches) === 1) {
                    $units[$matches[1]][] = $productId;
                }
            }
            if (count($units) > 1) {
                $ambiguousUnits[] = ['key' => $key, 'units' => $units, 'reason' => 'inconsistent_counting_units'];
            }
        }

        $attributeAxes = $this->variantAttributeAxisAnalysis($group, $productIds, $products, $variantByProduct);
        $duplicates = array_merge($duplicates, $attributeAxes['unresolved_duplicates']);
        $duplicatesByFingerprint = [];
        foreach ($duplicates as $duplicate) {
            $duplicatesByFingerprint[hash('sha256', $this->canonicalJson($duplicate))] = $duplicate;
        }
        $duplicates = array_values($duplicatesByFingerprint);
        $variantAxes = array_values(array_unique(array_merge(
            array_column($variantSpecific, 'axis'),
            $attributeAxes['varying_axes'],
        )));
        sort($variantAxes, SORT_STRING);

        foreach ([$shared, $variantSpecific, $missing, $conflicts] as &$items) {
            ksort($items, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($items);
        usort($duplicates, fn (array $left, array $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

        return [
            'shared' => $shared,
            'variant_specific' => $variantSpecific,
            'missing_or_incomplete' => $missing,
            'semantic_conflicts' => $conflicts,
            'duplicate_semantic_axes' => $duplicates,
            'equivalent_semantic_aliases' => $attributeAxes['equivalent_aliases'],
            'ambiguous_units' => $ambiguousUnits,
            'variant_axes' => $variantAxes,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, array<string, mixed>>  $variantByProduct
     * @return array{varying_axes: list<string>, unresolved_duplicates: list<array<string, mixed>>, equivalent_aliases: list<array<string, mixed>>}
     */
    private function variantAttributeAxisAnalysis(array $group, array $productIds, array $products, array $variantByProduct): array
    {
        $valuesByAxis = [];
        $unresolvedDuplicates = [];
        $equivalentAliases = [];
        foreach ($productIds as $productId) {
            $attributes = $variantByProduct[$productId]['attributes'] ?? [];
            $byAxis = [];
            foreach ($attributes as $key => $value) {
                $axis = $this->axisForKey((string) $key);
                if ($axis !== null && ! $this->missing($value)) {
                    $byAxis[$axis][(string) $key] = $value;
                }
            }
            foreach ($byAxis as $axis => $items) {
                $normalizedValues = array_values(array_unique(array_map(
                    fn (mixed $value): string => $this->semanticValueForGroup($group, $axis, $value),
                    $items,
                )));
                if (count($items) > 1) {
                    $case = [
                        'product_id' => $productId,
                        'variant_id' => (int) $variantByProduct[$productId]['id'],
                        'axis' => $axis,
                        'keys' => array_keys($items),
                        'values' => $items,
                        'normalized_semantic_value' => count($normalizedValues) === 1 ? $normalizedValues[0] : null,
                    ];
                    if (count($normalizedValues) === 1) {
                        $case['reason'] = 'equivalent_variant_attribute_aliases';
                        $equivalentAliases[] = $case;
                        $valuesByAxis[$axis][$productId][] = $normalizedValues[0];
                    } elseif (($resolution = $this->corroboratedAliasResolution(
                        $axis,
                        $items,
                        (string) ($products[$productId]['name'] ?? ''),
                        (string) ($variantByProduct[$productId]['name'] ?? ''),
                    )) !== null) {
                        $case['normalized_semantic_value'] = $resolution['normalized_value'];
                        $case['reason'] = 'corroborated_unit_bearing_variant_identity';
                        $case['resolution_evidence'] = $resolution['evidence'];
                        $equivalentAliases[] = $case;
                        $valuesByAxis[$axis][$productId][] = $resolution['normalized_value'];
                    } else {
                        $case['reason'] = 'conflicting_variant_attribute_aliases';
                        $unresolvedDuplicates[] = $case;
                        $valuesByAxis[$axis][$productId] = $normalizedValues;
                    }
                } else {
                    $valuesByAxis[$axis][$productId][] = $normalizedValues[0];
                }
            }
            $axes = array_keys($byAxis);
            for ($left = 0; $left < count($axes); $left++) {
                for ($right = $left + 1; $right < count($axes); $right++) {
                    foreach ($byAxis[$axes[$left]] as $leftKey => $leftValue) {
                        foreach ($byAxis[$axes[$right]] as $rightKey => $rightValue) {
                            if ($this->normalizedValue($leftValue) === $this->normalizedValue($rightValue)) {
                                $unresolvedDuplicates[] = [
                                    'product_id' => $productId,
                                    'variant_id' => (int) $variantByProduct[$productId]['id'],
                                    'axes' => [$axes[$left], $axes[$right]],
                                    'keys' => [$leftKey, $rightKey],
                                    'value' => $leftValue,
                                    'reason' => 'equivalent_variant_value_under_different_axes',
                                ];
                            }
                        }
                    }
                }
            }
        }

        $varying = [];
        foreach ($valuesByAxis as $axis => $values) {
            $fingerprints = [];
            foreach ($productIds as $productId) {
                $productValues = array_values(array_unique($values[$productId] ?? []));
                sort($productValues, SORT_STRING);
                $fingerprints[] = $this->canonicalJson($productValues);
            }
            if (count(array_unique($fingerprints)) > 1) {
                $varying[] = $axis;
            }
        }
        sort($varying, SORT_STRING);
        usort($unresolvedDuplicates, fn (array $left, array $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));
        usort($equivalentAliases, fn (array $left, array $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

        return [
            'varying_axes' => $varying,
            'unresolved_duplicates' => $unresolvedDuplicates,
            'equivalent_aliases' => $equivalentAliases,
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $variantByProduct
     * @param  array<string, mixed>  $specifications
     * @return array{variants: list<array<string, mixed>>, writes: int, blockers: list<string>}
     */
    private function attributePlans(
        array $productIds,
        array $products,
        array $variantByProduct,
        array $specifications,
    ): array
    {
        $plans = [];
        $writes = 0;
        $blockers = [];
        foreach ($productIds as $productId) {
            $variant = $variantByProduct[$productId] ?? null;
            if ($variant === null) {
                continue;
            }
            $before = $this->canonicalArray(is_array($variant['attributes'] ?? null) ? $variant['attributes'] : []);
            $after = $before;
            $moved = [];
            foreach ($specifications['variant_specific'] as $sourceKey => $item) {
                $value = $item['values_by_product'][$productId] ?? null;
                if ($this->missing($value)) {
                    continue;
                }
                $axis = $item['axis'];
                if ($axis === 'barcode' && ! $this->missing($variant['barcode'] ?? null)) {
                    if ($this->normalizedValue($variant['barcode']) !== $this->normalizedValue($value)) {
                        $blockers[] = 'barcode_conflict:'.$variant['id'];
                    }
                    $moved[$sourceKey] = ['target' => 'barcode_column', 'value' => $value, 'write_required' => false];

                    continue;
                }
                $sourceNormalizedKey = $this->normalizedKey($sourceKey);
                $existingKeys = array_values(array_filter(array_keys($after), function (string|int $key) use ($axis, $sourceNormalizedKey): bool {
                    $normalizedKey = $this->normalizedKey((string) $key);

                    return $axis === 'other'
                        ? $normalizedKey === $sourceNormalizedKey || Str::after($normalizedKey, 'spec_') === $sourceNormalizedKey
                        : $this->axisForKey((string) $key) === $axis;
                }));
                if (count($existingKeys) > 1) {
                    $existingValues = array_map(static fn (string|int $key): mixed => $after[$key], $existingKeys);
                    if (count(array_unique(array_map(
                        fn (mixed $existingValue): string => $this->normalizedSemanticValue($axis, $existingValue),
                        $existingValues,
                    ))) > 1 && $this->corroboratedAliasResolution(
                        $axis,
                        array_intersect_key($after, array_flip($existingKeys)),
                        (string) ($products[$productId]['name'] ?? ''),
                        (string) ($variant['name'] ?? ''),
                    ) === null) {
                        $blockers[] = 'conflicting_variant_attribute_keys_for_axis:'.$variant['id'].':'.$axis;
                    } else {
                        sort($existingKeys, SORT_STRING);
                        $moved[$sourceKey] = [
                            'target' => array_map(static fn (string|int $key): string => 'attributes.'.$key, $existingKeys),
                            'value' => $value,
                            'write_required' => false,
                            'equivalent_aliases_preserved' => true,
                        ];
                    }

                    continue;
                }
                $targetKey = $existingKeys[0] ?? ($axis === 'other' ? $this->normalizedKey($sourceKey) : $axis);
                if (array_key_exists($targetKey, $after)
                    && $this->normalizedValue($after[$targetKey]) !== $this->normalizedValue($value)) {
                    $blockers[] = 'variant_attribute_value_conflict:'.$variant['id'].':'.$targetKey;

                    continue;
                }
                $writeRequired = ! array_key_exists($targetKey, $after);
                $after[$targetKey] = $value;
                $moved[$sourceKey] = ['target' => 'attributes.'.$targetKey, 'value' => $value, 'write_required' => $writeRequired];
            }
            $after = $this->canonicalArray($after);
            $writeRequired = $this->canonicalJson($before) !== $this->canonicalJson($after);
            $writes += $writeRequired ? 1 : 0;
            $plans[] = [
                'source_product_id' => $productId,
                'variant_id' => (int) $variant['id'],
                'attributes_before' => $before,
                'attributes_after' => $after,
                'normalized_semantic_attributes' => $this->normalizedSemanticAttributes(
                    $before,
                    (string) ($products[$productId]['name'] ?? ''),
                    (string) ($variant['name'] ?? ''),
                ),
                'specification_moves' => $moved,
                'write_required' => $writeRequired,
            ];
        }
        sort($blockers, SORT_STRING);

        return ['variants' => $plans, 'writes' => $writes, 'blockers' => array_values(array_unique($blockers))];
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, array<string, mixed>>  $variantByProduct
     * @param  array<string, mixed>  $specifications
     * @return array<string, mixed>
     */
    private function namingPlan(
        array $group,
        array $productIds,
        array $products,
        array $variantByProduct,
        array $specifications,
        int $canonicalId,
    ): array {
        $cores = [];
        $variants = [];
        $varyingAxes = array_values(array_diff($specifications['variant_axes'], ['barcode', 'other']));
        foreach ($productIds as $productId) {
            $name = (string) ($products[$productId]['name'] ?? '');
            $tokens = [];
            $labels = [];
            foreach ($specifications['variant_specific'] as $item) {
                $value = $item['values_by_product'][$productId] ?? null;
                if (! $this->missing($value) && in_array($item['axis'], $varyingAxes, true)) {
                    $tokens = array_merge($tokens, $this->removableValueTokens((string) $value));
                    $labels[$item['axis']][] = $this->displayAxisValue($item['axis'], (string) $value);
                }
            }
            foreach (($variantByProduct[$productId]['attributes'] ?? []) as $key => $value) {
                $axis = $this->axisForKey((string) $key);
                if ($axis !== null && in_array($axis, $varyingAxes, true) && ! $this->missing($value)) {
                    $tokens = array_merge($tokens, $this->removableValueTokens((string) $value));
                    $labels[$axis][] = $this->displayAxisValue($axis, (string) $value);
                }
            }
            $tokens = array_values(array_unique(array_filter($tokens)));
            usort($tokens, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
            $core = $name;
            $removed = [];
            foreach ($tokens as $token) {
                $prefixedTokens = [$token];
                if (in_array($token, $labels['color'] ?? [], true)) {
                    array_unshift($prefixedTokens, 'Màu '.$token);
                }
                $updated = $core;
                foreach ($prefixedTokens as $removable) {
                    $parts = preg_split('/\s+/u', trim($removable)) ?: [$removable];
                    $pattern = implode('\\s*', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts));
                    $updated = preg_replace('/(?<![\pL\pN])'.$pattern.'(?![\pL\pN])/iu', ' ', $updated) ?? $updated;
                }
                if (is_string($updated) && $updated !== $core) {
                    $removed[] = $token;
                    $core = $updated;
                }
            }
            $core = trim(preg_replace('/\s+/u', ' ', trim($core, " \t\n\r\0\x0B-–—,|/")) ?? '');
            $cores[$productId] = $core;
            $displayValues = [];
            foreach ($labels as $axis => $values) {
                $displayValues[] = count($labels) === 1
                    ? implode(' / ', array_values(array_unique($values)))
                    : $axis.': '.implode(' / ', array_values(array_unique($values)));
            }
            $variants[] = [
                'source_product_id' => $productId,
                'variant_id' => isset($variantByProduct[$productId]) ? (int) $variantByProduct[$productId]['id'] : null,
                'display_value' => $displayValues === [] ? null : implode(' · ', $displayValues),
                'removed_tokens' => $removed,
            ];
        }
        $normalizedCores = array_values(array_unique(array_map($this->normalizedValue(...), $cores)));
        $safe = count($normalizedCores) === 1 && $normalizedCores[0] !== '';
        if (! $safe && ($auditedCanonicalName = $this->auditedCanonicalName($group, $productIds, $products)) !== null) {
            $cores[$canonicalId] = $auditedCanonicalName;
            $safe = true;
        }
        if (! $safe && ($group['semantic_conflicts'] ?? []) === []) {
            $packageCores = $this->componentPackageCanonicalCores($productIds, $products, $variantByProduct);
            if ($packageCores !== null) {
                $cores = $packageCores;
                $safe = true;
            }
        }
        $canonicalName = $safe ? $cores[$canonicalId] : null;
        $configuredOverride = $this->configuredCanonicalNameOverride($canonicalId, $products);
        if ($configuredOverride !== null) {
            $canonicalName = $configuredOverride;
            $safe = true;
        }

        return [
            'status' => $safe ? 'safe' : 'needs_manual_rule',
            'canonical_name' => $canonicalName,
            'confidence' => $safe ? 'high' : 'low',
            'variants' => $variants,
        ];
    }

    /** @param array<int, array<string, mixed>> $products */
    private function configuredCanonicalNameOverride(int $canonicalId, array $products): ?string
    {
        $rule = config('product_variant_normalization.canonical_name_overrides.'.$canonicalId);
        if (! is_array($rule)
            || ! is_string($rule['current_name'] ?? null)
            || ! is_string($rule['canonical_name'] ?? null)
            || ($products[$canonicalId]['name'] ?? null) !== $rule['current_name']) {
            return null;
        }

        return $rule['canonical_name'];
    }

    /** @return list<string> */
    private function removableValueTokens(string $value): array
    {
        $tokens = [$value];
        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:ml|l|mg|g|kg|mm|cm|m)\b/iu', $value, $match) === 1) {
            $tokens[] = $match[0];
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, array<string, mixed>>  $variantByProduct
     * @return array<int, string>|null
     */
    private function componentPackageCanonicalCores(
        array $productIds,
        array $products,
        array $variantByProduct,
    ): ?array {
        $cores = [];
        foreach ($productIds as $productId) {
            $name = (string) ($products[$productId]['name'] ?? '');
            $variant = $variantByProduct[$productId] ?? null;
            if ($name === '' || $variant === null) {
                return null;
            }

            $volumeAliases = array_filter(
                $variant['attributes'] ?? [],
                fn (mixed $value, string|int $key): bool => $this->axisForKey((string) $key) === 'volume'
                    && ! $this->missing($value),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($volumeAliases === []) {
                return null;
            }

            $normalizedVolumes = array_values(array_unique(array_map(
                fn (mixed $value): string => $this->normalizedSemanticValue('volume', $value),
                $volumeAliases,
            )));
            if (count($normalizedVolumes) !== 1) {
                return null;
            }

            $volume = (string) reset($volumeAliases);
            $nameSignature = $this->packageMeasurementSignature($name);
            $variantSignature = $this->packageMeasurementSignature($volume);
            if ($nameSignature !== $variantSignature || count($variantSignature) < 2) {
                return null;
            }

            $core = preg_replace($this->packageMeasurementPattern(), ' ', $name) ?? $name;
            $core = preg_replace('/\s+/u', ' ', trim($core)) ?? '';
            $core = preg_replace('/\(\s+/u', '(', $core) ?? $core;
            $core = preg_replace('/\s+\)/u', ')', $core) ?? $core;
            $core = preg_replace('/\(\s*\)/u', '', $core) ?? $core;
            $cores[$productId] = trim($core, " \t\n\r\0\x0B-–—,|/+");
        }

        $normalizedCores = array_values(array_unique(array_map($this->normalizedValue(...), $cores)));

        return count($normalizedCores) === 1 && $normalizedCores[0] !== '' ? $cores : null;
    }

    /** @return list<string> */
    private function packageMeasurementSignature(string $value): array
    {
        preg_match_all($this->packageMeasurementPattern(), $value, $matches, PREG_SET_ORDER);
        $signature = [];
        foreach ($matches as $match) {
            $count = (int) (($match[1] ?? '') !== '' ? $match[1] : (($match[4] ?? '') !== '' ? $match[4] : 1));
            if ($count < 1 || $count > 20) {
                return [];
            }
            $measurement = str_replace(',', '.', $match[2]).mb_strtolower($match[3]);
            for ($index = 0; $index < $count; $index++) {
                $signature[] = $measurement;
            }
        }
        sort($signature, SORT_STRING);

        return $signature;
    }

    private function packageMeasurementPattern(): string
    {
        return '/(?<![\pL\pN])(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(ml|l|mg|g|kg)(?:\s*[x×]\s*(\d+))?(?![\pL\pN])/iu';
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<int>  $productIds
     * @param  array<int, array<string, mixed>>  $products
     */
    private function auditedCanonicalName(array $group, array $productIds, array $products): ?string
    {
        if (($group['group_identifier'] ?? null) !== 'pvg-13ee293c047c417a') {
            return null;
        }

        $actualNames = array_map(
            fn (int $productId): string => $this->normalizedValue($products[$productId]['name'] ?? ''),
            $productIds,
        );
        $expectedNames = array_map($this->normalizedValue(...), [
            'Phấn Phủ Carslan Dạng Nén Bản Thường Màu Tím 8g',
            'Phấn Phủ Carslan Dạng Nén Bản Thường Màu Hồng 8g',
        ]);
        sort($actualNames, SORT_STRING);
        sort($expectedNames, SORT_STRING);

        return $actualNames === $expectedNames
            ? 'Phấn Phủ Carslan Dạng Nén Bản Thường 8g'
            : null;
    }

    /** @param array<string, mixed> $group @param array<int, mixed> $values */
    private function auditedEquivalentCountValues(array $group, ?string $axis, array $values): bool
    {
        if (($group['group_identifier'] ?? null) !== 'pvg-9b8a55eed1089cc8' || $axis !== 'volume') {
            return false;
        }

        $normalized = array_values(array_unique(array_map($this->normalizedValue(...), $values)));
        sort($normalized, SORT_STRING);

        return $normalized === ['1 cai', '1 cay'];
    }

    /** @param array<string, mixed> $group */
    private function semanticValueForGroup(array $group, string $axis, mixed $value): string
    {
        if (($group['group_identifier'] ?? null) === 'pvg-9b8a55eed1089cc8'
            && $axis === 'volume'
            && in_array($this->normalizedValue($value), ['1 cai', '1 cay'], true)) {
            return '1item';
        }

        return $this->normalizedSemanticValue($axis, $value);
    }

    private function displayAxisValue(string $axis, string $value): string
    {
        if ($axis === 'volume' && count($this->packageMeasurementSignature($value)) >= 2) {
            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        }
        if ($axis === 'size'
            && preg_match('/\d+(?:[.,]\d+)?\s*[x×]\s*\d+(?:[.,]\d+)?\s*(?:mm|cm|m)\b/iu', $value, $match) === 1) {
            return preg_replace('/\s+/u', '', $match[0]) ?? $match[0];
        }
        if (in_array($axis, ['volume', 'weight', 'size'], true)
            && preg_match('/\d+(?:[.,]\d+)?\s*(?:ml|l|mg|g|kg|mm|cm|m)(?:\s*[x×]\s*\d+(?:[.,]\d+)?\s*(?:mm|cm|m))?/iu', $value, $match) === 1) {
            return preg_replace('/\s+/u', '', $match[0]) ?? $match[0];
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $images @param list<int> $productIds @param array<int, array<string, mixed>> $variantByProduct */
    private function imagePlan(array $images, array $productIds, array $variantByProduct, int $canonicalId): array
    {
        usort($images, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        $plans = [];
        $blockers = [];
        $primaryCounts = [];
        $writes = 0;
        foreach ($images as $image) {
            $sourceProductId = (int) ($image['source_product_id'] ?? 0);
            $targetVariant = $variantByProduct[$sourceProductId] ?? null;
            $confidence = $targetVariant === null ? 'ambiguous' : 'high';
            if ($targetVariant === null) {
                $blockers[] = 'ambiguous_image_owner:'.$image['id'];
            } else {
                $currentVariantId = $image['product_variant_id'] === null ? null : (int) $image['product_variant_id'];
                if ($currentVariantId !== null && $currentVariantId !== (int) $targetVariant['id']) {
                    $blockers[] = 'stale_image_variant_owner:'.$image['id'];
                    $confidence = 'ambiguous';
                }
                if ((bool) $image['is_primary']) {
                    $primaryCounts[(int) $targetVariant['id']] = ($primaryCounts[(int) $targetVariant['id']] ?? 0) + 1;
                }
            }
            $requiresWrite = $targetVariant !== null
                && ((int) $image['product_id'] !== $canonicalId
                    || $image['product_variant_id'] === null);
            $writes += $requiresWrite ? 1 : 0;
            $plans[] = [
                'image_id' => (int) $image['id'],
                'current_product_id' => (int) $image['product_id'],
                'current_product_variant_id' => $image['product_variant_id'] === null ? null : (int) $image['product_variant_id'],
                'intended_canonical_product_id' => $canonicalId,
                'intended_product_variant_id' => $targetVariant === null ? null : (int) $targetVariant['id'],
                'is_primary' => (bool) $image['is_primary'],
                'sort_order' => (int) $image['sort_order'],
                'attribution_confidence' => $confidence,
                'write_required' => $requiresWrite,
            ];
        }
        foreach ($primaryCounts as $variantId => $count) {
            if ($count > 1) {
                $blockers[] = 'multiple_primary_images_for_variant:'.$variantId;
            }
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return [
            'images' => $plans,
            'writes' => $writes,
            'blockers' => $blockers,
            't1_3_compatible' => $blockers === [],
        ];
    }

    /** @param array<string, mixed> $group @param list<int> $productIds @param array<int, array<string, mixed>> $products */
    private function sharedFamilyFields(array $group, array $productIds, array $products): array
    {
        $fields = [];
        foreach (self::FAMILY_FIELDS as $field) {
            $comparison = $group['per_field_classification'][$field] ?? [];
            $safe = in_array($comparison['classification'] ?? null, ['shared_exact', 'shared_normalized'], true);
            $values = [];
            foreach ($productIds as $productId) {
                $values[$productId] = $products[$productId][$field] ?? null;
            }
            $fields[$field] = [
                'classification' => $comparison['classification'] ?? 'unknown',
                'safe_to_keep_on_product' => $safe,
                'canonical_value' => $safe ? reset($values) : null,
                'values_by_product' => $values,
            ];
        }

        return $fields;
    }

    /** @param array<string, mixed> $data @param list<int> $productIds */
    private function engagementState(array $data, array $productIds, int $canonicalId): array
    {
        $result = [];
        foreach (['reviews', 'questions', 'favorites'] as $type) {
            $counts = array_fill_keys($productIds, 0);
            $softDeleted = array_fill_keys($productIds, 0);
            foreach ($data[$type] ?? [] as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                if (isset($counts[$productId])) {
                    if ($type === 'reviews' && ($row['deleted_at'] ?? null) !== null) {
                        $softDeleted[$productId]++;
                    } else {
                        $counts[$productId]++;
                    }
                }
            }
            $result[$type] = [
                'active_counts_by_product' => $counts,
                'soft_deleted_counts_by_product' => $type === 'reviews' ? $softDeleted : [],
                'remaining_on_noncanonical_products' => array_sum(array_diff_key($counts, [$canonicalId => true])),
                'soft_deleted_on_noncanonical_products' => $type === 'reviews'
                    ? array_sum(array_diff_key($softDeleted, [$canonicalId => true]))
                    : 0,
            ];
        }

        return $result;
    }

    /** @param list<int> $productIds @param array<int, array<string, mixed>> $products */
    private function sourceIdentities(array $productIds, array $products): array
    {
        return array_values(array_map(static fn (int $productId): array => [
            'product_id' => $productId,
            'source' => $products[$productId]['source'] ?? null,
            'external_id' => $products[$productId]['external_id'] ?? null,
        ], $productIds));
    }

    /** @param list<int> $productIds @param array<int, array<string, mixed>> $products */
    private function productNames(array $productIds, array $products): array
    {
        $names = [];
        foreach ($productIds as $productId) {
            $names[$productId] = $products[$productId]['name'] ?? null;
        }

        return $names;
    }

    /** @param array<int, mixed> $values @param array<int, array<string, mixed>> $variantByProduct */
    private function valuesBackedByVariant(array $values, array $variantByProduct): bool
    {
        foreach ($values as $productId => $value) {
            if ($this->missing($value)) {
                continue;
            }
            $variant = $variantByProduct[$productId] ?? null;
            if ($variant === null) {
                return false;
            }
            $candidates = array_merge(array_values($variant['attributes'] ?? []), [$variant['barcode'] ?? null, $variant['weight'] ?? null]);
            if (! in_array($this->normalizedValue($value), array_map($this->normalizedValue(...), $candidates), true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $values @param array<int, array<string, mixed>> $variantByProduct */
    private function inferredAxis(array $values, array $variantByProduct): ?string
    {
        foreach (self::AXIS_ALIASES as $axis => $aliases) {
            $matched = true;
            foreach ($values as $productId => $value) {
                if ($this->missing($value)) {
                    continue;
                }
                $variant = $variantByProduct[$productId] ?? null;
                $axisValues = [];
                foreach (($variant['attributes'] ?? []) as $key => $attributeValue) {
                    if (in_array($this->normalizedKey((string) $key), $aliases, true)) {
                        $axisValues[] = $attributeValue;
                    }
                }
                if (! in_array($this->normalizedValue($value), array_map($this->normalizedValue(...), $axisValues), true)) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $axis;
            }
        }

        return null;
    }

    private function axisForKey(string $key): ?string
    {
        $normalizedKey = $this->normalizedKey($key);
        $normalized = str_starts_with($normalizedKey, 'spec_') ? Str::after($normalizedKey, 'spec_') : $normalizedKey;
        foreach (self::AXIS_ALIASES as $axis => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $axis;
            }
        }

        return null;
    }

    private function normalizedKey(string $key): string
    {
        return Str::slug($key, '_');
    }

    private function normalizedValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->canonicalJson($value);
        }
        $value = Str::lower(Str::ascii(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '');
    }

    private function normalizedSemanticValue(string $axis, mixed $value): string
    {
        $normalized = $this->normalizedValue($value);

        return in_array($axis, ['volume', 'weight', 'size'], true)
            ? str_replace(' ', '', $normalized)
            : $normalized;
    }

    /**
     * @param  array<string|int, mixed>  $aliases
     * @return array{normalized_value: string, evidence: array<string, mixed>}|null
     */
    private function corroboratedAliasResolution(
        string $axis,
        array $aliases,
        string $productName,
        string $variantName,
    ): ?array {
        if ($axis !== 'volume' || count($aliases) < 2) {
            return null;
        }

        $measurements = [];
        $bareNumbers = [];
        foreach ($aliases as $key => $value) {
            if (! is_string($value)) {
                return null;
            }
            $value = trim($value);
            if (preg_match('/^\d+(?:[.,]\d+)?\s*(?:ml|l|mg|g|kg)$/iu', $value) === 1) {
                $measurements[(string) $key] = $value;
            } elseif (preg_match('/^\d+(?:[.,]\d+)?$/u', $value) === 1) {
                $bareNumbers[(string) $key] = $value;
            } else {
                return null;
            }
        }
        if ($measurements === [] || $bareNumbers === [] || count($measurements) + count($bareNumbers) !== count($aliases)) {
            return null;
        }

        $normalizedMeasurements = array_values(array_unique(array_map(
            fn (string $value): string => $this->normalizedSemanticValue($axis, $value),
            $measurements,
        )));
        if (count($normalizedMeasurements) !== 1) {
            return null;
        }

        $measurement = reset($measurements);
        preg_match('/^(\d+(?:[.,]\d+)?)\s*(ml|l|mg|g|kg)$/iu', $measurement, $parts);
        $namePattern = '/(?<![\pL\pN])'.preg_quote($parts[1], '/').'\s*'.preg_quote($parts[2], '/').'\s*$/iu';
        if (preg_match($namePattern, trim($productName)) !== 1
            || $this->normalizedSemanticValue($axis, $variantName) !== $normalizedMeasurements[0]) {
            return null;
        }

        foreach ($bareNumbers as $bareNumber) {
            $barePattern = '/(?<![\pL\pN])'.preg_quote($bareNumber, '/').'(?![\pL\pN])/u';
            if (preg_match($barePattern, $productName) === 1 || preg_match($barePattern, $variantName) === 1) {
                return null;
            }
        }

        return [
            'normalized_value' => $normalizedMeasurements[0],
            'evidence' => [
                'unit_bearing_aliases' => $measurements,
                'uncorroborated_bare_number_aliases' => $bareNumbers,
                'product_name_suffix_match' => true,
                'variant_name_match' => true,
                'raw_attributes_preserved' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, string> */
    private function normalizedSemanticAttributes(
        array $attributes,
        string $productName,
        string $variantName,
    ): array
    {
        $values = [];
        foreach ($attributes as $key => $value) {
            $axis = $this->axisForKey((string) $key);
            if ($axis === null || $this->missing($value)) {
                continue;
            }
            $values[$axis][] = $this->normalizedSemanticValue($axis, $value);
        }

        $normalized = [];
        foreach ($values as $axis => $axisValues) {
            $axisValues = array_values(array_unique($axisValues));
            if (count($axisValues) === 1) {
                $normalized[$axis] = $axisValues[0];
            } elseif (($resolution = $this->corroboratedAliasResolution(
                $axis,
                array_filter(
                    $attributes,
                    fn (mixed $value, string|int $key): bool => $this->axisForKey((string) $key) === $axis
                        && ! $this->missing($value),
                    ARRAY_FILTER_USE_BOTH,
                ),
                $productName,
                $variantName,
            )) !== null) {
                $normalized[$axis] = $resolution['normalized_value'];
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function missing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === []);
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function canonicalArray(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalArray($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(is_array($value) ? $this->canonicalArray($value) : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $values @return list<int> */
    private function integerList(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @param list<array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function keyById(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['id']] = $row;
        }
        ksort($keyed, SORT_NUMERIC);

        return $keyed;
    }
}
