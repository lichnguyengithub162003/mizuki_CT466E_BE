<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffMutationRequest extends FormRequest
{
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
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
