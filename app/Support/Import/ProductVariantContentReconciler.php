<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

final class ProductVariantContentReconciler
{
    /** @var list<string> */
    private const PRODUCT_FIELDS = [
        'name',
        'short_description',
        'description',
        'ingredients',
        'usage_instructions',
        'origin_country',
        'category_id',
        'brand_id',
        'external_rating',
        'external_review_count',
    ];

    /** @var array<string, list<string>> */
    private const DIMENSION_ALIASES = [
        'volume' => ['dung_tich', 'the_tich', 'volume', 'capacity'],
        'weight' => ['trong_luong', 'khoi_luong', 'weight'],
        'size' => ['kich_thuoc', 'size'],
        'color' => ['mau', 'mau_sac', 'color', 'tone', 'shade'],
        'scent' => ['mui_huong', 'huong', 'scent', 'fragrance'],
        'type' => ['loai', 'phan_loai', 'dang', 'type'],
        'barcode' => ['barcode', 'ma_vach'],
    ];

    /** @var list<string> */
    private const CRITICAL_SPECIFICATION_KEYS = [
        'loai_san_pham',
        'dang_san_pham',
        'cong_dung',
        'product_type',
        'purpose',
        'formula',
        'cong_thuc',
        'thanh_phan',
        'ingredients',
        'huong_dan_su_dung',
        'usage',
    ];

    /**
     * Content differences approved by the final T1 content audit.
     *
     * These decisions are deliberately scoped by group and field. They must not
     * turn similar-looking content in an unaudited family into a safe merge.
     *
     * @var array<string, array<string, string>>
     */
    private const AUDITED_CONTENT_RESOLUTIONS = [
        'pvg-13ee293c047c417a' => ['description' => 'same_meaning_minor_copy_difference', 'short_description' => 'variant_quantity_only'],
        'pvg-256d679626f0d710' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-2978ff6df07ec49b' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-30b4b4452f6bc335' => ['short_description' => 'same_meaning_minor_copy_difference'],
        'pvg-38a4921d4acef61c' => ['short_description' => 'variant_quantity_only'],
        'pvg-452148f507f89953' => ['short_description' => 'variant_quantity_only'],
        'pvg-51bc30d4f169226e' => ['short_description' => 'variant_quantity_only'],
        'pvg-53695a009c08e222' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-55e32a1826890845' => ['short_description' => 'same_meaning_minor_copy_difference'],
        'pvg-65d050299781d79d' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-6982613a142ed1a7' => ['short_description' => 'variant_quantity_only'],
        'pvg-6f7febf9d971c3ae' => ['short_description' => 'same_meaning_minor_copy_difference'],
        'pvg-7572a6e76401e654' => ['short_description' => 'variant_quantity_only'],
        'pvg-8c424e1e92dddaaa' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-923893af804e54c0' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-93be79737db9cf18' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-9b8a55eed1089cc8' => ['short_description' => 'variant_quantity_only'],
        'pvg-9c1e701905c788cb' => ['short_description' => 'variant_quantity_only'],
        'pvg-9e4a3f002752aef8' => ['short_description' => 'variant_quantity_only'],
        'pvg-a5a0f8dba5564439' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-a715040f1a1c1d1f' => ['description' => 'same_meaning_minor_copy_difference', 'short_description' => 'variant_quantity_only'],
        'pvg-d467892127141cd6' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-dcb76623b40f2b55' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-e300270768ec3a7c' => ['description' => 'same_meaning_minor_copy_difference'],
        'pvg-f3a7f13bfa235171' => ['description' => 'same_meaning_minor_copy_difference', 'origin_country' => 'missing_member_consistent'],
        'pvg-fd6bde66fdc7df31' => ['description' => 'same_meaning_minor_copy_difference', 'short_description' => 'variant_quantity_only'],
    ];

    /** @var list<string> */
    private const AUDITED_DO_NOT_MERGE_GROUPS = [
        'pvg-6851845782b6d22f',
        'pvg-f84ae25842e2ca40',
    ];

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reconcile(array $group, array $data): array
    {
        $products = $this->sortedById($data['products'] ?? []);
        $productIds = array_values(array_map(static fn (array $product): int => (int) $product['id'], $products));
        $variants = $this->sortedById($data['variants'] ?? []);
        $normalizationState = $this->normalizationState($group, $products, $variants);

        if ($normalizationState['is_already_normalized']) {
            return $this->alreadyNormalizedResult(
                $group,
                $productIds,
                $normalizationState,
                $this->operationalContent($productIds, $data),
            );
        }

        $images = $this->sortedById($data['images'] ?? []);
        $operations = $this->operationalContent($productIds, $data);
        $variantInspection = $this->variantInspection($productIds, $variants);
        $specificationDiff = $this->specificationDiff($products, $variantInspection);
        $perField = [];

        foreach (self::PRODUCT_FIELDS as $field) {
            $values = $this->valuesByProduct($products, $field);
            $perField[$field] = $this->fieldComparison($field, $values, $group);
        }

        $perField['specifications'] = [
            'classification' => $specificationDiff['classification'],
            'values' => $this->evidenceForValues($this->valuesByProduct($products, 'specifications')),
        ];
        $perField['images'] = $this->imageComparison($productIds, $images);

        foreach (['reviews', 'questions', 'favorites'] as $field) {
            $perField[$field] = [
                'classification' => array_sum(array_column($operations['counts_by_product'], $field)) > 0
                    ? 'variant_specific'
                    : 'shared_exact',
                'counts_by_product' => array_map(
                    static fn (array $counts): int => $counts[$field],
                    $operations['counts_by_product'],
                ),
            ];
        }

        ksort($perField, SORT_STRING);
        $shared = [];
        $variantSpecific = [];
        $conflicts = [];

        foreach ($perField as $field => $comparison) {
            $classification = $comparison['classification'];
            if (in_array($classification, ['shared_exact', 'shared_normalized'], true)) {
                $shared[$field] = $comparison;
            } elseif ($classification === 'variant_specific') {
                $variantSpecific[$field] = $comparison;
            } elseif ($classification === 'semantic_conflict') {
                $conflicts[$field] = $comparison;
            }
        }

        $decision = $this->mergeDecision(
            $group,
            $perField,
            $specificationDiff,
            $operations,
            $images,
        );

        return [
            'group_identifier' => (string) ($group['group_identifier'] ?? ''),
            'source_manifest_classification' => (string) ($group['classification'] ?? ''),
            'candidate_product_ids' => $this->integerList($group['candidate_product_ids'] ?? $productIds),
            'candidate_variant_ids' => $this->integerList($group['candidate_variant_ids'] ?? []),
            'canonical_recommendation' => isset($group['recommended_canonical_product_id'])
                ? (int) $group['recommended_canonical_product_id']
                : null,
            'per_field_classification' => $perField,
            'shared_content_candidate' => $shared,
            'variant_specific_content_candidate' => $variantSpecific,
            'semantic_conflicts' => $conflicts,
            'specification_diff' => $specificationDiff,
            'variant_attribute_inspection' => $variantInspection,
            'operational_content' => $operations,
            'normalization_state' => $normalizationState,
            'recommended_merge_decision' => $decision['decision'],
            'reasons' => $decision['reasons'],
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $normalizationState
     * @param  array<string, mixed>  $operations
     * @return array<string, mixed>
     */
    private function alreadyNormalizedResult(
        array $group,
        array $productIds,
        array $normalizationState,
        array $operations,
    ): array {
        return [
            'group_identifier' => (string) ($group['group_identifier'] ?? ''),
            'source_manifest_classification' => (string) ($group['classification'] ?? ''),
            'candidate_product_ids' => $this->integerList($group['candidate_product_ids'] ?? $productIds),
            'candidate_variant_ids' => $this->integerList($group['candidate_variant_ids'] ?? []),
            'canonical_recommendation' => $normalizationState['canonical_product_id'],
            'per_field_classification' => [],
            'shared_content_candidate' => [],
            'variant_specific_content_candidate' => [],
            'semantic_conflicts' => [],
            'specification_diff' => [
                'classification' => 'not_evaluated_already_normalized',
                'shared_keys' => [],
                'variant_specific_keys' => [],
                'conflicting_keys' => [],
                'missing_keys' => [],
            ],
            'variant_attribute_inspection' => [],
            'operational_content' => $operations,
            'normalization_state' => $normalizationState,
            'recommended_merge_decision' => 'already_normalized',
            'reasons' => ['authoritative_post_normalization_invariants_hold'],
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function normalizationState(array $group, array $products, array $variants): array
    {
        $groupIdentifier = (string) ($group['group_identifier'] ?? '');
        $candidateProductIds = $this->integerList($group['candidate_product_ids'] ?? []);
        $candidateVariantIds = $this->integerList($group['candidate_variant_ids'] ?? []);
        $productsById = [];
        foreach ($products as $product) {
            $productsById[(int) $product['id']] = $product;
        }
        $variantsById = [];
        foreach ($variants as $variant) {
            $variantsById[(int) $variant['id']] = $variant;
        }
        $markerCanonicalIds = [];
        foreach ($products as $product) {
            $marker = $product['source_variant_groups']['_mizuki_variant_normalization'] ?? null;
            if (is_array($marker)
                && ($marker['group_identifier'] ?? null) === $groupIdentifier
                && (int) ($marker['canonical_product_id'] ?? 0) > 0) {
                $markerCanonicalIds[] = (int) $marker['canonical_product_id'];
            }
        }
        $markerCanonicalIds = array_values(array_unique($markerCanonicalIds));
        sort($markerCanonicalIds, SORT_NUMERIC);
        $declaredCanonicalId = isset($group['recommended_canonical_product_id'])
            ? (int) $group['recommended_canonical_product_id']
            : 0;
        $canonicalId = $declaredCanonicalId > 0
            ? $declaredCanonicalId
            : (count($markerCanonicalIds) === 1 ? $markerCanonicalIds[0] : 0);
        $canonical = $productsById[$canonicalId] ?? null;
        $duplicateIds = array_values(array_diff($candidateProductIds, [$canonicalId]));
        $duplicates = array_values(array_intersect_key($productsById, array_flip($duplicateIds)));
        $expectedIdentities = [];
        foreach ($group['source_external_ids'] ?? [] as $identity) {
            if (! is_array($identity)) {
                continue;
            }
            $source = $identity['source'] ?? null;
            $externalId = $identity['external_id'] ?? null;
            if (is_string($source) && $source !== '' && is_string($externalId) && $externalId !== '') {
                $expectedIdentities[] = $source.'|'.$externalId;
            }
        }
        $expectedIdentities = array_values(array_unique($expectedIdentities));
        sort($expectedIdentities, SORT_STRING);
        $productIdentities = array_values(array_unique(array_map(
            static fn (array $product): string => (string) ($product['source'] ?? '').'|'.(string) ($product['external_id'] ?? ''),
            $products,
        )));
        sort($productIdentities, SORT_STRING);
        $variantIdentities = array_values(array_unique(array_map(
            static fn (array $variant): string => (string) ($variant['source'] ?? '').'|'.(string) ($variant['external_id'] ?? ''),
            $variants,
        )));
        sort($variantIdentities, SORT_STRING);
        $productIdentitiesIntact = count($expectedIdentities) === count($candidateProductIds)
            && $productIdentities === $expectedIdentities;
        $variantIdentitiesIntact = count($expectedIdentities) === count($candidateVariantIds)
            && $variantIdentities === $expectedIdentities;
        $checks = [
            'canonical_product_is_live' => $canonical !== null
                && (bool) ($canonical['is_active'] ?? false)
                && ($canonical['deleted_at'] ?? null) === null,
            'all_candidate_products_present' => $this->integerList(array_keys($productsById)) === $candidateProductIds,
            'all_candidate_variants_present' => $this->integerList(array_keys($variantsById)) === $candidateVariantIds,
            'product_source_identities_intact' => $productIdentitiesIntact,
            'variant_source_identities_intact' => $variantIdentitiesIntact,
            'all_variants_on_canonical_product' => $variants !== []
                && array_all($variants, static fn (array $variant): bool => (int) ($variant['product_id'] ?? 0) === $canonicalId),
            'all_variants_are_live' => $variants !== []
                && array_all($variants, static fn (array $variant): bool => ($variant['deleted_at'] ?? null) === null),
            'duplicate_products_are_retired' => count($duplicates) === count($duplicateIds)
                && array_all($duplicates, static fn (array $product): bool => ! (bool) ($product['is_active'] ?? true)
                    && ($product['deleted_at'] ?? null) !== null),
            'retirement_markers_are_intact' => count($duplicates) === count($duplicateIds)
                && array_all($duplicates, static function (array $product) use ($groupIdentifier, $canonicalId): bool {
                    $marker = $product['source_variant_groups']['_mizuki_variant_normalization'] ?? null;

                    return is_array($marker)
                        && ($marker['group_identifier'] ?? null) === $groupIdentifier
                        && (int) ($marker['retired_product_id'] ?? 0) === (int) $product['id']
                        && (int) ($marker['canonical_product_id'] ?? 0) === $canonicalId;
                }),
            'no_active_duplicate_product_remains' => array_all(
                $duplicates,
                static fn (array $product): bool => ! (bool) ($product['is_active'] ?? true),
            ),
        ];
        $failedChecks = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        sort($failedChecks, SORT_STRING);

        return [
            'is_already_normalized' => $failedChecks === [],
            'canonical_product_id' => $canonicalId > 0 ? $canonicalId : null,
            'retired_product_ids' => $duplicateIds,
            'checks' => $checks,
            'failed_checks' => $failedChecks,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function fieldComparison(string $field, array $values, array $group): array
    {
        $missing = array_filter($values, $this->isMissing(...));
        if ($missing !== []) {
            if ($this->auditedContentResolution($group, $field) === 'missing_member_consistent'
                && count($missing) < count($values)) {
                $present = array_diff_key($values, $missing);
                $normalizedPresent = array_map($this->normalizedValue(...), $present);
                if (count(array_unique($normalizedPresent)) === 1) {
                    return [
                        'classification' => 'shared_normalized',
                        'audit_resolution' => 'missing_member_consistent',
                        'values' => $this->evidenceForValues($values),
                    ];
                }
            }

            return [
                'classification' => 'missing_or_incomplete',
                'missing_state' => count($missing) === count($values) ? 'all_missing' : 'partial',
                'values' => $this->evidenceForValues($values),
            ];
        }

        $canonical = array_map($this->canonicalValue(...), $values);
        if (count(array_unique($canonical)) === 1) {
            return ['classification' => 'shared_exact', 'values' => $this->evidenceForValues($values)];
        }

        $normalized = array_map($this->normalizedValue(...), $values);
        if (count(array_unique($normalized)) === 1) {
            return ['classification' => 'shared_normalized', 'values' => $this->evidenceForValues($values)];
        }

        $auditResolution = $this->auditedContentResolution($group, $field);
        if ($auditResolution === 'variant_quantity_only') {
            return [
                'classification' => 'variant_specific',
                'audit_resolution' => $auditResolution,
                'values' => $this->evidenceForValues($values),
            ];
        }
        if ($auditResolution === 'same_meaning_minor_copy_difference') {
            return [
                'classification' => 'shared_normalized',
                'audit_resolution' => $auditResolution,
                'values' => $this->evidenceForValues($values),
            ];
        }

        if (in_array($field, ['name', 'short_description', 'description'], true)
            && $this->differsOnlyByVariantDimensions($normalized, $group)) {
            return ['classification' => 'variant_specific', 'values' => $this->evidenceForValues($values)];
        }

        if (in_array($field, ['external_rating', 'external_review_count'], true)) {
            return [
                'classification' => 'variant_specific',
                'values' => $this->evidenceForValues($values),
                'requires_business_decision' => 'external_aggregate_reconciliation',
            ];
        }

        return ['classification' => 'semantic_conflict', 'values' => $this->evidenceForValues($values)];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $variantInspection
     * @return array<string, mixed>
     */
    private function specificationDiff(array $products, array $variantInspection): array
    {
        $productIds = array_map(static fn (array $product): int => (int) $product['id'], $products);
        $specifications = [];
        $keys = [];

        foreach ($products as $product) {
            $specifications[$product['id']] = is_array($product['specifications'] ?? null)
                ? $product['specifications']
                : [];
            $keys = array_merge($keys, array_keys($specifications[$product['id']]));
        }

        $keys = array_values(array_unique(array_map('strval', $keys)));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        $shared = [];
        $variantSpecific = [];
        $conflicting = [];
        $missing = [];

        foreach ($keys as $key) {
            $values = [];
            foreach ($productIds as $productId) {
                $values[$productId] = array_key_exists($key, $specifications[$productId])
                    ? $specifications[$productId][$key]
                    : null;
            }

            if (array_filter($values, $this->isMissing(...)) !== []) {
                $missingCount = count(array_filter($values, $this->isMissing(...)));
                $missing[$key] = [
                    'classification' => 'missing_or_incomplete',
                    'missing_state' => $missingCount === count($values) ? 'all_missing' : 'partial',
                    'values' => $this->evidenceForValues($values),
                ];

                continue;
            }

            $canonical = array_map($this->canonicalValue(...), $values);
            if (count(array_unique($canonical)) === 1) {
                $shared[$key] = ['classification' => 'shared_exact', 'values' => $this->evidenceForValues($values)];

                continue;
            }

            $normalized = array_map($this->normalizedValue(...), $values);
            if (count(array_unique($normalized)) === 1) {
                $shared[$key] = ['classification' => 'shared_normalized', 'values' => $this->evidenceForValues($values)];

                continue;
            }

            $normalizedKey = $this->normalizedKey($key);
            $dimension = $this->dimensionForKey($normalizedKey);
            $backedByVariant = $this->valuesBackedByVariantAttributes($normalizedKey, $values, $variantInspection);

            if (($dimension !== null && ! in_array($normalizedKey, self::CRITICAL_SPECIFICATION_KEYS, true)) || $backedByVariant) {
                $variantSpecific[$key] = [
                    'classification' => 'variant_specific',
                    'dimension' => $dimension ?? 'other',
                    'backed_by_variant_attribute' => $backedByVariant,
                    'values' => $this->evidenceForValues($values),
                ];
            } else {
                $conflicting[$key] = [
                    'classification' => 'semantic_conflict',
                    'values' => $this->evidenceForValues($values),
                ];
            }
        }

        foreach ([$shared, $variantSpecific, $conflicting, $missing] as &$items) {
            ksort($items, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($items);

        $classification = match (true) {
            $conflicting !== [] => 'semantic_conflict',
            $missing !== [] => 'missing_or_incomplete',
            $variantSpecific !== [] => 'variant_specific',
            array_filter($shared, static fn (array $item): bool => $item['classification'] === 'shared_normalized') !== [] => 'shared_normalized',
            $shared !== [] => 'shared_exact',
            default => 'missing_or_incomplete',
        };

        return [
            'classification' => $classification,
            'shared_keys' => $shared,
            'variant_specific_keys' => $variantSpecific,
            'conflicting_keys' => $conflicting,
            'missing_keys' => $missing,
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function variantInspection(array $productIds, array $variants): array
    {
        $byProduct = array_fill_keys($productIds, []);
        $allAttributeKeys = [];

        foreach ($variants as $variant) {
            $sourceProductId = (int) ($variant['source_product_id'] ?? 0);
            if (! isset($byProduct[$sourceProductId])) {
                continue;
            }

            $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
            $normalizedAttributes = [];
            foreach ($attributes as $key => $value) {
                $normalizedKey = $this->normalizedKey((string) $key);
                $normalizedAttributes[$normalizedKey] = $value;
                $allAttributeKeys[$normalizedKey] = true;
            }
            ksort($normalizedAttributes, SORT_STRING);

            $byProduct[$sourceProductId][] = [
                'variant_id' => (int) $variant['id'],
                'current_product_id' => (int) $variant['product_id'],
                'sku' => (string) $variant['sku'],
                'barcode' => $variant['barcode'],
                'weight' => $variant['weight'],
                'attributes' => $normalizedAttributes,
            ];
        }

        foreach ($byProduct as &$items) {
            usort($items, static fn (array $left, array $right): int => $left['variant_id'] <=> $right['variant_id']);
        }
        unset($items);

        $dimensions = [];
        foreach (self::DIMENSION_ALIASES as $dimension => $aliases) {
            $values = [];
            foreach ($byProduct as $productId => $productVariants) {
                foreach ($productVariants as $variant) {
                    if ($dimension === 'barcode' && ! $this->isMissing($variant['barcode'])) {
                        $values[$productId][] = (string) $variant['barcode'];
                    }
                    if ($dimension === 'weight' && ! $this->isMissing($variant['weight'])) {
                        $values[$productId][] = (string) $variant['weight'];
                    }
                    foreach ($variant['attributes'] as $key => $value) {
                        if (in_array($key, $aliases, true) && ! $this->isMissing($value)) {
                            $values[$productId][] = (string) $value;
                        }
                    }
                }
            }
            foreach ($values as &$items) {
                $items = array_values(array_unique($items));
                sort($items, SORT_NATURAL | SORT_FLAG_CASE);
            }
            unset($items);
            if (count(array_unique(array_map($this->canonicalValue(...), $values))) > 1) {
                ksort($values, SORT_NUMERIC);
                $dimensions[$dimension] = $values;
            }
        }

        ksort($dimensions, SORT_STRING);
        $knownKeys = array_flip(array_merge(...array_values(self::DIMENSION_ALIASES)));
        $otherKeys = array_values(array_diff(array_keys($allAttributeKeys), array_keys($knownKeys)));
        sort($otherKeys, SORT_STRING);
        $otherVaryingKeys = [];

        foreach ($otherKeys as $key) {
            $values = [];
            foreach ($byProduct as $productId => $productVariants) {
                foreach ($productVariants as $variant) {
                    if (array_key_exists($key, $variant['attributes']) && ! $this->isMissing($variant['attributes'][$key])) {
                        $values[$productId][] = (string) $variant['attributes'][$key];
                    }
                }
            }
            foreach ($values as &$items) {
                $items = array_values(array_unique($items));
                sort($items, SORT_NATURAL | SORT_FLAG_CASE);
            }
            unset($items);

            if (count($values) > 1 && count(array_unique(array_map($this->canonicalValue(...), $values))) > 1) {
                ksort($values, SORT_NUMERIC);
                $otherVaryingKeys[$key] = $values;
            }
        }

        return [
            'by_source_product' => $byProduct,
            'varying_dimensions' => $dimensions,
            'other_attribute_keys' => $otherKeys,
            'other_varying_specification_keys' => $otherVaryingKeys,
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function operationalContent(array $productIds, array $data): array
    {
        $counts = array_fill_keys($productIds, ['reviews' => 0, 'questions' => 0, 'favorites' => 0]);
        foreach (['reviews', 'questions', 'favorites'] as $type) {
            foreach ($data[$type] ?? [] as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                if (isset($counts[$productId])) {
                    $counts[$productId][$type]++;
                }
            }
        }

        $reviewUsers = $this->duplicateOwnership($data['reviews'] ?? [], 'user_id');
        $favoriteUsers = $this->duplicateOwnership($data['favorites'] ?? [], 'user_id');
        $questionKeys = [];
        foreach ($data['questions'] ?? [] as $row) {
            if ($this->isMissing($row['source'] ?? null) || $this->isMissing($row['external_key'] ?? null)) {
                continue;
            }
            $key = $row['source'].'|'.$row['external_key'];
            $questionKeys[$key][(int) $row['product_id']] = true;
        }
        $questionConflicts = array_keys(array_filter($questionKeys, static fn (array $ids): bool => count($ids) > 1));
        sort($questionConflicts, SORT_STRING);
        ksort($counts, SORT_NUMERIC);

        return [
            'counts_by_product' => $counts,
            'ownership_conflicts' => [
                'review_user_ids' => $reviewUsers,
                'favorite_user_ids' => $favoriteUsers,
                'question_source_keys' => $questionConflicts,
            ],
            'has_ownership_conflicts' => $reviewUsers !== [] || $favoriteUsers !== [] || $questionConflicts !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $perField
     * @param  array<string, mixed>  $specificationDiff
     * @param  array<string, mixed>  $operations
     * @param  list<array<string, mixed>>  $images
     * @return array{decision: string, reasons: list<string>}
     */
    private function mergeDecision(
        array $group,
        array $perField,
        array $specificationDiff,
        array $operations,
        array $images,
    ): array {
        if (in_array((string) ($group['group_identifier'] ?? ''), self::AUDITED_DO_NOT_MERGE_GROUPS, true)) {
            return [
                'decision' => 'do_not_merge',
                'reasons' => ['audited_substantive_content_difference'],
            ];
        }

        $reasons = [];
        $criticalFields = ['name', 'ingredients', 'usage_instructions', 'origin_country', 'category_id', 'brand_id'];
        $criticalConflicts = array_values(array_filter(
            $criticalFields,
            static fn (string $field): bool => ($perField[$field]['classification'] ?? null) === 'semantic_conflict',
        ));

        if (($group['classification'] ?? null) === 'rejected') {
            $reasons[] = 't1_1_hard_gate_rejected';
        }
        foreach ($this->historicalHardFailures($group) as $failure) {
            $reasons[] = 't1_1_hard_gate_failed:'.$failure;
        }
        if (($group['bundle_combo_flags'] ?? []) !== []) {
            $reasons[] = 't1_1_bundle_combo_detected';
        }
        if (($group['content_conflicts'] ?? []) !== []) {
            $reasons[] = 't1_1_product_content_conflict';
        }
        foreach ($criticalConflicts as $field) {
            $reasons[] = 'critical_semantic_conflict:'.$field;
        }
        if (($specificationDiff['conflicting_keys'] ?? []) !== []) {
            $reasons[] = 'conflicting_specification_keys';
        }

        if ($reasons !== []) {
            sort($reasons, SORT_STRING);

            return ['decision' => 'do_not_merge', 'reasons' => $reasons];
        }

        if ($operations['has_ownership_conflicts']) {
            $reasons[] = 'review_question_or_favorite_ownership_conflict';
        }
        foreach (['short_description', 'description'] as $field) {
            if (($perField[$field]['classification'] ?? null) === 'semantic_conflict') {
                $reasons[] = 'content_semantic_conflict:'.$field;
            }
        }
        foreach (['ingredients', 'usage_instructions', 'origin_country', 'category_id', 'brand_id'] as $field) {
            if (($perField[$field]['classification'] ?? null) === 'missing_or_incomplete'
                && ($perField[$field]['missing_state'] ?? null) === 'partial') {
                $reasons[] = 'incomplete_critical_content:'.$field;
            }
        }
        foreach (['external_rating', 'external_review_count'] as $field) {
            $classification = $perField[$field]['classification'] ?? null;
            $requiresDecision = in_array($classification, ['variant_specific', 'semantic_conflict'], true)
                || ($classification === 'missing_or_incomplete'
                    && ($perField[$field]['missing_state'] ?? null) === 'partial');
            if ($requiresDecision) {
                $reasons[] = 'external_aggregate_business_decision:'.$field;
            }
        }

        if ($reasons !== []) {
            $reasons = array_values(array_unique($reasons));
            sort($reasons, SORT_STRING);

            return ['decision' => 'manual_review', 'reasons' => $reasons];
        }

        $productScopedImages = array_filter(
            $images,
            static fn (array $image): bool => $image['product_variant_id'] === null,
        );
        $unbackedSpecifications = array_filter(
            $specificationDiff['variant_specific_keys'] ?? [],
            static fn (array $item): bool => ! $item['backed_by_variant_attribute'],
        );

        if ($productScopedImages !== [] || $unbackedSpecifications !== []) {
            if ($productScopedImages !== []) {
                $reasons[] = 'product_scoped_images_require_variant_attribution';
            }
            if ($unbackedSpecifications !== []) {
                $reasons[] = 'variant_specific_specifications_require_attribute_move';
            }
            sort($reasons, SORT_STRING);

            return ['decision' => 'merge_after_variant_content_move', 'reasons' => $reasons];
        }

        return ['decision' => 'merge_ready', 'reasons' => ['content_is_shared_or_already_variant_attributed']];
    }

    /** @param array<string, mixed> $group */
    private function auditedContentResolution(array $group, string $field): ?string
    {
        $groupIdentifier = (string) ($group['group_identifier'] ?? '');

        return self::AUDITED_CONTENT_RESOLUTIONS[$groupIdentifier][$field] ?? null;
    }

    /** @param array<string, mixed> $group @return list<string> */
    private function historicalHardFailures(array $group): array
    {
        $failures = array_values(array_filter(
            $group['failed_hard_gates'] ?? [],
            static fn (mixed $failure): bool => is_string($failure) && $failure !== '',
        ));
        sort($failures, SORT_STRING);

        return $failures;
    }

    /** @param list<int> $productIds @param list<array<string, mixed>> $images @return array<string, mixed> */
    private function imageComparison(array $productIds, array $images): array
    {
        $byProduct = array_fill_keys($productIds, []);
        foreach ($images as $image) {
            $productId = (int) ($image['source_product_id'] ?? 0);
            if (isset($byProduct[$productId])) {
                $byProduct[$productId][] = [
                    'id' => (int) $image['id'],
                    'image_url' => (string) $image['image_url'],
                    'product_variant_id' => $image['product_variant_id'] !== null ? (int) $image['product_variant_id'] : null,
                    'sort_order' => (int) $image['sort_order'],
                    'is_primary' => (bool) $image['is_primary'],
                ];
            }
        }
        foreach ($byProduct as &$items) {
            usort($items, static fn (array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);
        }
        unset($items);

        if (array_filter($byProduct) === []) {
            $classification = 'missing_or_incomplete';
        } elseif (array_filter($byProduct, static fn (array $items): bool => $items === []) !== []) {
            $classification = 'missing_or_incomplete';
        } else {
            $urls = array_map(
                static fn (array $items): string => json_encode(array_column($items, 'image_url'), JSON_THROW_ON_ERROR),
                $byProduct,
            );
            $classification = count(array_unique($urls)) === 1 ? 'shared_exact' : 'variant_specific';
        }

        return ['classification' => $classification, 'by_product' => $byProduct];
    }

    /** @param list<array<string, mixed>> $rows @return list<int|string> */
    private function duplicateOwnership(array $rows, string $key): array
    {
        $owners = [];
        foreach ($rows as $row) {
            if ($this->isMissing($row[$key] ?? null)) {
                continue;
            }
            $owners[(string) $row[$key]][(int) $row['product_id']] = true;
        }
        $duplicates = array_keys(array_filter($owners, static fn (array $ids): bool => count($ids) > 1));
        sort($duplicates, SORT_NATURAL);

        return array_map(static fn (string $value): int|string => ctype_digit($value) ? (int) $value : $value, $duplicates);
    }

    /** @param array<int, mixed> $normalizedValues @param array<string, mixed> $group */
    private function differsOnlyByVariantDimensions(array $normalizedValues, array $group): bool
    {
        $tokens = [];
        foreach (($group['detected_variant_dimensions']['values'] ?? []) as $values) {
            foreach ((array) $values as $value) {
                $normalized = $this->normalizedValue($value);
                if ($normalized !== '') {
                    $tokens[] = $normalized;
                    $tokens[] = str_replace(' ', '', $normalized);
                }
            }
        }
        $tokens = array_values(array_unique(array_filter($tokens)));
        usort($tokens, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $cores = [];
        foreach ($normalizedValues as $productId => $value) {
            $core = str_replace($tokens, ' ', $value);
            $cores[$productId] = trim(preg_replace('/\s+/u', ' ', $core) ?? '');
        }

        return $tokens !== [] && count(array_unique($cores)) === 1;
    }

    /** @param array<int, mixed> $values @param array<string, mixed> $variantInspection */
    private function valuesBackedByVariantAttributes(string $key, array $values, array $variantInspection): bool
    {
        foreach ($values as $productId => $value) {
            $matched = false;
            foreach ($variantInspection['by_source_product'][$productId] ?? [] as $variant) {
                foreach ($variant['attributes'] as $attributeKey => $attributeValue) {
                    if (($attributeKey === $key || $this->normalizedValue($attributeValue) === $this->normalizedValue($value))) {
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return $values !== [];
    }

    private function dimensionForKey(string $key): ?string
    {
        foreach (self::DIMENSION_ALIASES as $dimension => $aliases) {
            if (in_array($key, $aliases, true) || in_array(Str::after($key, 'spec_'), $aliases, true)) {
                return $dimension;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $products @return array<int, mixed> */
    private function valuesByProduct(array $products, string $field): array
    {
        $values = [];
        foreach ($products as $product) {
            $values[(int) $product['id']] = $product[$field] ?? null;
        }

        return $values;
    }

    /** @param array<int, mixed> $values @return array<int, array<string, mixed>> */
    private function evidenceForValues(array $values): array
    {
        $evidence = [];
        foreach ($values as $productId => $value) {
            $missing = $this->isMissing($value);
            $item = [
                'missing' => $missing,
                'fingerprint' => $missing ? null : hash('sha256', $this->canonicalValue($value)),
            ];
            if (is_scalar($value) || $value === null) {
                $item['value'] = is_string($value) ? Str::limit($value, 180, '…') : $value;
            } else {
                $item['preview'] = Str::limit($this->canonicalValue($value), 180, '…');
            }
            $evidence[(int) $productId] = $item;
        }
        ksort($evidence, SORT_NUMERIC);

        return $evidence;
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === []);
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $this->canonicalArray($value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function normalizedValue(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            return $this->canonicalValue($value);
        }
        $text = Str::ascii(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = Str::lower($text);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '');
    }

    private function normalizedKey(string $key): string
    {
        return Str::slug($key, '_');
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

    /** @param array<mixed> $values @return list<int> */
    private function integerList(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @param array<mixed> $items @return list<array<string, mixed>> */
    private function sortedById(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return array_values($items);
    }
}
