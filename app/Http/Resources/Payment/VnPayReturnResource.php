<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VnPayReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment_number' => $this['payment_number'],
            'status' => $this['status'],
            'order_number' => $this['order_number'],
            'amount' => $this['amount'],
            'response_code' => $this['response_code'],
        ];
    }
}
