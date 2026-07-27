<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;

test('it casts cart item quantity to an integer', function (): void {
    $item = new CartItem(['quantity' => '3']);

    expect($item->quantity)->toBeInt()->toBe(3);
});

test('it belongs to a cart and product variant', function (): void {
    $item = new CartItem();

    expect($item->cart()->getRelated())->toBeInstanceOf(Cart::class)
        ->and($item->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class);
});
