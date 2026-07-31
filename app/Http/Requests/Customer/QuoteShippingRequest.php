<?php

namespace App\Http\Requests\Customer;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteShippingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id' => [
                'required',
                'integer',
                Rule::exists('user_addresses', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $this->user()?->id)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'address_id.required' => 'Vui lòng chọn địa chỉ giao hàng!',
            'address_id.exists' => 'Địa chỉ giao hàng không tồn tại hoặc không thuộc tài khoản của bạn!',
        ];
    }
}
