<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->product->id,
            'name' => $this->product->name,
            'slug' => $this->product->slug,
            'primary_image_url' => $this->product->images->first()?->image_url,
            'minimum_price' => (int) $this->product->minimum_price,
            'brand' => $this->product->brand === null ? null : [
                'id' => $this->product->brand->id,
                'name' => $this->product->brand->name,
                'slug' => $this->product->brand->slug,
            ],
            'original_price' => $this->product->original_price === null
                ? null
                : (int) $this->product->original_price,
            'stock_state' => $this->product->stock_state,
        ];
    }
}
