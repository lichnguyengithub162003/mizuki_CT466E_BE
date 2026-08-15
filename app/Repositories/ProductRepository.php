<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<Product>
 */
class ProductRepository extends BaseRepository
{
    public function __construct(Product $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $categoryIds
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateActive(array $filters, array $categoryIds = []): LengthAwarePaginator
    {
        $query = $this->query()
            ->select('products.*')
            ->addSelect(['minimum_price' => $this->minimumPriceSubquery()])
            ->with([
                'category:id,name,parent_id',
                'brand:id,name',
                'images' => fn (Builder|HasMany $query): Builder|HasMany => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'variants' => function (Builder|HasMany $query) use ($filters): void {
                    $query->where('is_active', true)
                        ->orderBy('sort_order')
                        ->with(['inventories' => function (Builder|HasMany $inventory) use ($filters): void {
                            $inventory->when(
                                isset($filters['branch_id']),
                                fn (Builder|HasMany $builder): Builder|HasMany => $builder
                                    ->where('branch_id', (int) $filters['branch_id']),
                            );
                        }]);
                },
            ])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withExists([
                'variants as has_discount' => fn (Builder|HasMany $query): Builder|HasMany => $query
                    ->where('is_active', true)
                    ->whereNotNull('sale_price')
                    ->whereColumn('sale_price', '<', 'price'),
            ])
            ->where('products.is_active', true)
            ->whereHas('variants', fn (Builder $query): Builder => $query->where('is_active', true));

        if ($categoryIds !== []) {
            $query->whereIn('category_id', $categoryIds);
        }

        if (isset($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (isset($filters['price_min'])) {
            $this->whereMinimumPrice($query, '>=', (int) $filters['price_min']);
        }

        if (isset($filters['price_max'])) {
            $this->whereMinimumPrice($query, '<=', (int) $filters['price_max']);
        }

        if (! empty($filters['keyword'])) {
            $this->applyKeywordFilter($query, (string) $filters['keyword']);
        }

        if (isset($filters['branch_id']) || ! empty($filters['in_stock'])) {
            $query->whereHas('variants.inventories', function (Builder $inventory) use ($filters): void {
                if (isset($filters['branch_id'])) {
                    $inventory->where('branch_id', (int) $filters['branch_id']);
                }

                if (! empty($filters['in_stock'])) {
                    $inventory->whereColumn(
                        'branch_inventories.quantity',
                        '>',
                        'branch_inventories.reserved_quantity',
                    );
                }
            });
        }

        $this->applySort($query, (string) ($filters['sort'] ?? 'newest'));

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * @return Collection<int, Product>
     */
    public function searchActiveSuggestions(string $keyword, int $limit): Collection
    {
        $query = $this->query()
            ->select('products.*')
            ->addSelect(['minimum_price' => $this->minimumPriceSubquery()])
            ->with([
                'images' => fn (Builder|HasMany $imageQuery): Builder|HasMany => $imageQuery
                    ->where('is_primary', true)
                    ->orderBy('sort_order'),
            ])
            ->where('products.is_active', true)
            ->whereHas('variants', fn (Builder $variantQuery): Builder => $variantQuery->where('is_active', true));

        $this->applyKeywordFilter($query, $keyword);

        return $query
            ->orderByRaw('CASE WHEN products.name LIKE ? THEN 0 ELSE 1 END', [$keyword.'%'])
            ->orderBy('products.name')
            ->limit($limit)
            ->get();
    }

    public function findActiveDetailBySlug(string $identifier): ?Product
    {
        return $this->query()
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('products.id', (int) $identifier);
                }
            })
            ->where('is_active', true)
            ->with([
                'category:id,name,slug,parent_id',
                'category.parent:id,name,slug,parent_id',
                'category.parent.parent:id,name,slug,parent_id',
                'category.parent.parent.parent:id,name,slug,parent_id',
                'brand:id,name,slug,logo_url,follower_count',
                'images' => function (Builder|HasMany $query): void {
                    $query
                        ->orderByDesc('is_primary')
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'questions' => function (Builder|HasMany $query): void {
                    $query
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'answers' => fn (Builder|HasMany $answerQuery): Builder|HasMany => $answerQuery
                                ->orderBy('sort_order')
                                ->orderBy('id'),
                        ]);
                },
                'variants' => function (Builder|HasMany $query): void {
                    $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'inventories' => function (Builder|HasMany $inventoryQuery): void {
                                $inventoryQuery
                                    ->whereHas(
                                        'branch',
                                        fn (Builder $branchQuery): Builder => $branchQuery->where('is_active', true),
                                    )
                                    ->with('branch:id,name');
                            },
                        ]);
                },
            ])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->first();
    }

    /**
     * @param  list<string>  $externalIds
     * @return Collection<int, Product>
     */
    public function findActiveBySourceExternalIds(string $source, array $externalIds): Collection
    {
        if ($externalIds === []) {
            return new Collection;
        }

        return $this->query()
            ->select(['id', 'external_id', 'slug', 'name'])
            ->where('source', $source)
            ->where('is_active', true)
            ->whereIn('external_id', array_values(array_unique($externalIds)))
            ->get();
    }

    public function findActiveForReviews(string $identifier): ?Product
    {
        return $this->query()
            ->select(['id', 'slug'])
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Review>
     */
    public function paginateVisibleReviews(Product $product, array $filters): LengthAwarePaginator
    {
        $query = Review::query()
            ->select([
                'reviews.id',
                'reviews.user_id',
                'reviews.product_id',
                'reviews.order_item_id',
                'reviews.source',
                'reviews.source_author_name',
                'reviews.source_verified_purchase',
                'reviews.images',
                'reviews.mizuki_response_content',
                'reviews.rating',
                'reviews.title',
                'reviews.comment',
                'reviews.created_at',
            ])
            ->where('reviews.product_id', $product->id)
            ->where('reviews.is_visible', true)
            ->with('user:id,name,avatar')
            ->withExists([
                'orderItem as internal_verified_purchase' => fn (Builder $orderItem): Builder => $this
                    ->applyVerifiedPurchaseConstraints($orderItem),
            ]);

        if (isset($filters['rating'])) {
            $query->where('reviews.rating', (int) $filters['rating']);
        }

        if (array_key_exists('has_images', $filters)) {
            if ($filters['has_images']) {
                $query->whereJsonLength('reviews.images', '>', 0);
            } else {
                $query->where(function (Builder $images): void {
                    $images->whereNull('reviews.images')
                        ->orWhereJsonLength('reviews.images', 0);
                });
            }
        }

        if (array_key_exists('verified_purchase', $filters)) {
            $this->applyVerifiedPurchaseFilter($query, (bool) $filters['verified_purchase']);
        }

        return $query
            ->orderByDesc('reviews.created_at')
            ->orderByDesc('reviews.id')
            ->paginate((int) ($filters['per_page'] ?? 10));
    }

    /**
     * @return array{
     *     average_rating: float,
     *     total_reviews: int,
     *     rating_distribution: object,
     *     reviews_with_images_count: int,
     *     verified_purchase_reviews_count: int
     * }
     */
    public function visibleReviewSummary(Product $product): array
    {
        $summary = Review::query()
            ->where('product_id', $product->id)
            ->where('is_visible', true)
            ->selectRaw('COUNT(*) as total_reviews')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating')
            ->selectRaw('SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5')
            ->selectRaw('SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4')
            ->selectRaw('SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3')
            ->selectRaw('SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2')
            ->selectRaw('SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1')
            ->first();

        $verifiedPurchaseCount = Review::query()
            ->where('product_id', $product->id)
            ->where('is_visible', true)
            ->tap(fn (Builder $query): Builder => $this->applyVerifiedPurchaseFilter($query, true))
            ->count();
        $withImagesCount = Review::query()
            ->where('product_id', $product->id)
            ->where('is_visible', true)
            ->whereJsonLength('images', '>', 0)
            ->count();

        return [
            'average_rating' => round((float) ($summary?->average_rating ?? 0), 1),
            'total_reviews' => (int) ($summary?->total_reviews ?? 0),
            'rating_distribution' => (object) [
                5 => (int) ($summary?->rating_5 ?? 0),
                4 => (int) ($summary?->rating_4 ?? 0),
                3 => (int) ($summary?->rating_3 ?? 0),
                2 => (int) ($summary?->rating_2 ?? 0),
                1 => (int) ($summary?->rating_1 ?? 0),
            ],
            'reviews_with_images_count' => $withImagesCount,
            'verified_purchase_reviews_count' => $verifiedPurchaseCount,
        ];
    }

    /**
     * @param  Builder<OrderItem>  $query
     * @return Builder<OrderItem>
     */
    private function applyVerifiedPurchaseConstraints(Builder $query): Builder
    {
        return $query
            ->whereHas(
                'order',
                fn (Builder $order): Builder => $order
                    ->whereColumn('orders.user_id', 'reviews.user_id')
                    ->where(function (Builder $eligible): void {
                        $eligible
                            ->where(function (Builder $online): void {
                                $online->where('orders.channel', '!=', 'counter')
                                    ->where('orders.status', OrderStatus::Delivered->value);
                            })
                            ->orWhere(function (Builder $counter): void {
                                $counter->where('orders.channel', 'counter')
                                    ->where('orders.status', OrderStatus::Confirmed->value);
                            });
                    }),
            )
            ->whereHas(
                'productVariant',
                fn (Builder $variant): Builder => $variant
                    ->whereColumn('product_variants.product_id', 'reviews.product_id'),
            );
    }

    /**
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    private function applyVerifiedPurchaseFilter(Builder $query, bool $verified): Builder
    {
        return $query->where(function (Builder $verification) use ($verified): void {
            $verification
                ->where(function (Builder $imported) use ($verified): void {
                    $imported->where('reviews.source', 'hasaki');

                    if ($verified) {
                        $imported->where('reviews.source_verified_purchase', true);
                    } else {
                        $imported->where(function (Builder $sourceValue): void {
                            $sourceValue->whereNull('reviews.source_verified_purchase')
                                ->orWhere('reviews.source_verified_purchase', false);
                        });
                    }
                })
                ->orWhere(function (Builder $native) use ($verified): void {
                    $native->where(function (Builder $source): void {
                        $source->whereNull('reviews.source')
                            ->orWhere('reviews.source', '!=', 'hasaki');
                    });
                    $method = $verified ? 'whereHas' : 'whereDoesntHave';
                    $native->{$method}(
                        'orderItem',
                        fn (Builder $orderItem): Builder => $this->applyVerifiedPurchaseConstraints($orderItem),
                    );
                });
        });
    }

    /**
     * @return array{active_product_count: int, average_rating: float, review_count: int}
     */
    public function activeBrandStatistics(int $brandId): array
    {
        $internalReviews = Review::query()
            ->selectRaw('product_id, COUNT(*) as review_count, AVG(rating) as average_rating')
            ->groupBy('product_id');
        $reviewCount = Product::effectiveReviewCountSql('internal_reviews');
        $rating = Product::effectiveRatingSql('internal_reviews');
        $statistics = Product::query()
            ->leftJoinSub($internalReviews, 'internal_reviews', function ($join): void {
                $join->on('internal_reviews.product_id', '=', 'products.id');
            })
            ->where('products.brand_id', $brandId)
            ->where('products.is_active', true)
            ->selectRaw('COUNT(products.id) as active_product_count')
            ->selectRaw("COALESCE(SUM({$reviewCount}), 0) as review_count")
            ->selectRaw(
                "COALESCE(SUM(({$rating}) * ({$reviewCount})) / NULLIF(SUM({$reviewCount}), 0), 0) as average_rating",
            )
            ->first();

        return [
            'active_product_count' => (int) ($statistics?->active_product_count ?? 0),
            'average_rating' => (float) ($statistics?->average_rating ?? 0),
            'review_count' => (int) ($statistics?->review_count ?? 0),
        ];
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function searchActivePosVariants(string $keyword, int $branchId, int $limit): Collection
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->where(function (Builder $query) use ($keyword): void {
                $query->where('sku', 'like', "%{$keyword}%")
                    ->orWhere('barcode', 'like', "%{$keyword}%")
                    ->orWhereHas(
                        'product',
                        fn (Builder $productQuery): Builder => $productQuery
                            ->where('name', 'like', "%{$keyword}%"),
                    );
            })
            ->with([
                'product:id,name',
                'inventories' => fn (Builder|HasMany $query): Builder|HasMany => $query
                    ->where('branch_id', $branchId),
            ])
            ->orderByRaw(
                'CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END',
                [$keyword, $keyword],
            )
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function findActivePosVariantByBarcode(string $barcode, int $branchId): ?ProductVariant
    {
        return ProductVariant::query()
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->with([
                'product:id,name',
                'inventories' => fn (Builder|HasMany $query): Builder|HasMany => $query
                    ->where('branch_id', $branchId),
            ])
            ->first();
    }

    public function findActivePosVariant(int $variantId, int $branchId): ?ProductVariant
    {
        return ProductVariant::query()
            ->whereKey($variantId)
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->with([
                'product:id,name',
                'inventories' => fn (Builder|HasMany $query): Builder|HasMany => $query
                    ->where('branch_id', $branchId),
            ])
            ->first();
    }

    public function lockActivePosVariant(int $variantId): ?ProductVariant
    {
        return ProductVariant::query()
            ->whereKey($variantId)
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->with('product:id,name')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Return the lowest effective variant price for each product.
     *
     * @return Builder<ProductVariant>
     */
    private function minimumPriceSubquery(): Builder
    {
        return ProductVariant::query()
            ->selectRaw('MIN(CASE WHEN sale_price IS NOT NULL AND sale_price < price THEN sale_price ELSE price END)')
            ->whereColumn('product_id', 'products.id')
            ->where('is_active', true);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function whereMinimumPrice(Builder $query, string $operator, int $price): void
    {
        $query->where(
            fn ($subquery) => $subquery
                ->selectRaw('MIN(CASE WHEN sale_price IS NOT NULL AND sale_price < price THEN sale_price ELSE price END)')
                ->from('product_variants')
                ->whereColumn('product_id', 'products.id')
                ->where('is_active', true)
                ->whereNull('deleted_at'),
            $operator,
            $price,
        );
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyKeywordFilter(Builder $query, string $keyword): void
    {
        $query->where(function (Builder $search) use ($keyword): void {
            $search->where('products.name', 'like', '%'.$keyword.'%')
                ->orWhereHas('brand', fn (Builder $brand): Builder => $brand
                    ->where('name', 'like', '%'.$keyword.'%'))
                ->orWhereHas('variants', fn (Builder $variant): Builder => $variant
                    ->where('sku', 'like', '%'.$keyword.'%')
                    ->orWhere('name', 'like', '%'.$keyword.'%'));
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('minimum_price')->orderByDesc('products.id'),
            'price_desc' => $query->orderByDesc('minimum_price')->orderByDesc('products.id'),
            // TODO: Replace with real sales data when order analytics are implemented.
            'rating' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('products.id'),
            'name' => $query->orderBy('products.name')->orderByDesc('products.id'),
            'best_selling' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }
}
