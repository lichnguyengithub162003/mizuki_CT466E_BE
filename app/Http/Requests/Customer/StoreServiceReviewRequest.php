<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'appointment_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'user_id' => ['prohibited'],
            'service_id' => ['prohibited'],
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
            'appointment_id.required' => 'Vui lòng chọn lịch hẹn đã hoàn thành',
            'rating.required' => 'Vui lòng chọn số sao đánh giá',
            'rating.integer' => 'Số sao đánh giá phải là số nguyên',
            'rating.between' => 'Số sao đánh giá phải từ 1 đến 5',
            'title.max' => 'Tiêu đề không được vượt quá 255 ký tự',
            'comment.max' => 'Nội dung không được vượt quá 5000 ký tự',
            '*.prohibited' => 'Dữ liệu sở hữu không được phép gửi lên',
        ];
    }
}
