<?php

namespace App\Http\Requests\Cashier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer'])],
        ];
    }
}
