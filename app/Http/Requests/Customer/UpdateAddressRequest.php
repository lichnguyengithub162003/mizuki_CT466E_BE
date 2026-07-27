<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_name'  => ['sometimes', 'string', 'max:255'],
            'recipient_phone' => ['sometimes', 'string', 'max:20'],
            'province'        => ['sometimes', 'string', 'max:100'],
            'district'        => ['sometimes', 'string', 'max:100'],
            'ward'            => ['sometimes', 'string', 'max:100'],
            'hamlet'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line'    => ['sometimes', 'string', 'max:500'],
            'is_default'      => ['sometimes', 'boolean'],
            'ghn_province_id' => ['sometimes', 'nullable', 'integer'],
            'ghn_district_id' => ['sometimes', 'nullable', 'integer'],
            'ghn_ward_code'   => ['sometimes', 'nullable', 'string'],
        ];
    }
}
