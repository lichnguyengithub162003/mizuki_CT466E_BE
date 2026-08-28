<?php

namespace App\Http\Resources\Customer;

use App\Support\Customer\OrderAvailableActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderListResource extends JsonResource
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
            'payment_status' => $this->payment?->status->value,
            'payment_status_label' => $this->payment?->status->label(),
            'item_count' => (int) $this->items_count,
            'subtotal' => $this->subtotal,
            'product_discount_amount' => 0,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->fulfillment_method === 'shipping' ? $this->shipping_fee : 0,
            'shipping_discount_amount' => 0,
            'voucher_discount_amount' => $this->discount_amount,
            'total' => $this->total_amount,
            'total_amount' => $this->total_amount,
            'available_actions' => OrderAvailableActions::for($this->resource),
            'placed_at' => $this->placed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
