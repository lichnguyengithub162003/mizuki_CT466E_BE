<?php

namespace App\Http\Resources\Customer;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'items' => $this->items
                ->map(fn (CartItem $item): array => [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->productVariant->product->id,
                        'name' => $item->productVariant->product->name,
                        'slug' => $item->productVariant->product->slug,
                        'primary_image_url' => $item->productVariant->product->images->first()?->image_url,
                    ],
                    'variant' => [
                        'id' => $item->productVariant->id,
                        'name' => $item->productVariant->name,
                        'sku' => $item->productVariant->sku,
                        'attributes' => $item->productVariant->attributes,
                        'price' => $item->productVariant->price,
                        'sale_price' => $item->productVariant->sale_price,
                        'effective_price' => $item->effective_price,
                    ],
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                    'available_quantity' => $item->available_quantity,
                    'total_system_available_quantity' => $item->total_system_available_quantity,
                    'stock_warning' => $item->stock_warning,
                ])
                ->values()
                ->all(),
            'total_quantity' => $this->total_quantity,
            'total_amount' => $this->total_amount,
            'applied_promotion' => $this->promotion === null ? null : [
                'code' => $this->promotion->code,
                'discount_amount' => $this->discount_amount,
            ],
            'total_before_discount' => $this->total_before_discount,
            'discount_amount' => $this->discount_amount,
            'total_after_discount' => $this->total_after_discount,
        ];
    }
}
