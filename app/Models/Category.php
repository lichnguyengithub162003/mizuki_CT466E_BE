<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'parent_id',
    'name',
    'slug',
    'description',
    'image_url',
    'sort_order',
    'is_active',
    'status',
    'is_featured',
])]
class Category extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $attributes = $category->getAttributes();
            $statusChanged = $category->exists
                ? $category->isDirty('status')
                : array_key_exists('status', $attributes);
            $activeChanged = $category->exists
                ? $category->isDirty('is_active')
                : array_key_exists('is_active', $attributes);

            if ($statusChanged) {
                if (! in_array($category->status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_HIDDEN], true)) {
                    throw ValidationException::withMessages(['status' => ['Trạng thái danh mục không hợp lệ.']]);
                }

                $active = $category->status === self::STATUS_PUBLISHED;
                if ($activeChanged && (bool) $category->is_active !== $active) {
                    throw ValidationException::withMessages(['is_active' => ['Trạng thái và is_active không khớp.']]);
                }
                $category->is_active = $active;
            } elseif ($activeChanged) {
                $category->status = $category->is_active ? self::STATUS_PUBLISHED : self::STATUS_HIDDEN;
            } elseif (! $category->exists) {
                $category->status = self::STATUS_PUBLISHED;
                $category->is_active = true;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
