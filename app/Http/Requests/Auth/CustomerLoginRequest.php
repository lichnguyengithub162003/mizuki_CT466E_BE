<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CustomerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required_without:phone',
                'prohibits:phone',
                'nullable',
                'string',
                'email',
                'max:255',
            ],
            'phone' => [
                'required_without:email',
                'prohibits:email',
                'nullable',
                'string',
                'regex:/^0[35789][0-9]{8}$/',
            ],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Vui lòng nhập email hoặc số điện thoại',
            'email.prohibits' => 'Chỉ được dùng email hoặc số điện thoại để đăng nhập',
            'email.string' => 'Email không hợp lệ',
            'email.email' => 'Email không đúng định dạng',
            'email.max' => 'Email không được vượt quá 255 ký tự',
            'phone.required_without' => 'Vui lòng nhập email hoặc số điện thoại',
            'phone.prohibits' => 'Chỉ được dùng email hoặc số điện thoại để đăng nhập',
            'phone.string' => 'Số điện thoại không hợp lệ',
            'phone.regex' => 'Số điện thoại không đúng định dạng',
            'password.required' => 'Vui lòng nhập mật khẩu',
            'password.string' => 'Mật khẩu không hợp lệ',
        ];
    }
}
