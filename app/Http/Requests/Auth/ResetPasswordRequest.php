<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'exists:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'             => 'Token không hợp lệ',
            'email.required'             => 'Vui lòng nhập email',
            'email.email'                => 'Email không hợp lệ',
            'email.exists'               => 'Email không tồn tại trong hệ thống',
            'password.required'          => 'Vui lòng nhập mật khẩu mới',
            'password.min'               => 'Mật khẩu tối thiểu 8 ký tự',
            'password.confirmed'         => 'Xác nhận mật khẩu không khớp',
        ];
    }
}
