<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'logo_url' => $this->logo_url, 'image_url' => $this->logo_url,
            'banner_image' => $this->banner_image, 'description' => $this->description,
            'follower_count' => (int) $this->follower_count, 'is_active' => (bool) $this->is_active,
            'product_count' => (int) ($this->products_count ?? 0),
        ];
    }
}
