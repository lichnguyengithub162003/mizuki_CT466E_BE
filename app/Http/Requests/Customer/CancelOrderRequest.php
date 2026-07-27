<?php

namespace App\Http\Requests\Customer;

use App\Enums\OrderRequestReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_type' => ['required', Rule::enum(OrderRequestReason::class)],
            'reason' => ['nullable', 'required_if:reason_type,other', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason_type.required' => 'Vui lòng chọn lý do hủy đơn',
            'reason_type.enum' => 'Lý do hủy đơn không hợp lệ',
            'reason.required_if' => 'Vui lòng nhập lý do hủy đơn khác',
            'reason.max' => 'Lý do không được vượt quá 2000 ký tự',
        ];
    }
}
