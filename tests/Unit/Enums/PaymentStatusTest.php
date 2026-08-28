<?php

use App\Enums\PaymentStatus;

test('it defines stable payment lifecycle values', function (): void {
    expect(PaymentStatus::cases())->toHaveCount(5)
        ->and(PaymentStatus::Pending->value)->toBe('pending')
        ->and(PaymentStatus::Paid->value)->toBe('paid')
        ->and(PaymentStatus::Failed->value)->toBe('failed')
        ->and(PaymentStatus::Cancelled->value)->toBe('cancelled')
        ->and(PaymentStatus::Refunded->value)->toBe('refunded');
});

test('it provides Vietnamese labels', function (): void {
    expect(PaymentStatus::Pending->label())->toBe('Chờ thanh toán')
        ->and(PaymentStatus::Paid->label())->toBe('Đã thu tiền')
        ->and(PaymentStatus::Failed->label())->toBe('Thanh toán thất bại')
        ->and(PaymentStatus::Cancelled->label())->toBe('Đã hủy thanh toán')
        ->and(PaymentStatus::Refunded->label())->toBe('Đã hoàn tiền');
});
