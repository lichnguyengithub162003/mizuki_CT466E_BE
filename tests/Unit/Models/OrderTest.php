<?php

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PromotionUsage;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\WalletTransaction;

test('it casts order status, amounts, and timestamps to their expected types', function (): void {
    $order = new Order([
        'status' => OrderStatus::Pending->value,
        'ghn_district_id' => '916',
        'subtotal' => '320000',
        'discount_amount' => '20000',
        'shipping_fee' => '30000',
        'total_amount' => '330000',
        'placed_at' => '2026-06-22 09:00:00',
    ]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->ghn_district_id)->toBeInt()->toBe(916)
        ->and($order->subtotal)->toBeInt()->toBe(320000)
        ->and($order->total_amount)->toBeInt()->toBe(330000)
        ->and($order->placed_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it defines order relationships', function (): void {
    $order = new Order();

    expect($order->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($order->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($order->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($order->userAddress()->getRelated())->toBeInstanceOf(UserAddress::class)
        ->and($order->items()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($order->payments()->getRelated())->toBeInstanceOf(Payment::class)
        ->and($order->payment()->getRelated())->toBeInstanceOf(Payment::class)
        ->and($order->shipment()->getRelated())->toBeInstanceOf(Shipment::class)
        ->and($order->walletTransactions()->getRelated())->toBeInstanceOf(WalletTransaction::class)
        ->and($order->promotionUsages()->getRelated())->toBeInstanceOf(PromotionUsage::class)
        ->and($order->refunds()->getRelated())->toBeInstanceOf(Refund::class);
});
