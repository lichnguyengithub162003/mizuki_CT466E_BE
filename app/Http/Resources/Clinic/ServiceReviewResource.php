<?php

namespace App\Http\Resources\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => [
                'id' => $this->user?->id,
                'display_name' => $this->user?->name,
                'avatar_url' => $this->user?->avatar,
            ],
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'content' => $this->comment,
            'reviewed_at' => $this->created_at?->toISOString(),
            'verified_service' => (bool) $this->internal_verified_service,
        ];
    }
}
