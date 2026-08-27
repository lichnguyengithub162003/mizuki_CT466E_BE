<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'delivery_method' => $this['delivery_method'],
            'branch' => $this['branch'],
            'address_id' => $this['address_id'],
            'promotion' => $this['promotion'],
            'subtotal' => $this['subtotal'],
            'discount_amount' => $this['discount_amount'],
            'shipping_fee' => $this['shipping_fee'],
            'total_amount' => $this['total_amount'],
            'expected_delivery_time' => $this['expected_delivery_time'],
            'wallet' => $this['wallet'],
            'payment_methods' => $this['payment_methods'],
            'selected_payment_method' => $this['selected_payment_method'],
        ];
    }
}
