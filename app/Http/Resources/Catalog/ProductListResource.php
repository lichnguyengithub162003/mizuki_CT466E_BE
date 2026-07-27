<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
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
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
            ],
            'brand' => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ],
            'primary_image_url' => $this->images->first()?->image_url,
            'minimum_price' => (int) $this->minimum_price,
            'has_discount' => (bool) $this->has_discount,
        ];
    }
}
