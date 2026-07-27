<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [
                self::Paid,
                self::Failed,
                self::Cancelled,
            ], true),
            self::Failed => $target === self::Paid,
            self::Paid => $target === self::Refunded,
            self::Cancelled, self::Refunded => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ thanh toán',
            self::Paid => 'Đã thanh toán',
            self::Failed => 'Thanh toán thất bại',
            self::Cancelled => 'Đã hủy thanh toán',
            self::Refunded => 'Đã hoàn tiền',
        };
    }
}
