<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'integer', 'exists:brands,id'],
            'is_active' => ['sometimes', 'boolean'],
            'low_stock' => ['sometimes', 'boolean'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'visibility' => ['sometimes', Rule::in(['visible', 'hidden'])],
            'type' => ['sometimes', Rule::in(['product', 'service'])],
            'sort' => ['sometimes', Rule::in(['newest', 'name'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
