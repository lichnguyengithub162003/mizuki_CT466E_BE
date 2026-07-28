<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSkinProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'skin_type' => [
                'sometimes',
                'nullable',
                Rule::in(['normal', 'dry', 'oily', 'combination', 'sensitive']),
            ],
            'concerns' => ['sometimes', 'nullable', 'array'],
            'concerns.*' => ['string', 'max:100', 'not_regex:/^\s*$/'],
            'sensitivity_level' => [
                'sometimes',
                'nullable',
                Rule::in(['low', 'medium', 'high']),
            ],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'current_products' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'skin_type.in' => 'Loại da không hợp lệ!',
            'concerns.array' => 'Mối quan tâm về da phải là một danh sách!',
            'concerns.*.not_regex' => 'Mỗi mối quan tâm về da không được để trống!',
            'concerns.*.max' => 'Mỗi mối quan tâm về da không được vượt quá 100 ký tự!',
            'sensitivity_level.in' => 'Mức độ nhạy cảm không hợp lệ!',
        ];
    }
}
