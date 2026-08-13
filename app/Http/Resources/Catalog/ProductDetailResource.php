<?php

namespace App\Http\Resources\Catalog;

use App\Models\BranchInventory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Import\ProductImageImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $breadcrumbs = [];
        $category = $this->category;

        while ($category !== null) {
            array_unshift($breadcrumbs, [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $category->parent_id,
            ]);
            $category = $category->relationLoaded('parent') ? $category->parent : null;
        }

        $realImages = $this->images->reject(
            fn (ProductImage $image): bool => $image->image_url === ProductImageImportService::FALLBACK_URL,
        );
        $displayImages = ($realImages->isNotEmpty() ? $realImages : $this->images)
            ->sort(fn (ProductImage $left, ProductImage $right): int => [
                $left->is_primary ? 0 : 1,
                $left->sort_order,
                $left->id,
            ] <=> [
                $right->is_primary ? 0 : 1,
                $right->sort_order,
                $right->id,
            ]);
        $images = $displayImages
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'product_variant_id' => $image->product_variant_id,
                'image_url' => $image->image_url,
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
                'is_primary' => $image->is_primary,
            ])
            ->values()
            ->all();

        if ($images === []) {
            $images[] = [
                'id' => null,
                'product_variant_id' => null,
                'image_url' => ProductImageImportService::FALLBACK_URL,
                'alt_text' => $this->name,
                'sort_order' => 0,
                'is_primary' => true,
            ];
        }

        $variantPrices = $this->variants->pluck('effective_price')->map(fn (mixed $price): int => (int) $price);
        $rating = $this->resource->effectiveRating();
        $reviewCount = $this->resource->effectiveReviewCount();

        return [
            'product' => [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'short_description' => $this->short_description,
                'description' => $this->description,
                'ingredients' => $this->ingredients,
                'usage_instructions' => $this->usage_instructions,
            ],
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'usage_instructions' => $this->usage_instructions,
            'specifications' => $this->specifications ?? [],
            'origin_country' => $this->origin_country,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'parent_id' => $this->category->parent_id,
            ],
            'brand' => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
                'logo_url' => $this->brand->logo_url,
                'active_product_count' => (int) $this->brand->active_product_count,
                'average_rating' => round((float) $this->brand->average_rating, 1),
                'review_count' => (int) $this->brand->review_count,
                'follower_count' => (int) $this->brand->follower_count,
                'is_following' => (bool) ($this->brand->is_following ?? false),
            ],
            'categories' => $breadcrumbs,
            'breadcrumbs' => $breadcrumbs,
            'images' => $images,
            'gallery' => $images,
            'variant_groups' => $this->variant_groups ?? [],
            'variants' => $this->variants
                ->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'attributes' => $variant->attributes,
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'effective_price' => $variant->effective_price,
                    'weight' => $variant->weight,
                    'inventories' => $variant->inventories
                        ->map(fn (BranchInventory $inventory): array => [
                            'branch_id' => $inventory->branch_id,
                            'branch_name' => $inventory->branch->name,
                            'available_quantity' => $inventory->available_quantity,
                        ])
                        ->values()
                        ->all(),
                    'total_available_quantity' => $variant->total_available_quantity,
                    'available' => $variant->available,
                ])
                ->values()
                ->all(),
            'prices' => [
                'minimum' => $variantPrices->min() ?? 0,
                'maximum' => $variantPrices->max() ?? 0,
            ],
            'rating' => round($rating, 1),
            'review_count' => $reviewCount,
            'branch_availability' => $this->variants->flatMap(
                fn (ProductVariant $variant) => $variant->inventories->map(
                    fn (BranchInventory $inventory): array => [
                        'variant_id' => $variant->id,
                        'branch_id' => $inventory->branch_id,
                        'branch_name' => $inventory->branch->name,
                        'available_quantity' => $inventory->available_quantity,
                    ],
                ),
            )->values()->all(),
            'related_products' => [],
            'reviews' => [],
            'questions_and_answers' => $this->questions
                ->map(fn ($question): array => [
                    'id' => $question->id,
                    'author' => $question->author_name,
                    'question' => $question->question,
                    'date' => $question->source_date
                        ?? $question->asked_at?->timezone(config('app.timezone'))->format('Y-m-d, H:i'),
                    'answers' => $question->answers
                        ->map(fn ($answer): array => [
                            'id' => $answer->id,
                            'author' => $answer->author_name,
                            'text' => $answer->answer,
                            'date' => $answer->source_date
                                ?? $answer->answered_at?->timezone(config('app.timezone'))->format('Y-m-d, H:i'),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
