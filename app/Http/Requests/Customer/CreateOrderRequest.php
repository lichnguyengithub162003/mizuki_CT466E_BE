<?php

namespace App\Http\Requests\Customer;

use App\Enums\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_method' => ['required', Rule::in(['pickup', 'delivery'])],
            'address_id' => [
                'nullable',
                'required_if:delivery_method,delivery',
                'integer',
                Rule::exists('user_addresses', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $this->user()?->id)
                        ->whereNull('deleted_at'),
                ),
            ],
            'payment_method' => ['required', Rule::in([
                PaymentMethod::Wallet->value,
                PaymentMethod::VNPay->value,
                PaymentMethod::Cash->value,
            ])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delivery_method.required' => 'Vui lòng chọn phương thức nhận hàng',
            'delivery_method.in' => 'Phương thức nhận hàng không hợp lệ',
            'address_id.required_if' => 'Vui lòng chọn địa chỉ giao hàng',
            'address_id.exists' => 'Địa chỉ giao hàng không tồn tại hoặc không thuộc tài khoản của bạn',
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ',
        ];
    }
}
