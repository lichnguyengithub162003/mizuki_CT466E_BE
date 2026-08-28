<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->productVariant;

        return [
            'id' => $this->id,
            'branch' => ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name],
            'product' => ['id' => $variant->product->id, 'name' => $variant->product->name],
            'variant' => ['id' => $variant->id, 'name' => $variant->name],
            'sku' => $variant->sku, 'barcode' => $variant->barcode,
            'quantity' => $this->quantity, 'reserved_quantity' => $this->reserved_quantity,
            'available_quantity' => $this->quantity - $this->reserved_quantity,
            'reorder_level' => $this->reorder_level,
            'low_stock' => $this->quantity <= $this->reorder_level,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
