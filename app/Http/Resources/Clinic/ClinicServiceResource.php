<?php

namespace App\Http\Resources\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'duration_minutes' => $this->duration_minutes,
            'price' => $this->price,
            'is_available' => (bool) $this->getAttribute('booking_is_available'),
            'capacity' => max(1, (int) $this->getAttribute('booking_capacity')),
        ];
    }
}
