<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

test('it casts product image presentation fields to their expected types', function (): void {
    $image = new ProductImage([
        'sort_order' => '3',
        'is_primary' => 1,
    ]);

    expect($image->sort_order)->toBeInt()->toBe(3)
        ->and($image->is_primary)->toBeTrue();
});

test('it belongs to a product and an optional product variant', function (): void {
    $image = new ProductImage();

    expect($image->product()->getRelated())->toBeInstanceOf(Product::class)
        ->and($image->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class);
});
