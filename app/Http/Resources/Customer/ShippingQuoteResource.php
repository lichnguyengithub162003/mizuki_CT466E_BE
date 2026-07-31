<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'shipping_fee' => $this['shipping_fee'],
            'fee_breakdown' => $this['fee_breakdown'],
            'service_id' => $this['service_id'],
            'service_type_id' => $this['service_type_id'],
            'expected_delivery_time' => $this['expected_delivery_time'],
            'expires_at' => $this['expires_at'],
            'quote_token' => $this['quote_token'],
        ];
    }
}
