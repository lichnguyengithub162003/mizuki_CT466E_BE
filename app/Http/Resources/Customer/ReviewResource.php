<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
            ],
            'product_variant' => $this->productVariant === null
                ? null
                : [
                    'id' => $this->productVariant->id,
                    'name' => $this->productVariant->name,
                    'sku' => $this->productVariant->sku,
                ],
            'order_item_id' => $this->order_item_id,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'is_visible' => (bool) $this->is_visible,
            'verified_purchase' => true,
            'reviewed_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
