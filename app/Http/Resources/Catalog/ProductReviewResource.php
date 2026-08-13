<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imported = $this->source === 'hasaki';
        $images = is_array($this->images) ? array_values($this->images) : [];

        return [
            'id' => $this->id,
            'customer' => [
                'id' => $imported ? null : $this->user?->id,
                'display_name' => $imported ? $this->source_author_name : $this->user?->name,
                'avatar_url' => $imported ? null : $this->user?->avatar,
            ],
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'content' => $this->comment,
            'reviewed_at' => $this->created_at?->toISOString(),
            'verified_purchase' => $imported
                ? (bool) $this->source_verified_purchase
                : (bool) $this->internal_verified_purchase,
            'images' => $images,
            'helpful_count' => 0,
            'mizuki_response' => $this->mizuki_response_content === null
                ? null
                : [
                    'author' => 'Mizuki',
                    'content' => $this->mizuki_response_content,
                ],
        ];
    }
}
