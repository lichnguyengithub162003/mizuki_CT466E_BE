<?php

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class WalletTransactionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Customer;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'Số bản ghi mỗi trang phải là số nguyên',
            'per_page.min' => 'Số bản ghi mỗi trang phải ít nhất là 1',
            'per_page.max' => 'Số bản ghi mỗi trang không được vượt quá 100',
        ];
    }
}
