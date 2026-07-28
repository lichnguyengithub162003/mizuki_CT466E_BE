<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateWalkInAppointmentRequest extends FormRequest
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
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => [
                'nullable',
                'required_without_all:customer_id,customer_phone',
                'string',
                'max:100',
            ],
            'customer_phone' => [
                'nullable',
                'required_without_all:customer_id,customer_name',
                'string',
                'max:20',
            ],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_name.required_without_all' => 'Vui lòng nhập tên hoặc số điện thoại khách vãng lai!',
            'customer_phone.required_without_all' => 'Vui lòng nhập tên hoặc số điện thoại khách vãng lai!',
            'appointment_date.after_or_equal' => 'Ngày đặt lịch không được ở trong quá khứ!',
            'appointment_date.before_or_equal' => 'Chỉ có thể đặt lịch trong 90 ngày tới!',
            'start_time.date_format' => 'Giờ bắt đầu phải đúng định dạng HH:mm!',
        ];
    }
}
