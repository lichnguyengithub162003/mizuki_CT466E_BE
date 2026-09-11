<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffAssignmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['job_title', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
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
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'role' => ['sometimes', Rule::enum(UserRole::class), Rule::notIn([UserRole::Customer->value])],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_area' => ['sometimes', 'nullable', Rule::in(['clinic', 'retail', 'management', 'system'])],
            'effective_from' => ['sometimes', 'date', 'before_or_equal:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
