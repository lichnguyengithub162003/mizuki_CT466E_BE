<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'max_discount_amount' => $this->max_discount_amount,
            'minimum_order_amount' => $this->minimum_order_amount,
            'usage_limit' => $this->usage_limit,
            'usage_count' => (int) ($this->usages_count ?? $this->usage_count),
            'per_user_limit' => $this->per_user_limit,
            'applies_to' => $this->applies_to,
            'branch_ids' => $this->whenLoaded(
                'branches',
                fn (): array => $this->branches->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ),
            'user_ids' => array_map('intval', $this->scope['user_ids'] ?? []),
            'rules' => $this->rules,
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
