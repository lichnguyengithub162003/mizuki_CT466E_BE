<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductVariant;
use App\Models\User;
use App\Repositories\FavoriteRepository;
use App\Services\Catalog\ProductAvailabilityResolver;
use Illuminate\Pagination\LengthAwarePaginator;

class FavoriteService extends BaseService
{
    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly ProductAvailabilityResolver $availability,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ProductFavorite>
     */
    public function getForUser(User $user, ?int $branchId = null): LengthAwarePaginator
    {
        $paginator = $this->favorites->paginateForUser($user->id, $branchId);

        $paginator->getCollection()->each(
            fn (ProductFavorite $favorite): ProductFavorite => $this->setCatalogData($favorite),
        );

        return $paginator;
    }

    public function addForUser(
        User $user,
        int $productId,
        ?int $branchId = null,
    ): ?ProductFavorite {
        $result = $this->favorites->firstOrCreateForUser($user->id, $productId);

        if (! $result['created']) {
            return null;
        }

        $favorite = $this->favorites->loadProductData($result['favorite'], $branchId);

        return $this->setCatalogData($favorite);
    }

    public function removeForUser(User $user, int $productId): bool
    {
        return $this->favorites->deleteForUser($user->id, $productId);
    }

    private function setCatalogData(ProductFavorite $favorite): ProductFavorite
    {
        /** @var Product $product */
        $product = $favorite->product;
        $defaultVariant = $product->variants
            ->sortBy(fn (ProductVariant $variant): int => $this->effectivePrice($variant))
            ->first();
        $minimumPrice = $defaultVariant === null ? 0 : $this->effectivePrice($defaultVariant);
        $originalPrice = $defaultVariant !== null
            && $defaultVariant->sale_price !== null
            && $defaultVariant->sale_price < $defaultVariant->price
                ? $defaultVariant->price
                : null;
        $availability = $this->availability->resolve($product);

        $product->setAttribute('minimum_price', (int) $minimumPrice);
        $product->setAttribute('original_price', $originalPrice);
        $product->setAttribute(
            'stock_state',
            $availability['stock_state'],
        );

        return $favorite;
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        return $variant->sale_price !== null && $variant->sale_price < $variant->price
            ? $variant->sale_price
            : $variant->price;
    }
}
