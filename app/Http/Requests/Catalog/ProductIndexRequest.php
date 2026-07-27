<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $priceMinRules = ['sometimes', 'nullable', 'integer', 'min:0'];
        $priceMaxRules = ['sometimes', 'nullable', 'integer', 'min:0'];

        if ($this->filled('price_max')) {
            $priceMinRules[] = 'lte:price_max';
        }

        if ($this->filled('price_min')) {
            $priceMaxRules[] = 'gte:price_min';
        }

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category_id' => [
                'sometimes',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'brand_id' => [
                'sometimes',
                'integer',
                Rule::exists('brands', 'id')->whereNull('deleted_at'),
            ],
            'price_min' => $priceMinRules,
            'price_max' => $priceMaxRules,
            'sort' => ['sometimes', Rule::in(['newest', 'best_selling', 'price_asc', 'price_desc'])],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'Danh mục không tồn tại',
            'brand_id.exists' => 'Thương hiệu không tồn tại',
            'price_min.lte' => 'Giá tối thiểu không được lớn hơn giá tối đa',
            'price_max.gte' => 'Giá tối đa không được nhỏ hơn giá tối thiểu',
            'sort.in' => 'Kiểu sắp xếp không hợp lệ',
            'per_page.max' => 'Số sản phẩm mỗi trang không được vượt quá 100',
        ];
    }
}
