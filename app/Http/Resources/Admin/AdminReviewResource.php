<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'type' => $this->product_id !== null ? 'product' : 'service',
            'rating' => $this->rating, 'title' => $this->title, 'comment' => $this->comment,
            'is_visible' => (bool) $this->is_visible, 'mizuki_response' => $this->mizuki_response_content,
            'mizuki_response_content' => $this->mizuki_response_content,
            'customer' => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
            'author_name' => $this->user?->name ?? $this->source_author_name,
            'product' => $this->product === null ? null : ['id' => $this->product->id, 'name' => $this->product->name],
            'service' => $this->service === null ? null : ['id' => $this->service->id, 'name' => $this->service->name],
            'product_variant' => $this->whenLoaded('productVariant', fn () => $this->productVariant === null ? null : ['id' => $this->productVariant->id, 'name' => $this->productVariant->name, 'sku' => $this->productVariant->sku]),
            'source' => $this->source, 'source_key' => $this->source_key,
            'source_verified_purchase' => $this->source_verified_purchase,
            'source_date' => $this->source_date, 'variant_purchased' => $this->variant_purchased,
            'images' => $this->images ?? [],
            'moderated_by' => $this->moderatedBy === null ? null : ['id' => $this->moderatedBy->id, 'name' => $this->moderatedBy->name],
            'moderated_at' => $this->moderated_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
