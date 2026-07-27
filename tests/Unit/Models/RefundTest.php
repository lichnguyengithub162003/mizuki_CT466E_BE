<?php

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\WalletTransaction;

test('it casts refund amounts and lifecycle timestamps', function (): void {
    $refund = new Refund([
        'requested_amount' => '330000',
        'approved_amount' => '300000',
        'reviewed_at' => '2026-06-22 15:00:00',
    ]);

    expect($refund->requested_amount)->toBeInt()->toBe(330000)
        ->and($refund->approved_amount)->toBeInt()->toBe(300000)
        ->and($refund->reviewed_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to an order, customer, reviewer, and wallet transaction', function (): void {
    $refund = new Refund();

    expect($refund->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($refund->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($refund->reviewedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($refund->walletTransaction()->getRelated())->toBeInstanceOf(WalletTransaction::class);
});
