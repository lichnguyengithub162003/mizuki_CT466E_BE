<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại',
            'password.required'         => 'Vui lòng nhập mật khẩu mới',
            'password.min'              => 'Mật khẩu tối thiểu 8 ký tự',
            'password.confirmed'        => 'Xác nhận mật khẩu không khớp',
        ];
    }
}