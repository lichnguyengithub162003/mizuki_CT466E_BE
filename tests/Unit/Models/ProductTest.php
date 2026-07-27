<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;

test('it casts storefront product flags to booleans', function (): void {
    $product = new Product([
        'is_active' => 1,
        'is_featured' => 0,
    ]);

    expect($product->is_active)->toBeTrue()
        ->and($product->is_featured)->toBeFalse();
});

test('it belongs to a category and brand', function (): void {
    $product = new Product();

    expect($product->category()->getRelated())->toBeInstanceOf(Category::class)
        ->and($product->brand()->getRelated())->toBeInstanceOf(Brand::class);
});

test('it defines product catalog and review relationships', function (): void {
    $product = new Product();

    expect($product->variants()->getRelated())->toBeInstanceOf(ProductVariant::class)
        ->and($product->images()->getRelated())->toBeInstanceOf(ProductImage::class)
        ->and($product->reviews()->getRelated())->toBeInstanceOf(Review::class);
});
