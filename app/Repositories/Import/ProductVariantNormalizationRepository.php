<?php

namespace App\Repositories\Import;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class ProductVariantNormalizationRepository
{
    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function importedProducts(string $source): LazyCollection
    {
        return Product::query()
            ->where('source', $source)
            ->whereNotNull('external_id')
            ->with([
                'brand:id,name',
                'category:id,name',
                'variants' => fn ($query) => $query
                    ->orderBy('id')
                    ->select(['id', 'product_id', 'sku', 'barcode', 'attributes']),
            ])
            ->select([
                'id',
                'source',
                'external_id',
                'brand_id',
                'category_id',
                'name',
                'ingredients',
                'usage_instructions',
                'specifications',
                'origin_country',
                'source_variant_groups',
            ])
            ->orderBy('id')
            ->lazyById(200)
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'source' => $product->source,
                'external_id' => $product->external_id,
                'brand_id' => $product->brand_id,
                'brand_name' => $product->brand?->name,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'name' => $product->name,
                'ingredients' => $product->ingredients,
                'usage_instructions' => $product->usage_instructions,
                'specifications' => $product->specifications,
                'origin_country' => $product->origin_country,
                'source_variant_groups' => $product->source_variant_groups,
                'variant_ids' => $product->variants->pluck('id')->all(),
                'skus' => $product->variants->pluck('sku')->all(),
                'barcodes' => $product->variants->pluck('barcode')->all(),
                'variant_attributes' => $product->variants->pluck('attributes')->all(),
            ]);
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, int>  $variantProductIds
     * @return array<int, array<string, int>>
     */
    public function operationalCounts(array $productIds, array $variantProductIds): array
    {
        $result = [];

        foreach ($productIds as $productId) {
            $result[$productId] = [
                'inventory' => 0,
                'cart_items' => 0,
                'order_items' => 0,
                'reviews' => 0,
                'questions' => 0,
                'favorites' => 0,
            ];
        }

        $variantIds = array_keys($variantProductIds);

        foreach ([
            'inventory' => 'branch_inventories',
            'cart_items' => 'cart_items',
            'order_items' => 'order_items',
        ] as $key => $table) {
            foreach ($this->countsBy($table, 'product_variant_id', $variantIds) as $variantId => $count) {
                $productId = $variantProductIds[$variantId] ?? null;

                if ($productId !== null) {
                    $result[$productId][$key] += $count;
                }
            }
        }

        foreach ([
            'reviews' => 'reviews',
            'questions' => 'product_questions',
            'favorites' => 'product_favorites',
        ] as $key => $table) {
            foreach ($this->countsBy($table, 'product_id', $productIds) as $productId => $count) {
                $result[$productId][$key] = $count;
            }
        }

        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function countsBy(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table($table)
                ->whereIn($column, $chunk)
                ->selectRaw("{$column} as aggregate_id, COUNT(*) as aggregate_count")
                ->groupBy($column)
                ->get();

            foreach ($rows as $row) {
                $counts[(int) $row->aggregate_id] = (int) $row->aggregate_count;
            }
        }

        return $counts;
    }
}
