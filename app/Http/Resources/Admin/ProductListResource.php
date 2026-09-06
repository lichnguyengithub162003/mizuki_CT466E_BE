<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class ProductListResource extends ProductResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = parent::toArray($request);
        $primaryImageReference = $this->relationLoaded('images')
            ? $this->images->first()?->image_url
            : null;

        $payload['primary_image_thumb_url'] = $this->mediaUrl($primaryImageReference, 'thumb');

        return $payload;
    }
}
