<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case OrderPayment = 'order_payment';
    case WalletTopUp = 'wallet_top_up';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::OrderPayment => 'Thanh toán đơn hàng',
            self::WalletTopUp => 'Nạp tiền vào ví',
            self::Refund => 'Hoàn tiền vào ví',
        };
    }
}
