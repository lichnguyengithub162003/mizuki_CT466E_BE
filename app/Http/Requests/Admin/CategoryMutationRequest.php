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

        $rules = [
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

        if ($creating) {
            $rules += [
                'attach_existing_child_ids' => ['sometimes', 'array', 'list'],
                'attach_existing_child_ids.*' => ['required', 'integer', 'distinct:strict'],
                'new_children' => ['sometimes', 'array', 'list'],
                'new_children.*' => ['required', 'array:name,slug,description,sort_order,status,is_featured,image_upload_token'],
                'new_children.*.name' => ['required', 'string', 'max:255'],
                'new_children.*.slug' => ['required', 'string', 'max:255', Rule::unique('categories', 'slug')],
                'new_children.*.description' => ['sometimes', 'nullable', 'string'],
                'new_children.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'new_children.*.status' => ['sometimes', Rule::in([Category::STATUS_DRAFT, Category::STATUS_PUBLISHED, Category::STATUS_HIDDEN])],
                'new_children.*.is_featured' => ['sometimes', 'boolean'],
                'new_children.*.image_upload_token' => ['sometimes', 'uuid'],
            ];
        }

        return $rules;
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

        $validator->after(function (Validator $validator): void {
            if (! $this->isMethod('post')) {
                return;
            }

            $seen = [];
            foreach (['slug' => $this->input('slug')] + collect($this->input('new_children', []))
                ->mapWithKeys(fn ($child, $index): array => ["new_children.{$index}.slug" => is_array($child) ? ($child['slug'] ?? null) : null])
                ->all() as $field => $slug) {
                if (! is_string($slug)) {
                    continue;
                }
                $key = mb_strtolower($slug);
                if (isset($seen[$key])) {
                    $validator->errors()->add($field, 'Slug danh mục bị trùng trong yêu cầu.');
                }
                $seen[$key] = true;
            }
        });
    }
}
