<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromotionRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', Rule::unique('promotions', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => [
                'required',
                'integer',
                'min:1',
                Rule::when($this->input('discount_type') === 'percentage', ['max:100']),
            ],
            'max_discount_amount' => ['nullable', 'integer', 'min:0'],
            'minimum_order_amount' => ['required', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_count' => ['sometimes', 'integer', 'min:0'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'applies_to' => ['required', Rule::in(['order'])],
            'rules' => ['nullable', 'array'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
            'branch_ids' => ['required_without:user_ids', 'prohibits:user_ids', 'array', 'min:1'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at'),
                ),
            ],
            'user_ids' => ['required_without:branch_ids', 'prohibits:branch_ids', 'array', 'min:1'],
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
            'code.required' => 'Vui lòng nhập mã promotion',
            'code.unique' => 'Mã promotion đã tồn tại',
            'name.required' => 'Vui lòng nhập tên promotion',
            'discount_type.required' => 'Vui lòng chọn loại giảm giá',
            'discount_type.in' => 'Loại giảm giá không hợp lệ',
            'discount_value.required' => 'Vui lòng nhập giá trị giảm',
            'discount_value.max' => 'Giá trị giảm theo phần trăm không được vượt quá 100',
            'minimum_order_amount.required' => 'Vui lòng nhập giá trị đơn hàng tối thiểu',
            'starts_at.required' => 'Vui lòng nhập thời gian bắt đầu',
            'ends_at.after' => 'Thời gian kết thúc phải sau thời gian bắt đầu',
            'branch_ids.required_without' => 'Vui lòng chọn chi nhánh hoặc khách hàng nhận voucher',
            'branch_ids.prohibits' => 'Không thể đồng thời chọn chi nhánh và khách hàng',
            'branch_ids.*.exists' => 'Chi nhánh không tồn tại hoặc đã ngừng hoạt động',
            'user_ids.required_without' => 'Vui lòng chọn khách hàng hoặc chi nhánh áp dụng',
            'user_ids.prohibits' => 'Không thể đồng thời chọn khách hàng và chi nhánh',
            'user_ids.*.exists' => 'Tài khoản khách hàng không tồn tại',
        ];
    }
}
