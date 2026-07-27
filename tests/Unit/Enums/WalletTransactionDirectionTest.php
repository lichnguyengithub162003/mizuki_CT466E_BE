<?php

use App\Enums\WalletTransactionDirection;

test('wallet transaction directions have stable ledger values', function (): void {
    expect(WalletTransactionDirection::cases())->toHaveCount(2)
        ->and(WalletTransactionDirection::Debit->value)->toBe('debit')
        ->and(WalletTransactionDirection::Credit->value)->toBe('credit');
});
