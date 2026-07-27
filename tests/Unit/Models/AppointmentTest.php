<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;

test('it casts appointment status, service snapshot values, and schedule fields', function (): void {
    $appointment = new Appointment([
        'status' => AppointmentStatus::Confirmed->value,
        'service_price' => '450000',
        'duration_minutes' => '60',
        'starts_at' => '2026-06-22 14:00:00',
    ]);

    expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->service_price)->toBeInt()->toBe(450000)
        ->and($appointment->duration_minutes)->toBeInt()->toBe(60)
        ->and($appointment->starts_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to booking entities and has payment records', function (): void {
    $appointment = new Appointment();

    expect($appointment->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($appointment->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($appointment->service()->getRelated())->toBeInstanceOf(Service::class)
        ->and($appointment->technician()->getRelated())->toBeInstanceOf(User::class)
        ->and($appointment->payments()->getRelated())->toBeInstanceOf(Payment::class);
});
