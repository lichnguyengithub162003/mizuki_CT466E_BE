<?php

namespace App\Http\Requests\Customer;

use App\Enums\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        if (is_string($key)) {
            $this->merge(['idempotency_key' => trim($key)]);
        }
    }

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
            'shipping_quote_token' => [
                'exclude_unless:delivery_method,delivery',
                'required_if:delivery_method,delivery',
                'string',
                'size:64',
                'regex:/\A[a-f0-9]{64}\z/',
            ],
            'payment_method' => ['required', Rule::in(array_map(
                static fn (PaymentMethod $method): string => $method->value,
                PaymentMethod::customerCheckoutMethods(),
            ))],
            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:100',
                'regex:/\A[A-Za-z0-9._:-]+\z/',
            ],
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
            'shipping_quote_token.required_if' => 'Vui lòng lấy phí vận chuyển trước khi đặt hàng!',
            'shipping_quote_token.size' => 'Báo giá vận chuyển không hợp lệ!',
            'shipping_quote_token.regex' => 'Báo giá vận chuyển không hợp lệ!',
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ',
            'idempotency_key.required' => 'Thiếu mã chống trùng khi đặt hàng',
            'idempotency_key.min' => 'Mã chống trùng khi đặt hàng không hợp lệ',
            'idempotency_key.max' => 'Mã chống trùng khi đặt hàng không hợp lệ',
            'idempotency_key.regex' => 'Mã chống trùng khi đặt hàng không hợp lệ',
        ];
    }
}
