<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPromotionRequest extends FormRequest
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
        return [
            'is_active' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.in' => 'Trạng thái hoạt động không hợp lệ',
            'discount_type.in' => 'Loại giảm giá không hợp lệ',
            'per_page.integer' => 'Số bản ghi mỗi trang phải là số nguyên',
            'per_page.min' => 'Số bản ghi mỗi trang phải ít nhất là 1',
            'per_page.max' => 'Số bản ghi mỗi trang không được vượt quá 100',
        ];
    }
}
