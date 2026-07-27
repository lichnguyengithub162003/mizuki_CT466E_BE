<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_name'  => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'province'        => ['required', 'string', 'max:100'],
            'district'        => ['required', 'string', 'max:100'],
            'ward'            => ['required', 'string', 'max:100'],
            'hamlet'          => ['nullable', 'string', 'max:255'],
            'address_line'    => ['required', 'string', 'max:500'],
            'is_default'      => ['sometimes', 'boolean'],
            'ghn_province_id' => ['sometimes', 'nullable', 'integer'],
            'ghn_district_id' => ['sometimes', 'nullable', 'integer'],
            'ghn_ward_code'   => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required'  => 'Vui lòng nhập tên người nhận',
            'recipient_phone.required' => 'Vui lòng nhập số điện thoại người nhận',
            'province.required'        => 'Vui lòng chọn tỉnh/thành phố',
            'district.required'        => 'Vui lòng chọn quận/huyện',
            'ward.required'            => 'Vui lòng chọn phường/xã',
            'address_line.required'    => 'Vui lòng nhập địa chỉ cụ thể',
        ];
    }
}
