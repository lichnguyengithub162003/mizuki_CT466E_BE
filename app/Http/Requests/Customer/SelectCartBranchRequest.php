<?php

namespace App\Http\Requests\Customer;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectCartBranchRequest extends FormRequest
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
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh',
            'branch_id.exists' => 'Chi nhánh không tồn tại hoặc đã ngừng hoạt động',
        ];
    }
}
