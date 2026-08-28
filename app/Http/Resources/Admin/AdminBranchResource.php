<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminBranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name,
            'branch_type' => $this->branch_type->value, 'branch_type_label' => $this->branch_type->label(),
            'phone' => $this->phone, 'email' => $this->email, 'address' => $this->address,
            'province_code' => $this->province_code, 'ghn_district_id' => $this->ghn_district_id,
            'ghn_ward_code' => $this->ghn_ward_code, 'is_active' => (bool) $this->is_active,
            'business_hours' => $this->whenLoaded('businessHours', fn () => $this->businessHours->map(fn ($hours): array => [
                'weekday' => $hours->weekday, 'opens_at' => $hours->opens_at,
                'closes_at' => $hours->closes_at, 'is_closed' => (bool) $hours->is_closed,
            ])->values()->all()),
        ];
    }
}
