<?php

namespace App\Http\Requests\Customer;

class CheckoutPreviewRequest extends CreateOrderRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(parent::rules())
            ->except('idempotency_key')
            ->all();
    }
}
