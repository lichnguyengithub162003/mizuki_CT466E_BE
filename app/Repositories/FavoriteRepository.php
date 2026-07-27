<?php

namespace App\Repositories;

use App\Models\ProductFavorite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<ProductFavorite>
 */
class FavoriteRepository extends BaseRepository
{
    public function __construct(ProductFavorite $model)
    {
        parent::__construct($model);
    }

    /**
     * @return LengthAwarePaginator<int, ProductFavorite>
     */
    public function paginateForUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                ->where('is_active', true)
                ->whereHas('variants', fn (Builder $variantQuery): Builder => $variantQuery->where('is_active', true)))
            ->with([
                'product' => fn (Builder|BelongsTo $productQuery): Builder|BelongsTo => $productQuery
                    ->where('is_active', true),
                'product.images' => fn (Builder|HasMany $imageQuery): Builder|HasMany => $imageQuery
                    ->where('is_primary', true)
                    ->orderBy('sort_order'),
                'product.variants' => fn (Builder|HasMany $variantQuery): Builder|HasMany => $variantQuery
                    ->where('is_active', true),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array{favorite: ProductFavorite, created: bool}
     */
    public function firstOrCreateForUser(int $userId, int $productId): array
    {
        /** @var ProductFavorite $favorite */
        $favorite = $this->query()->firstOrCreate([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        return [
            'favorite' => $favorite,
            'created' => $favorite->wasRecentlyCreated,
        ];
    }

    public function loadProductData(ProductFavorite $favorite): ProductFavorite
    {
        return $favorite->load([
            'product' => fn (Builder|BelongsTo $productQuery): Builder|BelongsTo => $productQuery
                ->where('is_active', true),
            'product.images' => fn (Builder|HasMany $imageQuery): Builder|HasMany => $imageQuery
                ->where('is_primary', true)
                ->orderBy('sort_order'),
            'product.variants' => fn (Builder|HasMany $variantQuery): Builder|HasMany => $variantQuery
                ->where('is_active', true),
        ]);
    }

    public function deleteForUser(int $userId, int $productId): bool
    {
        return $this->query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->delete() > 0;
    }
}
