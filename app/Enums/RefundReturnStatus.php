<?php

namespace App\Enums;

enum RefundReturnStatus: string
{
    case NotRequired = 'not_required';
    case AwaitingReturn = 'awaiting_return';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Restocked = 'restocked';
    case NotRestockable = 'not_restockable';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Không cần hoàn hàng',
            self::AwaitingReturn => 'Chờ khách hoàn hàng',
            self::InTransit => 'Đang hoàn hàng',
            self::Received => 'Đã nhận hàng hoàn',
            self::Restocked => 'Đã nhập lại kho',
            self::NotRestockable => 'Không thể nhập lại kho',
        };
    }
}
