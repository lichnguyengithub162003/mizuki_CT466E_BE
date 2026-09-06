<?php

namespace App\Http\Resources\Catalog;

use App\Http\Resources\Concerns\SerializesMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSuggestResource extends JsonResource
{
    use SerializesMedia;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryImageReference = $this->images->first()?->image_url;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'primary_image_url' => $this->mediaUrl($primaryImageReference),
            'primary_image_thumb_url' => $this->mediaUrl($primaryImageReference, 'thumb'),
            'minimum_price' => (int) $this->minimum_price,
        ];
    }
}
