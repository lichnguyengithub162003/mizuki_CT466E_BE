<?php

namespace App\Http\Requests\Cashier;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePosCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^\+?[0-9]{9,15}$/',
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
