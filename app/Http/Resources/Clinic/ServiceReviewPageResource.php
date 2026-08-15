<?php

namespace App\Http\Resources\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceReviewPageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'service' => [
                'id' => $this->resource['service']->id,
                'name' => $this->resource['service']->name,
                'slug' => $this->resource['service']->slug,
            ],
            'summary' => $this->resource['summary'],
            'reviews' => ServiceReviewResource::collection($this->resource['reviews'])->resolve($request),
        ];
    }
}
