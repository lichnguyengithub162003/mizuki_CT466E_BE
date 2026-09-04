<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `completed` is the canonical Admin Orders presentation value. The
            // persisted `delivered` value remains readable for existing orders.
            'status' => ['sometimes', Rule::in([
                ...array_column(OrderStatus::cases(), 'value'),
                'completed',
            ])],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_only' => ['sometimes', 'boolean'],
            'shipment_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest'])],
            'sort_by' => ['sometimes', Rule::in(['order_number', 'total_amount', 'status', 'created_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
