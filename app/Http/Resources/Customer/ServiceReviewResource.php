<?php

namespace App\Http\Resources\Customer;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $verifiedService = $this->appointment !== null
            && $this->appointment->status === AppointmentStatus::Completed
            && $this->appointment->user_id === $this->user_id
            && $this->appointment->service_id === $this->service_id;

        return [
            'id' => $this->id,
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'slug' => $this->service->slug,
            ],
            'appointment_id' => $this->appointment_id,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'is_visible' => (bool) $this->is_visible,
            'verified_service' => $verifiedService,
            'reviewed_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
