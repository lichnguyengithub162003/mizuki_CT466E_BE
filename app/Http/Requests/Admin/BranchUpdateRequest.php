<?php

namespace App\Http\Requests\Admin;

use App\Enums\BranchType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'branch_type' => ['sometimes', Rule::enum(BranchType::class)],
            'phone' => ['sometimes', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'string', 'max:255'],
            'province_code' => ['sometimes', 'string', 'max:20'],
            'ghn_district_id' => ['sometimes', 'integer', 'min:1'],
            'ghn_ward_code' => ['sometimes', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'business_hours' => ['sometimes', 'array', 'max:7'],
            'business_hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'business_hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'business_hours.*.closes_at' => ['nullable', 'date_format:H:i', 'after:business_hours.*.opens_at'],
            'business_hours.*.is_closed' => ['required', 'boolean'],
        ];
    }
}
