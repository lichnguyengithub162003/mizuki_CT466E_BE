<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductVariant;
use App\Services\Import\ProductImageImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $defaultVariant = $this->variants
            ->sortBy(fn (ProductVariant $variant): int => $this->effectivePrice($variant))
            ->first();
        $price = $defaultVariant === null ? (int) $this->minimum_price : $this->effectivePrice($defaultVariant);
        $originalPrice = $defaultVariant?->price ?? $price;
        $availableQuantity = $this->variants->sum(
            fn (ProductVariant $variant): int => (int) $variant->inventories->sum(
                fn ($inventory): int => max(0, $inventory->quantity - $inventory->reserved_quantity),
            ),
        );
        $discountAmount = max(0, $originalPrice - $price);
        $realImages = $this->images->reject(
            fn ($image): bool => $image->image_url === ProductImageImportService::FALLBACK_URL,
        );
        $primaryImage = ($realImages->firstWhere('is_primary', true) ?? $realImages->first())?->image_url
            ?? ProductImageImportService::FALLBACK_URL;
        $internalReviewCount = (int) ($this->reviews_count ?? 0);
        $rating = $internalReviewCount > 0
            ? (float) ($this->reviews_avg_rating ?? 0)
            : (float) ($this->external_rating ?? 0);
        $reviewCount = $internalReviewCount > 0
            ? $internalReviewCount
            : (int) ($this->external_review_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
            ],
            'brand' => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ],
            'primary_image' => $primaryImage,
            'primary_image_url' => $primaryImage,
            'price' => $price,
            'original_price' => $originalPrice,
            'minimum_price' => (int) $this->minimum_price,
            'has_discount' => $discountAmount > 0,
            'discount' => [
                'amount' => $discountAmount,
                'percentage' => $originalPrice > 0
                    ? (int) round(($discountAmount / $originalPrice) * 100)
                    : 0,
            ],
            'rating' => round($rating, 1),
            'review_count' => $reviewCount,
            'default_variant' => $defaultVariant === null ? null : [
                'id' => $defaultVariant->id,
                'name' => $defaultVariant->name,
                'sku' => $defaultVariant->sku,
                'attributes' => $defaultVariant->attributes ?? [],
                'price' => $defaultVariant->price,
                'sale_price' => $defaultVariant->sale_price,
                'effective_price' => $price,
            ],
            'availability' => [
                'available' => $availableQuantity > 0,
                'available_quantity' => $availableQuantity,
            ],
        ];
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        return $variant->sale_price !== null && $variant->sale_price < $variant->price
            ? $variant->sale_price
            : $variant->price;
    }
}
