<?php

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;

test('it defines cart relationships', function (): void {
    $cart = new Cart();

    expect($cart->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($cart->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($cart->items()->getRelated())->toBeInstanceOf(CartItem::class);
});

test('it permits user and branch assignment', function (): void {
    $cart = new Cart();

    expect($cart->isFillable('user_id'))->toBeTrue()
        ->and($cart->isFillable('branch_id'))->toBeTrue();
});
