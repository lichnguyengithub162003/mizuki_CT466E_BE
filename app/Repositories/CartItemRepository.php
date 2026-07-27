<?php

namespace App\Repositories;

use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<CartItem>
 */
class CartItemRepository extends BaseRepository
{
    public function __construct(
        CartItem $model,
        private readonly ProductVariant $variants,
        private readonly BranchInventory $inventories,
    ) {
        parent::__construct($model);
    }

    public function findActiveVariant(int $variantId): ?ProductVariant
    {
        return $this->variants->newQuery()
            ->whereKey($variantId)
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('is_active', true))
            ->first();
    }

    public function findForCart(int $cartId, int $itemId): ?CartItem
    {
        return $this->query()
            ->where('cart_id', $cartId)
            ->whereKey($itemId)
            ->first();
    }

    public function findVariantInCart(int $cartId, int $variantId): ?CartItem
    {
        return $this->query()
            ->where('cart_id', $cartId)
            ->where('product_variant_id', $variantId)
            ->first();
    }

    public function createForCart(Cart $cart, int $variantId, int $quantity): CartItem
    {
        /** @var CartItem $item */
        $item = $this->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
        ]);

        return $item;
    }

    public function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        $item->fill(['quantity' => $quantity])->save();

        return $item->refresh();
    }

    public function deleteItem(CartItem $item): bool
    {
        return (bool) $item->delete();
    }

    /**
     * @return Collection<int, BranchInventory>
     */
    public function getActiveBranchInventories(int $variantId): Collection
    {
        return $this->inventories->newQuery()
            ->where('product_variant_id', $variantId)
            ->whereHas('branch', fn (Builder $branchQuery): Builder => $branchQuery->where('is_active', true))
            ->get();
    }
}
