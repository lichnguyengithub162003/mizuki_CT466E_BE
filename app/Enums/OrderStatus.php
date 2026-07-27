<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipping = 'shipping';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case RefundRequested = 'refund_requested';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ xác nhận',
            self::Confirmed => 'Đã xác nhận',
            self::Processing => 'Đang chuẩn bị hàng',
            self::Shipping => 'Đang giao hàng',
            self::Delivered => 'Đã giao hàng',
            self::Cancelled => 'Đã hủy',
            self::RefundRequested => 'Đã yêu cầu hoàn tiền',
            self::Refunded => 'Đã hoàn tiền',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Cancelled, self::Refunded => true,
            default => false,
        };
    }
}
