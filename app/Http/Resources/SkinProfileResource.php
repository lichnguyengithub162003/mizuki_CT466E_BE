<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SkinProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->exists ? $this->id : null,
            'customer_id' => $this->user_id,
            'skin_type' => $this->skin_type,
            'concerns' => $this->exists ? $this->concerns : [],
            'sensitivity_level' => $this->sensitivity_level,
            'allergies' => $this->allergies,
            'current_products' => $this->current_products,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
