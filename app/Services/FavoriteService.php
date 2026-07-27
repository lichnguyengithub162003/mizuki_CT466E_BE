<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductVariant;
use App\Models\User;
use App\Repositories\FavoriteRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class FavoriteService extends BaseService
{
    public function __construct(
        private readonly FavoriteRepository $favorites,
    ) {
    }

    /**
     * @return LengthAwarePaginator<int, ProductFavorite>
     */
    public function getForUser(User $user): LengthAwarePaginator
    {
        $paginator = $this->favorites->paginateForUser($user->id);

        $paginator->getCollection()->each(
            fn (ProductFavorite $favorite): ProductFavorite => $this->setMinimumPrice($favorite),
        );

        return $paginator;
    }

    public function addForUser(User $user, int $productId): ?ProductFavorite
    {
        $result = $this->favorites->firstOrCreateForUser($user->id, $productId);

        if (! $result['created']) {
            return null;
        }

        $favorite = $this->favorites->loadProductData($result['favorite']);

        return $this->setMinimumPrice($favorite);
    }

    public function removeForUser(User $user, int $productId): bool
    {
        return $this->favorites->deleteForUser($user->id, $productId);
    }

    private function setMinimumPrice(ProductFavorite $favorite): ProductFavorite
    {
        /** @var Product $product */
        $product = $favorite->product;
        $minimumPrice = $product->variants->min(
            fn (ProductVariant $variant): int => $variant->sale_price !== null && $variant->sale_price < $variant->price
                ? $variant->sale_price
                : $variant->price,
        );

        $product->setAttribute('minimum_price', (int) $minimumPrice);

        return $favorite;
    }
}
