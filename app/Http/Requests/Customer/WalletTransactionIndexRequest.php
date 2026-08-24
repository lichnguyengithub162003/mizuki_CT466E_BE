<?php

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => ['sometimes', Rule::enum(WalletTransactionType::class)],
            'direction' => ['sometimes', Rule::enum(WalletTransactionDirection::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.enum' => 'Loại giao dịch ví không hợp lệ',
            'direction.enum' => 'Chiều giao dịch ví không hợp lệ',
            'page.integer' => 'Trang phải là số nguyên',
            'page.min' => 'Trang phải ít nhất là 1',
            'per_page.integer' => 'Số bản ghi mỗi trang phải là số nguyên',
            'per_page.min' => 'Số bản ghi mỗi trang phải ít nhất là 1',
            'per_page.max' => 'Số bản ghi mỗi trang không được vượt quá 100',
        ];
    }
}
