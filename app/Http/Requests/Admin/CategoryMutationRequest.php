<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'image_upload_token' => ['sometimes', 'uuid'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([Category::STATUS_DRAFT, Category::STATUS_PUBLISHED, Category::STATUS_HIDDEN])],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('status') || ! $this->exists('is_active')
                || $validator->errors()->has('status') || $validator->errors()->has('is_active')) {
                return;
            }

            if ($this->boolean('is_active') !== ($this->input('status') === Category::STATUS_PUBLISHED)) {
                $validator->errors()->add('is_active', 'Trạng thái và is_active không khớp.');
            }
        });
    }
}
