<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => [
                'required',
                'string',
                'regex:/^0[35789][0-9]{8}$/',
                Rule::unique('users', 'phone'),
            ],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ tên',
            'name.string' => 'Họ tên không hợp lệ',
            'name.max' => 'Họ tên không được vượt quá 255 ký tự',
            'email.required' => 'Vui lòng nhập email',
            'email.string' => 'Email không hợp lệ',
            'email.email' => 'Email không đúng định dạng',
            'email.max' => 'Email không được vượt quá 255 ký tự',
            'email.unique' => 'Email này đã được sử dụng',
            'phone.required' => 'Vui lòng nhập số điện thoại',
            'phone.string' => 'Số điện thoại không hợp lệ',
            'phone.regex' => 'Số điện thoại không đúng định dạng',
            'phone.unique' => 'Số điện thoại này đã được sử dụng',
            'password.required' => 'Vui lòng nhập mật khẩu',
            'password.string' => 'Mật khẩu không hợp lệ',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự',
        ];
    }
}
