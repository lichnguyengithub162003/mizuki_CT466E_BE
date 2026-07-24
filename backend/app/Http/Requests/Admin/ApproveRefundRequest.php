<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approved_amount' => ['sometimes', 'integer', 'min:1'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
