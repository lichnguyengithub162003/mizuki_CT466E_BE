<?php

namespace App\Http\Resources\Admin;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->refunds->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'channel' => $this->channel,
            'delivery_method' => $this->fulfillment_method === 'shipping' ? 'delivery' : 'pickup',
            'payment_method' => $this->payment_method->value,
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
            'cancellation' => $this->cancellation_reason === null ? null : [
                'reason_type' => $this->cancellation_reason_type,
                'reason' => $this->cancellation_reason,
                'cancelled_at' => $this->cancelled_at?->toISOString(),
            ],
            'refund' => $refund === null ? null : [
                'id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'status' => $refund->status,
                'requested_amount' => $refund->requested_amount,
                'approved_amount' => $refund->approved_amount,
                'reviewed_by_user_id' => $refund->reviewed_by_user_id,
                'reviewed_at' => $refund->reviewed_at?->toISOString(),
            ],
            'placed_at' => $this->placed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
