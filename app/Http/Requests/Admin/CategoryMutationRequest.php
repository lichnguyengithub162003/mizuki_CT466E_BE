<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $id = $this->route('category');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id', Rule::notIn([$id])],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
