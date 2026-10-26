<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Concerns\SerializesMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use SerializesMedia;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $imageUrl = $this->mediaUrl($this->image_url);

        return [
            'id' => $this->id, 'parent_id' => $this->parent_id, 'name' => $this->name,
            'slug' => $this->slug, 'description' => $this->description, 'image_url' => $imageUrl,
            'image_rendition_url' => $this->mediaUrl($this->image_url, 'category'),
            'sort_order' => $this->sort_order, 'is_active' => (bool) $this->is_active,
            'status' => $this->status, 'is_featured' => (bool) $this->is_featured,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : ['id' => $this->parent->id, 'name' => $this->parent->name, 'slug' => $this->parent->slug]),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)->resolve($request)),
            'children_count' => (int) ($this->children_count ?? ($this->relationLoaded('children') ? $this->children->count() : 0)),
            'has_children' => (int) ($this->children_count ?? ($this->relationLoaded('children') ? $this->children->count() : 0)) > 0,
            'ancestors' => $this->whenLoaded('ancestors', fn () => $this->ancestors->all()),
            'depth' => $this->whenLoaded('ancestors', fn () => $this->ancestors->count()),
            'product_count' => (int) ($this->products_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
