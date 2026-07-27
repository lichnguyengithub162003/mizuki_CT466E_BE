<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Cashier = 'cashier';
    case Technician = 'technician';
    case BranchManager = 'branch_manager';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Khách hàng',
            self::Cashier => 'Thu ngân',
            self::Technician => 'Kỹ thuật viên',
            self::BranchManager => 'Quản lý chi nhánh',
            self::SuperAdmin => 'Quản trị viên hệ thống',
        };
    }
}
