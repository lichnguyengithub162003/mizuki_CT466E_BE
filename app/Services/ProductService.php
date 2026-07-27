<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\BranchInventory;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductService extends BaseService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function getActiveProducts(array $filters): LengthAwarePaginator
    {
        $categoryIds = isset($filters['category_id'])
            ? $this->getCategoryAndDescendantIds((int) $filters['category_id'])
            : [];

        return $this->products->paginateActive($filters, $categoryIds);
    }

    public function getActiveProductDetail(string $slug): ?Product
    {
        $product = $this->products->findActiveDetailBySlug($slug);

        if ($product === null) {
            return null;
        }

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
     * @return Collection<int, Product>
     */
    public function searchActiveProducts(string $keyword, int $limit = 8): Collection
    {
        return $this->products->searchActiveSuggestions($keyword, $limit);
    }

    /**
     * @return list<int>
     */
    private function getCategoryAndDescendantIds(int $categoryId): array
    {
        $categories = $this->categories->getActiveOrdered();

        if (! $categories->contains('id', $categoryId)) {
            return [-1];
        }

        $childrenByParent = $categories->groupBy('parent_id');
        $ids = [];
        $queue = [$categoryId];

        for ($index = 0; $index < count($queue); $index++) {
            $currentId = $queue[$index];
            $ids[] = $currentId;

            /** @var Category $child */
            foreach ($childrenByParent->get($currentId, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
