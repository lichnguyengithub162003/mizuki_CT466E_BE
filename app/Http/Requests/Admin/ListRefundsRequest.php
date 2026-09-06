<?php

namespace App\Http\Requests\Admin;

use App\Enums\RefundReturnStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRefundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['requested', 'approved', 'rejected', 'refunded'])],
            'return_status' => ['sometimes', Rule::enum(RefundReturnStatus::class)],
            'return_inspection_status' => ['sometimes', Rule::in(['pending', 'accepted_restockable', 'accepted_not_restockable', 'rejected'])],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest'])],
            'sort_by' => ['sometimes', Rule::in([
                'created_at',
                'requested_amount',
                'status',
                'return_status',
                'settlement_method',
                'updated_at',
            ])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'settlement_method' => ['sometimes', Rule::in(['wallet', 'vnpay', 'momo', 'zalopay', 'manual_external', 'no_payout', 'pending'])],
            'destination' => ['sometimes', Rule::in(['wallet', 'vnpay', 'momo', 'zalopay', 'manual_external', 'no_payout', 'pending'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
