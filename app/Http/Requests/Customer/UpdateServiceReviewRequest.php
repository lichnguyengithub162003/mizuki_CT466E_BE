<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'appointment_id' => ['prohibited'],
            'service_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'product_variant_id' => ['prohibited'],
            'order_item_id' => ['prohibited'],
            'source' => ['prohibited'],
            'is_visible' => ['prohibited'],
            'moderated_by_user_id' => ['prohibited'],
            'moderated_at' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.integer' => 'Số sao đánh giá phải là số nguyên',
            'rating.between' => 'Số sao đánh giá phải từ 1 đến 5',
            'title.max' => 'Tiêu đề không được vượt quá 255 ký tự',
            'comment.max' => 'Nội dung không được vượt quá 5000 ký tự',
            '*.prohibited' => 'Dữ liệu sở hữu không được phép thay đổi',
        ];
    }
}
