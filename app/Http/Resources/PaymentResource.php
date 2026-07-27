<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment_number' => $this->payment_number,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'provider' => $this->provider,
            'provider_transaction_id' => $this->transaction_reference,
        ];
    }
}
