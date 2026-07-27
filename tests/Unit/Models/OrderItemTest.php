<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Review;

test('it casts order item snapshot data to their expected types', function (): void {
    $item = new OrderItem([
        'variant_attributes' => ['size' => '30ml'],
        'unit_price' => '320000',
        'quantity' => '2',
        'line_total' => '640000',
    ]);

    expect($item->variant_attributes)->toBe(['size' => '30ml'])
        ->and($item->unit_price)->toBeInt()->toBe(320000)
        ->and($item->quantity)->toBeInt()->toBe(2)
        ->and($item->line_total)->toBeInt()->toBe(640000);
});

test('it defines order item relationships', function (): void {
    $item = new OrderItem();

    expect($item->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($item->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class)
        ->and($item->review()->getRelated())->toBeInstanceOf(Review::class);
});
