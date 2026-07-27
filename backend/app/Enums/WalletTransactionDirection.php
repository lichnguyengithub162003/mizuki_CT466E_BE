<?php

namespace App\Enums;

enum WalletTransactionDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Tiền ra',
            self::Credit => 'Tiền vào',
        };
    }
}
