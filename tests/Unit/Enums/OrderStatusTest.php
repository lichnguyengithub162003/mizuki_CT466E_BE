<?php

use App\Enums\OrderStatus;

test('it defines the supported order statuses with stable database values', function (): void {
    expect(OrderStatus::cases())->toHaveCount(8)
        ->and(OrderStatus::Pending->value)->toBe('pending')
        ->and(OrderStatus::RefundRequested->value)->toBe('refund_requested')
        ->and(OrderStatus::Refunded->value)->toBe('refunded');
});

test('it provides Vietnamese labels for order statuses', function (): void {
    expect(OrderStatus::Pending->label())->toBe('Chờ xác nhận')
        ->and(OrderStatus::Shipping->label())->toBe('Đang giao hàng')
        ->and(OrderStatus::Refunded->label())->toBe('Đã hoàn tiền');
});

test('it identifies terminal order statuses', function (): void {
    expect(OrderStatus::Delivered->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Refunded->isTerminal())->toBeTrue()
        ->and(OrderStatus::Processing->isTerminal())->toBeFalse()
        ->and(OrderStatus::RefundRequested->isTerminal())->toBeFalse();
});
