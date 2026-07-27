<?php

namespace App\Http\Resources\Catalog;

use App\Models\BranchInventory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'usage_instructions' => $this->usage_instructions,
            'origin_country' => $this->origin_country,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
            ],
            'brand' => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ],
            'images' => $this->images
                ->map(fn (ProductImage $image): array => [
                    'id' => $image->id,
                    'product_variant_id' => $image->product_variant_id,
                    'image_url' => $image->image_url,
                    'alt_text' => $image->alt_text,
                    'sort_order' => $image->sort_order,
                    'is_primary' => $image->is_primary,
                ])
                ->values()
                ->all(),
            'variants' => $this->variants
                ->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'attributes' => $variant->attributes,
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'effective_price' => $variant->effective_price,
                    'weight' => $variant->weight,
                    'inventories' => $variant->inventories
                        ->map(fn (BranchInventory $inventory): array => [
                            'branch_id' => $inventory->branch_id,
                            'branch_name' => $inventory->branch->name,
                            'available_quantity' => $inventory->available_quantity,
                        ])
                        ->values()
                        ->all(),
                    'total_available_quantity' => $variant->total_available_quantity,
                    'available' => $variant->available,
                ])
                ->values()
                ->all(),
        ];
    }
}
