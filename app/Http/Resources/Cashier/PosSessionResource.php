<?php

namespace App\Http\Resources\Cashier;

use App\Models\PosSessionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subtotal = (int) $this->items->sum(
            fn (PosSessionItem $item): int => $item->unit_price * $item->quantity,
        );

        return [
            'code' => $this->code,
            'status' => $this->status,
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'items' => $this->items->map(fn (PosSessionItem $item): array => [
                'variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'attributes' => $item->variant_attributes,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->unit_price * $item->quantity,
            ])->values()->all(),
            'customer' => $this->customer_name === null && $this->customer_phone === null ? null : [
                'user_id' => $this->customer_user_id,
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'registered' => $this->customer_user_id !== null,
            ],
            'payment_method' => $this->payment_method->value,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'order' => $this->order === null ? null : [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
            ],
            'display_url' => url("/api/v1/pos/display/{$this->code}"),
            'expires_at' => $this->expires_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
