<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FavoriteIndexRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => [
                'sometimes',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)->whereNull('deleted_at')),
            ],
        ];
    }
}
