<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'Vui lòng chọn ảnh.',
            'image.image' => 'Tệp đã chọn phải là hình ảnh.',
            'image.mimes' => 'Ảnh chỉ hỗ trợ JPG, PNG hoặc WebP.',
            'image.max' => 'Ảnh không được vượt quá 8 MB.',
        ];
    }
}
