<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $image = $this->relationLoaded('images') ? $this->images->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'usage_instructions' => $this->usage_instructions,
            'specifications' => $this->specifications,
            'origin_country' => $this->origin_country,
            'is_active' => (bool) $this->is_active,
            'is_featured' => (bool) $this->is_featured,
            'image_url' => $image?->image_url,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'source_url' => $this->source_url,
            'source_variant_groups' => $this->source_variant_groups,
            'brand' => $this->whenLoaded('brand', fn () => ['id' => $this->brand->id, 'name' => $this->brand->name, 'slug' => $this->brand->slug]),
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]),
            'variant_count' => (int) ($this->variants_count ?? ($this->relationLoaded('variants') ? $this->variants->count() : 0)),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'image_url' => $item->image_url,
                'alt_text' => $item->alt_text,
                'sort_order' => $item->sort_order,
                'is_primary' => (bool) $item->is_primary,
            ])->values()->all()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'attributes' => $variant->attributes,
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'effective_price' => $variant->sale_price ?? $variant->price,
                'weight' => $variant->weight,
                'sort_order' => $variant->sort_order,
                'is_active' => (bool) $variant->is_active,
                'inventory' => $variant->relationLoaded('inventories') ? $variant->inventories->map(fn ($inventory): array => [
                    'id' => $inventory->id,
                    'branch' => ['id' => $inventory->branch->id, 'name' => $inventory->branch->name],
                    'quantity' => $inventory->quantity,
                    'reserved_quantity' => $inventory->reserved_quantity,
                    'available_quantity' => $inventory->quantity - $inventory->reserved_quantity,
                ])->values()->all() : [],
            ])->values()->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
