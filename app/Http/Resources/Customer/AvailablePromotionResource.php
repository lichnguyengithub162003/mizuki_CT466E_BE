<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailablePromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'maximum_discount_amount' => $this->max_discount_amount,
            'minimum_order_amount' => $this->minimum_order_amount,
            'estimated_discount_amount' => (int) $this->estimated_discount_amount,
            'ends_at' => $this->ends_at?->toISOString(),
        ];
    }
}
