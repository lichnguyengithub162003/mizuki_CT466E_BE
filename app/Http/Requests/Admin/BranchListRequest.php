<?php

namespace App\Http\Requests\Admin;

use App\Enums\BranchStatus;
use Illuminate\Validation\Rule;

class BranchListRequest extends AdminListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
        ];
    }
}
