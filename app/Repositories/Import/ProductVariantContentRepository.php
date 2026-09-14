<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class ProductVariantContentRepository
{
    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    public function groupData(array $group): array
    {
        $productIds = $this->integerList($group['candidate_product_ids'] ?? []);
        $variantIds = $this->integerList($group['candidate_variant_ids'] ?? []);

        $products = Product::query()
            ->withTrashed()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->get([
                'id', 'source', 'external_id', 'name', 'short_description', 'description',
                'ingredients', 'usage_instructions', 'specifications', 'origin_country',
                'category_id', 'brand_id', 'external_rating', 'external_review_count',
                'is_active', 'source_variant_groups', 'deleted_at',
            ])
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'source' => $product->source,
                'external_id' => $product->external_id,
                'name' => $product->name,
                'short_description' => $product->short_description,
                'description' => $product->description,
                'ingredients' => $product->ingredients,
                'usage_instructions' => $product->usage_instructions,
                'specifications' => $product->specifications ?? [],
                'origin_country' => $product->origin_country,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'external_rating' => $product->external_rating,
                'external_review_count' => $product->external_review_count,
                'is_active' => $product->is_active,
                'source_variant_groups' => $product->source_variant_groups ?? [],
                'deleted_at' => $product->deleted_at?->toAtomString(),
            ])
            ->all();

        $identityToProduct = [];
        foreach ($products as $product) {
            if ($product['source'] !== null && $product['external_id'] !== null) {
                $identityToProduct[$product['source'].'|'.$product['external_id']] = $product['id'];
            }
        }

        $variants = ProductVariant::query()
            ->withTrashed()
            ->whereIn('id', $variantIds)
            ->orderBy('id')
            ->get([
                'id', 'product_id', 'source', 'external_id', 'name', 'sku', 'barcode',
                'attributes', 'price', 'sale_price', 'weight', 'deleted_at',
            ])
            ->map(function (ProductVariant $variant) use ($identityToProduct, $productIds): array {
                $identity = $variant->source !== null && $variant->external_id !== null
                    ? $variant->source.'|'.$variant->external_id
                    : null;
                $sourceProductId = $identity !== null ? ($identityToProduct[$identity] ?? null) : null;

                if ($sourceProductId === null && in_array($variant->product_id, $productIds, true)) {
                    $sourceProductId = $variant->product_id;
                }

                return [
                    'id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'source_product_id' => $sourceProductId,
                    'source' => $variant->source,
                    'external_id' => $variant->external_id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'attributes' => $variant->attributes ?? [],
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'weight' => $variant->weight,
                    'deleted_at' => $variant->deleted_at?->toAtomString(),
                ];
            })
            ->all();

        $variantToSourceProduct = [];
        foreach ($variants as $variant) {
            if ($variant['source_product_id'] !== null) {
                $variantToSourceProduct[$variant['id']] = $variant['source_product_id'];
            }
        }

        $images = ProductImage::query()
            ->where(function ($query) use ($productIds, $variantIds): void {
                $query->whereIn('product_id', $productIds)
                    ->orWhereIn('product_variant_id', $variantIds);
            })
            ->orderBy('id')
            ->get(['id', 'product_id', 'product_variant_id', 'image_url', 'is_primary', 'sort_order'])
            ->map(static function (ProductImage $image) use ($variantToSourceProduct, $productIds): array {
                $sourceProductId = $image->product_variant_id !== null
                    ? ($variantToSourceProduct[$image->product_variant_id] ?? null)
                    : null;

                if ($sourceProductId === null && in_array($image->product_id, $productIds, true)) {
                    $sourceProductId = $image->product_id;
                }

                return [
                    'id' => $image->id,
                    'product_id' => $image->product_id,
                    'source_product_id' => $sourceProductId,
                    'product_variant_id' => $image->product_variant_id,
                    'image_url' => $image->image_url,
                    'is_primary' => $image->is_primary,
                    'sort_order' => $image->sort_order,
                ];
            })
            ->all();

        return [
            'products' => $products,
            'variants' => $variants,
            'images' => $images,
            'reviews' => $this->rows('reviews', $productIds, [
                'id', 'product_id', 'product_variant_id', 'user_id', 'order_item_id',
                'source', 'source_key', 'deleted_at',
            ]),
            'questions' => $this->rows('product_questions', $productIds, [
                'id', 'product_id', 'source', 'external_key',
            ]),
            'favorites' => $this->rows('product_favorites', $productIds, [
                'id', 'product_id', 'user_id',
            ]),
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $productIds, array $columns): array
    {
        return DB::table($table)
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get($columns)
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param array<mixed> $values @return list<int> */
    private function integerList(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }
}
