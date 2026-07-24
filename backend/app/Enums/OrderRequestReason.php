<?php

namespace App\Enums;

enum OrderRequestReason: string
{
    case ChangedMind = 'changed_mind';
    case OrderedWrongItem = 'ordered_wrong_item';
    case ShippingDelay = 'shipping_delay';
    case ProductDamaged = 'product_damaged';
    case WrongProduct = 'wrong_product';
    case ProductQuality = 'product_quality';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ChangedMind => 'Thay đổi nhu cầu',
            self::OrderedWrongItem => 'Đặt nhầm sản phẩm',
            self::ShippingDelay => 'Giao hàng chậm',
            self::ProductDamaged => 'Sản phẩm bị hư hỏng',
            self::WrongProduct => 'Giao sai sản phẩm',
            self::ProductQuality => 'Chất lượng sản phẩm không đạt yêu cầu',
            self::Other => 'Lý do khác',
        };
    }
}
