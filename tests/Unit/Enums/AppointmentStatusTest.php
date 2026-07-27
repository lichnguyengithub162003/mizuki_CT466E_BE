<?php

use App\Enums\AppointmentStatus;

test('it defines the supported appointment statuses with stable database values', function (): void {
    expect(AppointmentStatus::cases())->toHaveCount(6)
        ->and(AppointmentStatus::Pending->value)->toBe('pending')
        ->and(AppointmentStatus::InProgress->value)->toBe('in_progress')
        ->and(AppointmentStatus::NoShow->value)->toBe('no_show');
});

test('it provides Vietnamese labels for appointment statuses', function (): void {
    expect(AppointmentStatus::Confirmed->label())->toBe('Đã xác nhận')
        ->and(AppointmentStatus::InProgress->label())->toBe('Đang thực hiện')
        ->and(AppointmentStatus::NoShow->label())->toBe('Khách không đến');
});

test('it identifies terminal appointment statuses', function (): void {
    expect(AppointmentStatus::Completed->isTerminal())->toBeTrue()
        ->and(AppointmentStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(AppointmentStatus::NoShow->isTerminal())->toBeTrue()
        ->and(AppointmentStatus::Confirmed->isTerminal())->toBeFalse();
});
