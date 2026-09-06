<?php

namespace App\Http\Resources\Catalog;

use App\Http\Resources\Concerns\SerializesMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use SerializesMedia;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'thumbnail_url' => $this->thumbnailUrl(),
            'image_rendition_url' => $this->thumbnailUrl('category'),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }

    private function thumbnailUrl(?string $preset = null): ?string
    {
        return $this->mediaUrl(is_string($this->thumbnail_url) ? $this->thumbnail_url : null, $preset);
    }
}
