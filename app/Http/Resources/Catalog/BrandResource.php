<?php

namespace App\Http\Resources\Catalog;

use App\Http\Resources\Concerns\SerializesMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    use SerializesMedia;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $logoUrl = $this->brandLogoUrl($this->logo_url, $this->slug, $this->name);
        $bannerUrl = $this->mediaUrl($this->banner_image);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $logoUrl,
            'logo' => $logoUrl,
            'banner_image' => $bannerUrl,
            'logo_rendition_url' => $this->brandLogoUrl($this->logo_url, $this->slug, $this->name, 'brand_logo'),
            'banner_rendition_url' => $this->mediaUrl($this->banner_image, 'brand_banner'),
            'description' => $this->description,
        ];
    }
}
