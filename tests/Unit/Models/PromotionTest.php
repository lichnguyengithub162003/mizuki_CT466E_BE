<?php

use App\Models\Branch;
use App\Models\Promotion;
use App\Models\PromotionUsage;

test('it casts promotion amounts, limits, rules, dates, and active state', function (): void {
    $promotion = new Promotion([
        'discount_value' => '10',
        'max_discount_amount' => '50000',
        'minimum_order_amount' => '300000',
        'usage_limit' => '100',
        'usage_count' => '5',
        'per_user_limit' => '1',
        'scope' => ['category_ids' => [1]],
        'rules' => ['stackable' => true],
        'starts_at' => '2026-06-22 00:00:00',
        'is_active' => 1,
    ]);

    expect($promotion->discount_value)->toBeInt()->toBe(10)
        ->and($promotion->minimum_order_amount)->toBeInt()->toBe(300000)
        ->and($promotion->scope)->toBe(['category_ids' => [1]])
        ->and($promotion->rules)->toBe(['stackable' => true])
        ->and($promotion->starts_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($promotion->is_active)->toBeTrue();
});

test('it defines promotion relationships', function (): void {
    $promotion = new Promotion();

    expect($promotion->branches()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($promotion->usages()->getRelated())->toBeInstanceOf(PromotionUsage::class);
});
