<?php

use App\Models\BranchInventory;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;

test('it casts SKU attributes, money, and storefront fields to their expected types', function (): void {
    $variant = new ProductVariant([
        'attributes' => ['size' => '30ml'],
        'price' => '320000',
        'sale_price' => '299000',
        'weight' => '180',
        'sort_order' => '2',
        'is_active' => 1,
    ]);

    expect($variant->attributes)->toBe(['size' => '30ml'])
        ->and($variant->price)->toBeInt()->toBe(320000)
        ->and($variant->sale_price)->toBeInt()->toBe(299000)
        ->and($variant->weight)->toBeInt()->toBe(180)
        ->and($variant->sort_order)->toBeInt()->toBe(2)
        ->and($variant->is_active)->toBeTrue();
});

test('it belongs to a product', function (): void {
    $variant = new ProductVariant();

    expect($variant->product()->getRelated())->toBeInstanceOf(Product::class);
});

test('it defines variant-dependent relationships', function (): void {
    $variant = new ProductVariant();

    expect($variant->images()->getRelated())->toBeInstanceOf(ProductImage::class)
        ->and($variant->inventories()->getRelated())->toBeInstanceOf(BranchInventory::class)
        ->and($variant->cartItems()->getRelated())->toBeInstanceOf(CartItem::class)
        ->and($variant->orderItems()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($variant->reviews()->getRelated())->toBeInstanceOf(Review::class);
});
