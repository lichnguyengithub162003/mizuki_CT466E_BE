<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VnPayUrlResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment_url' => $this['payment_url'],
            'expires_at' => $this['expires_at'],
            'payment_number' => $this['payment_number'],
        ];
    }
}
