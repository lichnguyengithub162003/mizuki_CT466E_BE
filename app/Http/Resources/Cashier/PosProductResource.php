<?php

namespace App\Http\Resources\Cashier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'variant_id' => $this->id,
            'variant_name' => $this->name,
            'attributes' => $this->attributes,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'effective_price' => $this->effective_price,
            'available_quantity' => $this->available_quantity,
            'available' => $this->available,
        ];
    }
}
