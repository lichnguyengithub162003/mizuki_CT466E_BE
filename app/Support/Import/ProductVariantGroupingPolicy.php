<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

final class ProductVariantGroupingPolicy
{
    /** @var array<string, list<string>> */
    private const DIMENSION_ALIASES = [
        'volume' => ['dung_tich', 'the_tich', 'volume', 'capacity'],
        'size' => ['kich_thuoc', 'trong_luong', 'khoi_luong', 'size', 'weight'],
        'color' => ['mau', 'mau_sac', 'tone', 'color', 'shade'],
        'scent' => ['mui_huong', 'huong', 'scent', 'fragrance'],
        'type' => ['loai', 'phan_loai', 'dang', 'type'],
    ];

    /** @var list<string> */
    private const PRODUCT_TYPE_KEYS = [
        'spec_loai_san_pham',
        'spec_dang_san_pham',
        'spec_cong_dung',
        'loai_san_pham',
        'dang_san_pham',
    ];

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function profile(array $product): array
    {
        $dimensions = $this->dimensions($product);
        $attributeDimensions = $this->dimensions($product, false);
        $normalizedName = $this->normalizeText((string) ($product['name'] ?? ''));
        $coreName = $this->coreName($normalizedName, $dimensions);

        return [
            'product_id' => (int) $product['id'],
            'variant_ids' => $this->sortedIntegers($product['variant_ids'] ?? []),
            'source' => $this->nullableString($product['source'] ?? null),
            'external_id' => $this->nullableString($product['external_id'] ?? null),
            'brand_id' => (int) ($product['brand_id'] ?? 0),
            'brand_name' => (string) ($product['brand_name'] ?? ''),
            'category_id' => (int) ($product['category_id'] ?? 0),
            'category_name' => (string) ($product['category_name'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'normalized_name' => $normalizedName,
            'normalized_core_name' => $coreName,
            'dimensions' => $dimensions,
            'attribute_dimensions' => $attributeDimensions,
            'skus' => $this->sortedStrings($product['skus'] ?? []),
            'barcodes' => $this->sortedStrings(array_filter(
                $product['barcodes'] ?? [],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            )),
            'bundle_flags' => $this->bundleFlags($normalizedName),
            'content' => $this->contentProfile($product),
            'source_relationship_ids' => $this->sourceRelationshipIds(
                is_array($product['source_variant_groups'] ?? null)
                    ? $product['source_variant_groups']
                    : [],
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<int, array<string, int>>  $operationalByProduct
     * @return array<string, mixed>
     */
    public function evaluate(array $profiles, array $operationalByProduct = []): array
    {
        usort($profiles, static fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

        $sources = $this->uniqueValues($profiles, 'source');
        $brands = $this->uniqueValues($profiles, 'brand_id');
        $categories = $this->uniqueValues($profiles, 'category_id');
        $coreNames = $this->uniqueValues($profiles, 'normalized_core_name');
        $skus = array_merge(...array_map(static fn (array $profile): array => $profile['skus'], $profiles));
        $barcodes = array_merge(...array_map(static fn (array $profile): array => $profile['barcodes'], $profiles));
        $dimensionEvidence = $this->dimensionEvidence($profiles);
        $bundleFlags = array_values(array_unique(array_merge(
            ...array_map(static fn (array $profile): array => $profile['bundle_flags'], $profiles),
        )));
        sort($bundleFlags, SORT_STRING);
        $contentConflicts = $this->contentConflicts($profiles);
        $metadata = $this->metadataEvidence($profiles);
        $operational = $this->operationalEvidence($profiles, $operationalByProduct);

        $gates = [
            'same_source' => count($sources) === 1 && $sources[0] !== null,
            'same_brand' => count($brands) === 1 && (int) $brands[0] > 0,
            'compatible_leaf_category' => count($categories) === 1 && (int) $categories[0] > 0,
            'compatible_normalized_core_name' => count($coreNames) === 1 && $coreNames[0] !== '',
            'differences_explainable_by_variant_dimensions' => $dimensionEvidence['attribute_backed_varying'] !== [],
            'no_sku_or_barcode_conflict' => count($skus) === count(array_unique($skus))
                && count($barcodes) === count(array_unique($barcodes)),
            'not_bundle_combo_gift_set_or_pack' => $bundleFlags === [],
            'no_contradictory_product_content' => $contentConflicts === [],
            'source_relationship_metadata_compatible' => ! $metadata['contradictory'],
            'operational_conflicts_detected' => true,
        ];
        $failedGates = array_keys(array_filter($gates, static fn (bool $passed): bool => ! $passed));
        $blockingFailures = array_values(array_diff($failedGates, ['operational_conflicts_detected']));
        $highImpactOperations = array_sum(array_intersect_key(
            $operational['totals'],
            array_flip(['cart_items', 'order_items', 'reviews', 'questions', 'favorites']),
        ));
        $ambiguousDimension = array_intersect($dimensionEvidence['varying'], ['type']) !== [];

        if ($blockingFailures !== []) {
            $classification = 'rejected';
        } elseif ($highImpactOperations > 0 || $ambiguousDimension) {
            $classification = 'manual_review';
        } else {
            $classification = 'auto_safe';
        }

        $productIds = array_column($profiles, 'product_id');
        $externalIds = array_values(array_filter(
            array_column($profiles, 'external_id'),
            static fn (mixed $value): bool => $value !== null,
        ));
        sort($externalIds, SORT_NATURAL);

        return [
            'classification' => $classification,
            'candidate_product_ids' => $productIds,
            'candidate_variant_ids' => $this->sortedIntegers(array_merge(
                ...array_map(static fn (array $profile): array => $profile['variant_ids'], $profiles),
            )),
            'source_external_ids' => array_map(
                static fn (array $profile): array => [
                    'source' => $profile['source'],
                    'external_id' => $profile['external_id'],
                ],
                $profiles,
            ),
            'brand' => count($brands) === 1 ? [
                'id' => $profiles[0]['brand_id'],
                'name' => $profiles[0]['brand_name'],
            ] : null,
            'category' => count($categories) === 1 ? [
                'id' => $profiles[0]['category_id'],
                'name' => $profiles[0]['category_name'],
            ] : null,
            'normalized_core_name' => count($coreNames) === 1 ? $coreNames[0] : null,
            'detected_variant_dimensions' => $dimensionEvidence,
            'evidence' => [
                'hard_gates' => $gates,
                'source_relationships' => $metadata,
                'member_names' => array_map(
                    static fn (array $profile): array => [
                        'product_id' => $profile['product_id'],
                        'name' => $profile['name'],
                        'normalized_name' => $profile['normalized_name'],
                    ],
                    $profiles,
                ),
            ],
            'failed_hard_gates' => $failedGates,
            'bundle_combo_flags' => $bundleFlags,
            'content_conflicts' => $contentConflicts,
            'operational_conflicts' => $operational,
            'recommended_canonical_product_id' => $classification === 'auto_safe'
                ? min($productIds)
                : null,
            '_external_ids' => $externalIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, list<string>>
     */
    private function dimensions(array $product, bool $includeName = true): array
    {
        $found = [];

        foreach ($product['variant_attributes'] ?? [] as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            foreach ($attributes as $key => $value) {
                if (! is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }

                $normalizedKey = Str::slug((string) $key, '_');

                foreach (self::DIMENSION_ALIASES as $dimension => $aliases) {
                    if (in_array($normalizedKey, $aliases, true)
                        || in_array(Str::after($normalizedKey, 'spec_'), $aliases, true)) {
                        $found[$dimension][] = $this->normalizeDimensionValue((string) $value);
                    }
                }
            }
        }

        if ($includeName) {
            $name = $this->normalizeText((string) ($product['name'] ?? ''));
            preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:ml|lit|l)\b/u', $name, $volumeMatches);
            preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:mg|gr|g|kg)\b/u', $name, $sizeMatches);

            foreach ($volumeMatches[0] ?? [] as $value) {
                $found['volume'][] = $this->normalizeDimensionValue($value);
            }

            foreach ($sizeMatches[0] ?? [] as $value) {
                $found['size'][] = $this->normalizeDimensionValue($value);
            }
        }

        foreach ($found as $dimension => $values) {
            $found[$dimension] = $this->sortedStrings($values);
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * @param  array<string, list<string>>  $dimensions
     */
    private function coreName(string $name, array $dimensions): string
    {
        $core = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:ml|lit|l|mg|gr|g|kg)\b/u', ' ', $name) ?? $name;

        foreach ($dimensions as $values) {
            foreach ($values as $value) {
                if (mb_strlen($value) >= 2) {
                    $core = str_replace($value, ' ', $core);
                }
            }
        }

        return $this->normalizeText($core);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, array{fingerprint: string, preview: string}>
     */
    private function contentProfile(array $product): array
    {
        $content = [];

        foreach (['ingredients', 'usage_instructions', 'origin_country'] as $field) {
            $normalized = $this->normalizeText((string) ($product[$field] ?? ''));

            if ($normalized !== '') {
                $content[$field] = $this->contentEvidence($normalized);
            }
        }

        $specifications = is_array($product['specifications'] ?? null)
            ? $product['specifications']
            : [];

        foreach ($specifications as $key => $value) {
            $normalizedKey = Str::slug((string) $key, '_');

            if (in_array($normalizedKey, self::PRODUCT_TYPE_KEYS, true) && is_scalar($value)) {
                $normalized = $this->normalizeText((string) $value);

                if ($normalized !== '') {
                    $content['product_type:'.$normalizedKey] = $this->contentEvidence($normalized);
                }
            }
        }

        ksort($content, SORT_STRING);

        return $content;
    }

    /** @return list<string> */
    private function bundleFlags(string $name): array
    {
        $patterns = [
            'bundle' => '/\bbundle\b/u',
            'combo' => '/\bcombo\b/u',
            'gift_set' => '/\b(?:gift set|set qua|qua tang)\b/u',
            'pack' => '/\b(?:pack|loc|bo \d+|hop \d+|x\s*\d+)\b/u',
        ];
        $flags = [];

        foreach ($patterns as $flag => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<string>
     */
    private function sourceRelationshipIds(array $groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['options'] ?? [] as $option) {
                if (! is_array($option)) {
                    continue;
                }

                foreach ($option['products'] ?? [] as $product) {
                    if (is_array($product) && isset($product['external_id'])) {
                        $ids[] = (string) $product['external_id'];
                    }
                }
            }
        }

        return $this->sortedStrings($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array{varying: list<string>, attribute_backed_varying: list<string>, values: array<string, list<string>>}
     */
    private function dimensionEvidence(array $profiles): array
    {
        $values = [];
        $attributeValues = [];

        foreach ($profiles as $profile) {
            foreach ($profile['dimensions'] as $dimension => $dimensionValues) {
                $values[$dimension] = array_merge($values[$dimension] ?? [], $dimensionValues);
            }

            foreach ($profile['attribute_dimensions'] as $dimension => $dimensionValues) {
                $attributeValues[$dimension] = array_merge(
                    $attributeValues[$dimension] ?? [],
                    $dimensionValues,
                );
            }
        }

        foreach ($values as $dimension => $dimensionValues) {
            $values[$dimension] = $this->sortedStrings($dimensionValues);
        }

        ksort($values, SORT_STRING);
        $varying = array_keys(array_filter($values, static fn (array $items): bool => count($items) > 1));
        $attributeBackedVarying = [];

        foreach ($attributeValues as $dimension => $dimensionValues) {
            if (count($this->sortedStrings($dimensionValues)) > 1) {
                $attributeBackedVarying[] = $dimension;
            }
        }
        sort($attributeBackedVarying, SORT_STRING);

        return [
            'varying' => $varying,
            'attribute_backed_varying' => $attributeBackedVarying,
            'values' => $values,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return list<array{field: string, product_values: array<int, array{fingerprint: string, preview: string}>}>
     */
    private function contentConflicts(array $profiles): array
    {
        $fields = [];

        foreach ($profiles as $profile) {
            foreach ($profile['content'] as $field => $value) {
                $fields[$field][$profile['product_id']] = $value;
            }
        }

        $conflicts = [];

        foreach ($fields as $field => $productValues) {
            $fingerprints = array_column($productValues, 'fingerprint');

            if (count(array_unique($fingerprints)) > 1) {
                ksort($productValues, SORT_NUMERIC);
                $conflicts[] = ['field' => $field, 'product_values' => $productValues];
            }
        }

        usort($conflicts, static fn (array $left, array $right): int => $left['field'] <=> $right['field']);

        return $conflicts;
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array{linked_pairs: int, unresolved_external_ids: list<string>, contradictory: bool}
     */
    private function metadataEvidence(array $profiles): array
    {
        $candidateIds = array_fill_keys(array_filter(array_column($profiles, 'external_id')), true);
        $allReferences = [];
        $linkedPairs = [];

        foreach ($profiles as $profile) {
            foreach ($profile['source_relationship_ids'] as $externalId) {
                $allReferences[$externalId] = true;

                if (isset($candidateIds[$externalId]) && $externalId !== $profile['external_id']) {
                    $pair = [$profile['external_id'], $externalId];
                    sort($pair, SORT_NATURAL);
                    $linkedPairs[implode('|', $pair)] = true;
                }
            }
        }

        $unresolved = array_values(array_diff(array_keys($allReferences), array_keys($candidateIds)));
        sort($unresolved, SORT_NATURAL);

        return [
            'linked_pairs' => count($linkedPairs),
            'unresolved_external_ids' => array_slice($unresolved, 0, 20),
            'contradictory' => $allReferences !== [] && $linkedPairs === [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<int, array<string, int>>  $operationalByProduct
     * @return array{by_product: array<int, array<string, int>>, totals: array<string, int>}
     */
    private function operationalEvidence(array $profiles, array $operationalByProduct): array
    {
        $keys = ['inventory', 'cart_items', 'order_items', 'reviews', 'questions', 'favorites'];
        $byProduct = [];
        $totals = array_fill_keys($keys, 0);

        foreach ($profiles as $profile) {
            $productId = $profile['product_id'];
            $counts = [];

            foreach ($keys as $key) {
                $counts[$key] = (int) ($operationalByProduct[$productId][$key] ?? 0);
                $totals[$key] += $counts[$key];
            }

            $byProduct[$productId] = $counts;
        }

        return ['by_product' => $byProduct, 'totals' => $totals];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<mixed>
     */
    private function uniqueValues(array $items, string $key): array
    {
        $values = array_values(array_unique(array_column($items, $key), SORT_REGULAR));
        usort($values, static fn (mixed $left, mixed $right): int => (string) $left <=> (string) $right);

        return $values;
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function normalizeDimensionValue(string $value): string
    {
        return str_replace(' ', '', $this->normalizeText($value));
    }

    /** @return array{fingerprint: string, preview: string} */
    private function contentEvidence(string $value): array
    {
        return [
            'fingerprint' => hash('sha256', $value),
            'preview' => Str::limit($value, 120, '…'),
        ];
    }

    /** @param  array<mixed>  $values */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /** @param  array<mixed>  $values */
    private function sortedIntegers(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
