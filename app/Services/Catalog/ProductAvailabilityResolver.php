<?php

namespace App\Services\Catalog;

use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductAvailabilityResolver
{
    private const LOW_STOCK_MAXIMUM = 5;

    /**
     * @return array{available: bool, available_quantity: int, stock_state: string}
     */
    public function resolve(Product $product): array
    {
        $availableQuantity = (int) $product->variants->sum(
            fn (ProductVariant $variant): int => (int) $variant->inventories->sum(
                fn (BranchInventory $inventory): int => max(
                    0,
                    $inventory->quantity - $inventory->reserved_quantity,
                ),
            ),
        );

        return [
            'available' => $product->is_active && $availableQuantity > 0,
            'available_quantity' => $availableQuantity,
            'stock_state' => $this->stockState($product, $availableQuantity),
        ];
    }

    private function stockState(Product $product, int $availableQuantity): string
    {
        if (! $product->is_active) {
            return 'discontinued';
        }

        if ($availableQuantity === 0) {
            return 'sold-out';
        }

        return $availableQuantity <= self::LOW_STOCK_MAXIMUM
            ? 'low-stock'
            : 'available';
    }
}
