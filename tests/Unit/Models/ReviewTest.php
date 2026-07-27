<?php

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;

test('it casts review rating, visibility, and moderation time', function (): void {
    $review = new Review([
        'rating' => '5',
        'is_visible' => 1,
        'moderated_at' => '2026-06-22 16:00:00',
    ]);

    expect($review->rating)->toBeInt()->toBe(5)
        ->and($review->is_visible)->toBeTrue()
        ->and($review->moderated_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to review, product, purchase, and moderation entities', function (): void {
    $review = new Review();

    expect($review->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($review->product()->getRelated())->toBeInstanceOf(Product::class)
        ->and($review->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class)
        ->and($review->orderItem()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($review->moderatedBy()->getRelated())->toBeInstanceOf(User::class);
});
