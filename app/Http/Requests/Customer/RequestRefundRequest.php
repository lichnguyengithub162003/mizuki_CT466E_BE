<?php

namespace App\Http\Requests\Customer;

use App\Enums\OrderRequestReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestRefundRequest extends FormRequest
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
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['required', 'file', 'mimes:jpg,jpeg,png,mp4', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason_type.required' => 'Vui lòng chọn lý do yêu cầu hoàn tiền',
            'reason_type.enum' => 'Lý do yêu cầu hoàn tiền không hợp lệ',
            'reason.required_if' => 'Vui lòng nhập lý do hoàn tiền khác',
            'evidence.required' => 'Vui lòng cung cấp ít nhất một file bằng chứng',
            'evidence.array' => 'Danh sách file bằng chứng không hợp lệ',
            'evidence.min' => 'Vui lòng cung cấp ít nhất một file bằng chứng',
            'evidence.max' => 'Chỉ được tải lên tối đa 5 file bằng chứng',
            'evidence.*.mimes' => 'File bằng chứng chỉ hỗ trợ JPG, JPEG, PNG hoặc MP4',
            'evidence.*.max' => 'Mỗi file bằng chứng không được vượt quá 8MB',
        ];
    }
}
