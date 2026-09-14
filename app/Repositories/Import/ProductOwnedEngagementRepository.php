<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class ProductOwnedEngagementRepository
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
            ->get(['id', 'source', 'external_id'])
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'source' => $product->source,
                'external_id' => $product->external_id,
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
            ->get(['id', 'product_id', 'source', 'external_id', 'sku', 'barcode', 'attributes'])
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
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'attributes' => $variant->attributes ?? [],
                ];
            })
            ->all();
        $reviews = $this->rows('reviews', $productIds, [
            'id', 'product_id', 'product_variant_id', 'order_item_id', 'user_id', 'rating',
            'title', 'comment', 'source', 'source_key', 'source_author_name',
            'source_verified_purchase', 'source_date', 'variant_purchased', 'images',
            'created_at', 'updated_at', 'deleted_at',
        ]);
        $orderItemIds = $this->integerList(array_filter(array_column($reviews, 'order_item_id')));
        $orderItems = $orderItemIds === [] ? [] : DB::table('order_items')
            ->whereIn('id', $orderItemIds)
            ->orderBy('id')
            ->get(['id', 'order_id', 'product_id', 'product_variant_id', 'variant_name', 'variant_attributes'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $questions = $this->rows('product_questions', $productIds, [
            'id', 'product_id', 'source', 'external_key', 'author_name', 'question',
            'asked_at', 'source_date', 'created_at', 'updated_at',
        ]);
        $questionIds = $this->integerList(array_column($questions, 'id'));
        $answers = $questionIds === [] ? [] : DB::table('product_question_answers')
            ->whereIn('product_question_id', $questionIds)
            ->orderBy('id')
            ->get([
                'id', 'product_question_id', 'source', 'external_key', 'author_name',
                'answer', 'answered_at', 'source_date', 'created_at', 'updated_at',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'products' => $products,
            'variants' => $variants,
            'reviews' => $reviews,
            'order_items' => $orderItems,
            'questions' => $questions,
            'answers' => $answers,
            'favorites' => $this->rows('product_favorites', $productIds, [
                'id', 'product_id', 'user_id', 'created_at', 'updated_at',
            ]),
        ];
    }

    /** @param list<int> $productIds @param list<string> $columns @return list<array<string, mixed>> */
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
