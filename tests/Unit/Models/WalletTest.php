<?php

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

test('it casts wallet balance to an integer', function (): void {
    $wallet = new Wallet(['balance' => '500000']);

    expect($wallet->balance)->toBeInt()->toBe(500000);
});

test('it defines wallet relationships', function (): void {
    $wallet = new Wallet();

    expect($wallet->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($wallet->transactions()->getRelated())->toBeInstanceOf(WalletTransaction::class);
});
