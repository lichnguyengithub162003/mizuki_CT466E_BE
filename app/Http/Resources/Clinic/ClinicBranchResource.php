<?php

namespace App\Http\Resources\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicBranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'phone' => $this->phone,
            'address' => $this->address,
            'province_code' => $this->province_code,
            'branch_type' => $this->branch_type->value,
            'business_hours' => $this->whenLoaded('businessHours', function (): array {
                return $this->businessHours->map(fn ($hour): array => [
                    'weekday' => $hour->weekday,
                    'opens_at' => $hour->opens_at,
                    'closes_at' => $hour->closes_at,
                    'is_closed' => $hour->is_closed,
                ])->all();
            }),
        ];
    }
}
