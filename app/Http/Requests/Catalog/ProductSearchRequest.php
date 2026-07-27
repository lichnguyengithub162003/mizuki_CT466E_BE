<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ProductSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keyword.required' => 'Vui lòng nhập từ khóa tìm kiếm',
            'keyword.min' => 'Từ khóa tìm kiếm phải có ít nhất 1 ký tự',
            'limit.integer' => 'Giới hạn kết quả phải là số nguyên',
            'limit.min' => 'Giới hạn kết quả phải từ 1 đến 20',
            'limit.max' => 'Giới hạn kết quả phải từ 1 đến 20',
        ];
    }
}
