<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = now(config('app.timezone'))->startOfDay();

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'appointment_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->toDateString(),
                'before_or_equal:'.$today->copy()->addDays(90)->toDateString(),
            ],
            'start_time' => ['required', 'date_format:H:i'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh!',
            'branch_id.exists' => 'Chi nhánh không tồn tại!',
            'service_id.required' => 'Vui lòng chọn dịch vụ!',
            'service_id.exists' => 'Dịch vụ không tồn tại!',
            'appointment_date.required' => 'Vui lòng chọn ngày đặt lịch!',
            'appointment_date.date_format' => 'Ngày đặt lịch phải đúng định dạng YYYY-MM-DD!',
            'appointment_date.after_or_equal' => 'Ngày đặt lịch không được ở trong quá khứ!',
            'appointment_date.before_or_equal' => 'Chỉ có thể đặt lịch trong 90 ngày tới!',
            'start_time.required' => 'Vui lòng chọn giờ bắt đầu!',
            'start_time.date_format' => 'Giờ bắt đầu phải đúng định dạng HH:mm!',
            'customer_note.max' => 'Ghi chú không được vượt quá 1000 ký tự!',
        ];
    }
}
