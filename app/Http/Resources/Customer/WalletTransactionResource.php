<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'transaction_number' => $this->transaction_number,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
