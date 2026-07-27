<?php

namespace App\Http\Resources\Cashier;

use App\Models\PosSessionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosDisplayResource extends JsonResource
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
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'items' => $this->items->map(fn (PosSessionItem $item): array => [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->unit_price * $item->quantity,
            ])->values()->all(),
            'payment_method' => $this->payment_method->value,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'bank_transfer' => $this->payment_method->value !== 'bank_transfer'
                ? null
                : $this->getAttribute('bank_transfer'),
            'order_number' => $this->order?->order_number,
            'expires_at' => $this->expires_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
