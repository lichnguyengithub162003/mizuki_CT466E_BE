<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
