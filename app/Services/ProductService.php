<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use App\Repositories\BrandRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductService extends BaseService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly BrandRepository $brands,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function getActiveProducts(array $filters): LengthAwarePaginator
    {
        $categoryIds = isset($filters['category_id'])
            ? $this->categories->getActiveCategoryAndDescendantIds((int) $filters['category_id'])
            : [];

        return $this->products->paginateActive($filters, $categoryIds);
    }

    public function getActiveProductDetail(string $slug, ?User $viewer = null): ?Product
    {
        $product = $this->products->findActiveDetailBySlug($slug);

        if ($product === null) {
            return null;
        }

        $product->brand->forceFill(
            [
                ...$this->products->activeBrandStatistics((int) $product->brand_id),
                'is_following' => $viewer?->role === UserRole::Customer
                    && $this->brands->isFollowedBy($product->brand, $viewer),
            ],
        );
        $product->forceFill([
            'variant_groups' => $this->resolvedVariantGroups($product),
        ]);

        foreach ($product->variants as $variant) {
            $effectivePrice = $variant->sale_price !== null && $variant->sale_price < $variant->price
                ? $variant->sale_price
                : $variant->price;

            $availableInventories = $variant->inventories
                ->map(function (BranchInventory $inventory): BranchInventory {
                    $inventory->setAttribute(
                        'available_quantity',
                        max(0, $inventory->quantity - $inventory->reserved_quantity),
                    );

                    return $inventory;
                })
                ->filter(fn (BranchInventory $inventory): bool => $inventory->available_quantity > 0)
                ->values();

            $totalAvailableQuantity = (int) $availableInventories->sum('available_quantity');

            $variant->setAttribute('effective_price', $effectivePrice);
            $variant->setAttribute('total_available_quantity', $totalAvailableQuantity);
            $variant->setAttribute('available', $totalAvailableQuantity > 0);
            $variant->setRelation('inventories', $availableInventories);
        }

        return $product;
    }

    /**
     * Resolve source family members in one query while retaining unresolved source metadata.
     *
     * @return list<array<string, mixed>>
     */
    private function resolvedVariantGroups(Product $product): array
    {
        $groups = is_array($product->source_variant_groups)
            ? $product->source_variant_groups
            : [];
        $externalIds = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['options'] ?? [] as $option) {
                if (! is_array($option)) {
                    continue;
                }

                foreach ($option['products'] ?? [] as $sourceProduct) {
                    if (is_array($sourceProduct) && isset($sourceProduct['external_id'])) {
                        $externalIds[] = (string) $sourceProduct['external_id'];
                    }
                }
            }
        }

        $localProducts = $this->products
            ->findActiveBySourceExternalIds('hasaki', array_values(array_unique($externalIds)))
            ->keyBy(fn (Product $localProduct): string => (string) $localProduct->external_id);

        return array_values(array_map(
            static function (mixed $group) use ($localProducts): mixed {
                if (! is_array($group)) {
                    return $group;
                }

                $group['options'] = array_values(array_map(
                    static function (mixed $option) use ($localProducts): mixed {
                        if (! is_array($option)) {
                            return $option;
                        }

                        $option['products'] = array_values(array_map(
                            static function (mixed $sourceProduct) use ($localProducts): mixed {
                                if (! is_array($sourceProduct)) {
                                    return $sourceProduct;
                                }

                                $externalId = isset($sourceProduct['external_id'])
                                    ? (string) $sourceProduct['external_id']
                                    : '';
                                /** @var Product|null $localProduct */
                                $localProduct = $localProducts->get($externalId);

                                return [
                                    ...$sourceProduct,
                                    'product_id' => $localProduct?->id,
                                    'slug' => $localProduct?->slug,
                                    'name' => $localProduct?->name,
                                ];
                            },
                            is_array($option['products'] ?? null) ? $option['products'] : [],
                        ));

                        return $option;
                    },
                    is_array($group['options'] ?? null) ? $group['options'] : [],
                ));

                return $group;
            },
            $groups,
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{reviews: LengthAwarePaginator, summary: array<string, mixed>}|null
     */
    public function getActiveProductReviews(string $slug, array $filters): ?array
    {
        $product = $this->products->findActiveForReviews($slug);

        if ($product === null) {
            return null;
        }

        return [
            'reviews' => $this->products->paginateVisibleReviews($product, $filters),
            'summary' => $this->products->visibleReviewSummary($product),
        ];
    }

    /**
     * @return Collection<int, Product>
     */
    public function searchActiveProducts(string $keyword, int $limit = 8): Collection
    {
        return $this->products->searchActiveSuggestions($keyword, $limit);
    }
}
