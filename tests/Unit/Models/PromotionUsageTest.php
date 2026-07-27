<?php

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;

test('it casts applied discount and usage time to their expected types', function (): void {
    $usage = new PromotionUsage([
        'discount_amount' => '50000',
        'used_at' => '2026-06-22 12:00:00',
    ]);

    expect($usage->discount_amount)->toBeInt()->toBe(50000)
        ->and($usage->used_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to a promotion, customer, and order', function (): void {
    $usage = new PromotionUsage();

    expect($usage->promotion()->getRelated())->toBeInstanceOf(Promotion::class)
        ->and($usage->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($usage->order()->getRelated())->toBeInstanceOf(Order::class);
});
