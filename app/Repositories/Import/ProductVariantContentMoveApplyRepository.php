<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Import\ProductVariantNormalizationRetirement;
use Closure;
use Illuminate\Support\Facades\DB;

final class ProductVariantContentMoveApplyRepository
{
    public function __construct(private readonly ProductVariantNormalizationRetirement $retirement) {}

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback($this), attempts: 1);
    }

    /** @param list<int> $productIds @param list<int> $variantIds @return array<string, mixed> */
    public function lockSnapshot(array $productIds, array $variantIds): array
    {
        $products = Product::query()->withTrashed()->whereIn('id', $productIds)
            ->orderBy('id')->lockForUpdate()->get();
        $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)
            ->orderBy('id')->lockForUpdate()->get();
        $images = ProductImage::query()
            ->where(fn ($query) => $query->whereIn('product_id', $productIds)
                ->orWhereIn('product_variant_id', $variantIds))
            ->orderBy('id')->lockForUpdate()->get();

        $engagement = [];
        foreach (['reviews', 'product_questions', 'product_favorites'] as $table) {
            $query = DB::table($table)->whereIn('product_id', $productIds)->orderBy('id')->lockForUpdate();
            if ($table === 'reviews') {
                $query->whereNull('deleted_at');
            }
            $engagement[$table] = $query->get(['id', 'product_id'])
                ->map(static fn (object $row): array => (array) $row)->all();
        }
        $orderItems = DB::table('order_items')->whereIn('product_id', $productIds)
            ->orderBy('id')->lockForUpdate()->get(['id', 'product_id'])
            ->map(static fn (object $row): array => (array) $row)->all();

        return [
            'products' => $products->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'source' => $product->source,
                'external_id' => $product->external_id,
                'source_url' => $product->source_url,
                'brand_id' => (int) $product->brand_id,
                'category_id' => (int) $product->category_id,
                'name' => $product->name,
                'specifications' => $product->specifications ?? [],
                'is_active' => (bool) $product->is_active,
                'deleted_at' => $product->deleted_at?->toAtomString(),
                'retirement_marker' => $this->retirement->marker($product),
            ])->keyBy('id')->all(),
            'variants' => $variants->map(static fn (ProductVariant $variant): array => [
                'id' => (int) $variant->id,
                'product_id' => (int) $variant->product_id,
                'source' => $variant->source,
                'external_id' => $variant->external_id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'attributes' => $variant->attributes ?? [],
                'deleted_at' => $variant->deleted_at?->toAtomString(),
            ])->keyBy('id')->all(),
            'images' => $images->map(static fn (ProductImage $image): array => [
                'id' => (int) $image->id,
                'product_id' => (int) $image->product_id,
                'product_variant_id' => $image->product_variant_id === null ? null : (int) $image->product_variant_id,
                'image_url' => $image->image_url,
                'alt_text' => $image->alt_text,
                'sort_order' => (int) $image->sort_order,
                'is_primary' => (bool) $image->is_primary,
            ])->keyBy('id')->all(),
            'engagement' => $engagement,
            'order_items' => $orderItems,
        ];
    }

    /** @param array<string, mixed> $specifications @return array{name_changed: int, specification_keys_removed: int} */
    public function updateCanonicalProduct(int $productId, string $name, array $specifications): array
    {
        $product = Product::query()->withTrashed()->lockForUpdate()->findOrFail($productId);
        $nameChanged = $product->name !== $name;
        $beforeKeys = array_keys($product->specifications ?? []);
        $product->name = $name;
        $product->specifications = $specifications;
        if ($product->isDirty()) {
            $product->save();
        }

        return [
            'name_changed' => $nameChanged ? 1 : 0,
            'specification_keys_removed' => count(array_diff($beforeKeys, array_keys($specifications))),
        ];
    }

    public function attributeImage(int $imageId, int $productId, int $variantId): int
    {
        $image = ProductImage::query()->lockForUpdate()->findOrFail($imageId);
        $image->product_id = $productId;
        $image->product_variant_id = $variantId;
        if (! $image->isDirty()) {
            return 0;
        }
        $image->save();

        return 1;
    }

    /** @param list<int> $variantIds */
    public function reparentVariants(array $variantIds, int $canonicalProductId): int
    {
        return ProductVariant::query()->whereIn('id', $variantIds)
            ->where('product_id', '<>', $canonicalProductId)
            ->update(['product_id' => $canonicalProductId]);
    }

    /** @param list<int> $duplicateProductIds */
    public function transferOrderItems(array $duplicateProductIds, int $canonicalProductId): int
    {
        return DB::table('order_items')->whereIn('product_id', $duplicateProductIds)
            ->update(['product_id' => $canonicalProductId]);
    }

    /** @param list<int> $duplicateProductIds */
    public function retireProducts(array $duplicateProductIds, string $groupIdentifier, int $canonicalProductId): int
    {
        $retired = 0;
        foreach ($duplicateProductIds as $productId) {
            $product = Product::query()->withTrashed()->lockForUpdate()->findOrFail($productId);
            if ($product->trashed()) {
                continue;
            }
            $product->source_variant_groups = $this->retirement->mark($product, $groupIdentifier, $canonicalProductId);
            $product->is_active = false;
            $product->save();
            $product->delete();
            $retired++;
        }

        return $retired;
    }
}
