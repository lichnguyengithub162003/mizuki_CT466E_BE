<?php

use App\Enums\WalletTransactionType;

test('wallet transaction types have stable ledger values', function (): void {
    expect(WalletTransactionType::cases())->toHaveCount(3)
        ->and(WalletTransactionType::OrderPayment->value)->toBe('order_payment')
        ->and(WalletTransactionType::WalletTopUp->value)->toBe('wallet_top_up')
        ->and(WalletTransactionType::Refund->value)->toBe('refund');
});
