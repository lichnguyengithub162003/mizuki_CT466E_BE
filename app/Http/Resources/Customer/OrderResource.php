<?php

namespace App\Http\Resources\Customer;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_method' => $this->fulfillment_method === 'shipping' ? 'delivery' : 'pickup',
            'payment_method' => $this->payment_method->value,
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'delivery_address' => $this->user_address_id === null ? null : [
                'address_id' => $this->user_address_id,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'province_code' => $this->province_code,
                'ghn_district_id' => $this->ghn_district_id,
                'ghn_ward_code' => $this->ghn_ward_code,
                'full_address' => $this->shipping_address,
            ],
            'applied_promotion' => $this->promotion_id === null ? null : [
                'id' => $this->promotion_id,
                'code' => $this->promotion?->code,
                'name' => $this->promotion?->name,
                'discount_amount' => $this->discount_amount,
            ],
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'variant_attributes' => $item->variant_attributes,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()->all(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->shipping_fee,
            'total_amount' => $this->total_amount,
            'cancellation' => $this->status->value !== 'cancelled' ? null : [
                'reason_type' => $this->cancellation_reason_type,
                'reason' => $this->cancellation_reason,
                'cancelled_at' => $this->cancelled_at?->toISOString(),
            ],
            'placed_at' => $this->placed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
