<?php

namespace App\Http\Requests\Admin;

use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffMutationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('job_title'))) {
            $jobTitle = trim($this->input('job_title'));
            $this->merge(['job_title' => $jobTitle === '' ? null : $jobTitle]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $id = $this->route('staff');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($id)],
            'password' => [$creating ? 'required' : 'sometimes', 'string', 'min:8'],
            'role' => [$creating ? 'required' : 'sometimes', Rule::enum(UserRole::class), Rule::notIn([UserRole::Customer->value])],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(StaffEmploymentStatus::class)],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'avatar_upload_token' => ['sometimes', 'uuid'],
        ];
    }
}
