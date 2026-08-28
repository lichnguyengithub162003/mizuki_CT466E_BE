<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'parent_id' => $this->parent_id, 'name' => $this->name,
            'slug' => $this->slug, 'description' => $this->description, 'image_url' => $this->image_url,
            'sort_order' => $this->sort_order, 'is_active' => (bool) $this->is_active,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : ['id' => $this->parent->id, 'name' => $this->parent->name, 'slug' => $this->parent->slug]),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)->resolve($request)),
            'children_count' => (int) ($this->children_count ?? ($this->relationLoaded('children') ? $this->children->count() : 0)),
            'product_count' => (int) ($this->products_count ?? 0),
        ];
    }
}
