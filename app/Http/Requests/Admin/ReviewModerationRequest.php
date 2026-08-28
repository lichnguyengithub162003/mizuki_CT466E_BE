<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewModerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_visible' => ['sometimes', 'boolean'],
            'mizuki_response_content' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
