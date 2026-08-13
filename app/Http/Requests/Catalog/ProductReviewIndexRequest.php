<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductReviewIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $booleans = [];

        foreach (['has_images', 'verified_purchase'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $booleans[$field] = $value;
            }
        }

        $this->merge($booleans);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'has_images' => ['sometimes', 'boolean'],
            'verified_purchase' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['newest'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.between' => 'Số sao đánh giá phải từ 1 đến 5.',
            'has_images.boolean' => 'Bộ lọc đánh giá có hình ảnh không hợp lệ.',
            'verified_purchase.boolean' => 'Bộ lọc đã mua hàng không hợp lệ.',
            'sort.in' => 'Kiểu sắp xếp đánh giá không hợp lệ.',
            'per_page.max' => 'Số đánh giá mỗi trang không được vượt quá 50.',
        ];
    }
}
