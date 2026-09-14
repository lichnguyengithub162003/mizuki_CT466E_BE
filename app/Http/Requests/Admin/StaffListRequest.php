<?php

namespace App\Http\Requests\Admin;

use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class StaffListRequest extends AdminListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'role' => ['sometimes', Rule::enum(UserRole::class), Rule::notIn([UserRole::Customer->value])],
            'status' => ['sometimes', Rule::enum(StaffEmploymentStatus::class)],
        ];
    }
}
