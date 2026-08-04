<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
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
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->when(
                filled($this->email),
                $this->email,
            ),
            'is_active' => (bool) $this->is_active,
            'opening_hours' => $this->whenLoaded(
                'businessHours',
                fn () => $this->businessHours
                    ->map(fn ($hours): array => [
                        'weekday' => $hours->weekday,
                        'opens_at' => $hours->opens_at,
                        'closes_at' => $hours->closes_at,
                        'is_closed' => (bool) $hours->is_closed,
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }
}
