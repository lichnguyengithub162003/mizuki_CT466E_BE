<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')->ignore((int) $this->route('id')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::when($this->input('discount_type') === 'percentage', ['max:100']),
            ],
            'max_discount_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'minimum_order_amount' => ['sometimes', 'integer', 'min:0'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_count' => ['sometimes', 'integer', 'min:0'],
            'per_user_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'applies_to' => ['sometimes', Rule::in(['order'])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
                Rule::when($this->filled('starts_at'), ['after:starts_at']),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'branch_ids' => ['sometimes', 'prohibits:user_ids', 'array', 'min:1'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at'),
                ),
            ],
            'user_ids' => ['sometimes', 'prohibits:branch_ids', 'array', 'min:1'],
            'user_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query): Builder => $query->where('role', UserRole::Customer->value),
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
            'code.unique' => 'Mã promotion đã tồn tại',
            'discount_type.in' => 'Loại giảm giá không hợp lệ',
            'discount_value.max' => 'Giá trị giảm theo phần trăm không được vượt quá 100',
            'ends_at.after' => 'Thời gian kết thúc phải sau thời gian bắt đầu',
            'branch_ids.prohibits' => 'Không thể đồng thời chọn chi nhánh và khách hàng',
            'branch_ids.*.exists' => 'Chi nhánh không tồn tại hoặc đã ngừng hoạt động',
            'user_ids.prohibits' => 'Không thể đồng thời chọn khách hàng và chi nhánh',
            'user_ids.*.exists' => 'Tài khoản khách hàng không tồn tại',
        ];
    }
}
