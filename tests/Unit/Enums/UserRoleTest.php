<?php

use App\Enums\UserRole;

test('it defines the supported user roles with stable database values', function (): void {
    expect(UserRole::cases())->toHaveCount(5)
        ->and(UserRole::Customer->value)->toBe('customer')
        ->and(UserRole::Cashier->value)->toBe('cashier')
        ->and(UserRole::Technician->value)->toBe('technician')
        ->and(UserRole::BranchManager->value)->toBe('branch_manager')
        ->and(UserRole::SuperAdmin->value)->toBe('super_admin');
});

test('it provides Vietnamese labels for user-facing role names', function (): void {
    expect(UserRole::Customer->label())->toBe('Khách hàng')
        ->and(UserRole::Cashier->label())->toBe('Thu ngân')
        ->and(UserRole::Technician->label())->toBe('Kỹ thuật viên')
        ->and(UserRole::BranchManager->label())->toBe('Quản lý chi nhánh')
        ->and(UserRole::SuperAdmin->label())->toBe('Quản trị viên hệ thống');
});
