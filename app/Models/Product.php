<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'source',
    'external_id',
    'source_url',
    'category_id',
    'brand_id',
    'name',
    'slug',
    'short_description',
    'description',
    'ingredients',
    'usage_instructions',
    'specifications',
    'origin_country',
    'is_active',
    'is_featured',
    'external_rating',
    'external_review_count',
])]
class Product extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'specifications' => 'array',
            'external_rating' => 'decimal:2',
            'external_review_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<ProductFavorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(ProductFavorite::class);
    }

    public function effectiveReviewCount(): int
    {
        $internalReviewCount = (int) ($this->reviews_count ?? 0);

        return $internalReviewCount > 0
            ? $internalReviewCount
            : (int) ($this->external_review_count ?? 0);
    }

    public function effectiveRating(): float
    {
        return (int) ($this->reviews_count ?? 0) > 0
            ? (float) ($this->reviews_avg_rating ?? 0)
            : (float) ($this->external_rating ?? 0);
    }

    public static function effectiveReviewCountSql(string $reviewAlias): string
    {
        return "CASE WHEN COALESCE({$reviewAlias}.review_count, 0) > 0 "
            ."THEN {$reviewAlias}.review_count ELSE COALESCE(products.external_review_count, 0) END";
    }

    public static function effectiveRatingSql(string $reviewAlias): string
    {
        return "CASE WHEN COALESCE({$reviewAlias}.review_count, 0) > 0 "
            ."THEN COALESCE({$reviewAlias}.average_rating, 0) ELSE COALESCE(products.external_rating, 0) END";
    }
}
