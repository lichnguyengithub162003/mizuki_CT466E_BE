<?php

namespace App\Support\Import;

use App\Models\Product;

final class ProductVariantNormalizationRetirement
{
    private const MARKER = '_mizuki_variant_normalization';

    /** @return array<string|int, mixed> */
    public function mark(Product $product, string $groupIdentifier, int $canonicalProductId): array
    {
        $metadata = is_array($product->source_variant_groups) ? $product->source_variant_groups : [];
        $metadata[self::MARKER] = [
            'group_identifier' => $groupIdentifier,
            'canonical_product_id' => $canonicalProductId,
            'retired_product_id' => (int) $product->id,
        ];

        return $metadata;
    }

    /** @return array{group_identifier: string, canonical_product_id: int, retired_product_id: int}|null */
    public function marker(Product $product): ?array
    {
        $value = is_array($product->source_variant_groups)
            ? ($product->source_variant_groups[self::MARKER] ?? null)
            : null;

        if (! is_array($value)
            || ! is_string($value['group_identifier'] ?? null)
            || ! is_numeric($value['canonical_product_id'] ?? null)
            || ! is_numeric($value['retired_product_id'] ?? null)) {
            return null;
        }

        return [
            'group_identifier' => $value['group_identifier'],
            'canonical_product_id' => (int) $value['canonical_product_id'],
            'retired_product_id' => (int) $value['retired_product_id'],
        ];
    }
}
