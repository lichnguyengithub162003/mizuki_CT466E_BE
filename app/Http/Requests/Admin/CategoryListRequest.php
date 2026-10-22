<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::in(['flat', 'root', 'children'])],
            'parent_id' => ['required_if:mode,children', 'prohibited_unless:mode,children', 'integer', 'exists:categories,id'],
            'search' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'has_children' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['sort_order', 'name', 'created_at', 'updated_at', 'product_count'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
