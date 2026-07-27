<?php

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;

test('it is immutable and casts wallet ledger amounts to integers', function (): void {
    $transaction = new WalletTransaction([
        'amount' => '100000',
        'balance_after' => '600000',
    ]);

    expect($transaction->usesTimestamps())->toBeFalse()
        ->and($transaction->amount)->toBeInt()->toBe(100000)
        ->and($transaction->balance_after)->toBeInt()->toBe(600000);
});

test('it defines wallet transaction relationships', function (): void {
    $transaction = new WalletTransaction;

    expect($transaction->wallet()->getRelated())->toBeInstanceOf(Wallet::class)
        ->and($transaction->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($transaction->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($transaction->payment()->getRelated())->toBeInstanceOf(Payment::class)
        ->and($transaction->refund()->getRelated())->toBeInstanceOf(Refund::class);
});

test('it casts ledger enums and refuses model updates and deletes', function (): void {
    $transaction = new WalletTransaction([
        'type' => 'order_payment',
        'direction' => 'debit',
    ]);

    expect($transaction->type)->toBe(WalletTransactionType::OrderPayment)
        ->and($transaction->direction)->toBe(WalletTransactionDirection::Debit);

    $query = Mockery::mock(Builder::class);
    $performUpdate = new ReflectionMethod($transaction, 'performUpdate');
    $performDelete = new ReflectionMethod($transaction, 'performDeleteOnModel');

    expect(fn (): mixed => $performUpdate->invoke($transaction, $query))
        ->toThrow(LogicException::class, 'Không thể cập nhật giao dịch ví đã ghi nhận');
    expect(fn (): mixed => $performDelete->invoke($transaction))
        ->toThrow(LogicException::class, 'Không thể xóa giao dịch ví đã ghi nhận');
});
