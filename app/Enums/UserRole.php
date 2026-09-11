<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Cashier = 'cashier';
    case SalesStaff = 'sales_staff';
    case Technician = 'technician';
    case BranchManager = 'branch_manager';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Khách hàng',
            self::Cashier => 'Thu ngân',
            self::SalesStaff => 'Nhân viên bán hàng',
            self::Technician => 'Kỹ thuật viên',
            self::BranchManager => 'Quản lý chi nhánh',
            self::SuperAdmin => 'Quản trị viên hệ thống',
        };
    }
}
