<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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
    $appointment = new Appointment;

    expect($appointment->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($appointment->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($appointment->service()->getRelated())->toBeInstanceOf(Service::class)
        ->and($appointment->technician()->getRelated())->toBeInstanceOf(User::class)
        ->and($appointment->payments()->getRelated())->toBeInstanceOf(Payment::class);
});

test('walk-in schema makes user nullable and adds customer snapshots', function (): void {
    $userColumn = collect(Schema::getColumns('appointments'))->firstWhere('name', 'user_id');

    expect(Schema::hasColumns('appointments', ['customer_name', 'customer_phone']))->toBeTrue()
        ->and($userColumn)->not->toBeNull()
        ->and($userColumn['nullable'])->toBeTrue();
});

test('appointment accepts a null user and walk-in customer snapshots', function (): void {
    $branch = Branch::query()->create([
        'code' => 'UNIT-WALK-IN',
        'name' => 'Unit Walk-in Clinic',
        'phone' => '02920000000',
        'address' => 'C?n Th?',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Walk-in Service',
        'slug' => 'unit-walk-in-service',
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
    ]);

    $appointment = Appointment::query()->create([
        'appointment_number' => 'APT-UNIT-WALK-IN',
        'user_id' => null,
        'customer_name' => 'Kh?ch v?ng lai',
        'customer_phone' => '0901234567',
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'status' => AppointmentStatus::Confirmed,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => $service->duration_minutes,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 10:00:00',
    ]);

    expect($appointment->user_id)->toBeNull()
        ->and($appointment->customer_name)->toBe('Kh?ch v?ng lai')
        ->and($appointment->customer_phone)->toBe('0901234567')
        ->and($appointment->user)->toBeNull();
});

test('existing appointment customer relationship still resolves', function (): void {
    $user = User::factory()->create();
    $appointment = new Appointment(['user_id' => $user->id]);
    $appointment->setRelation('user', $user);

    expect($appointment->user)->toBe($user)
        ->and($appointment->user()->getForeignKeyName())->toBe('user_id');
});

test('walk-in migration rolls back and reapplies safely on an empty database', function (): void {
    $migration = require database_path(
        'migrations/2026_07_29_000000_add_walk_in_customer_fields_to_appointments_table.php',
    );

    $migration->down();

    $userColumn = collect(Schema::getColumns('appointments'))->firstWhere('name', 'user_id');
    expect(Schema::hasColumn('appointments', 'customer_name'))->toBeFalse()
        ->and(Schema::hasColumn('appointments', 'customer_phone'))->toBeFalse()
        ->and($userColumn['nullable'])->toBeFalse();

    $migration->up();

    $userColumn = collect(Schema::getColumns('appointments'))->firstWhere('name', 'user_id');
    expect(Schema::hasColumns('appointments', ['customer_name', 'customer_phone']))->toBeTrue()
        ->and($userColumn['nullable'])->toBeTrue();
});

test('walk-in migration refuses rollback while null user appointments exist', function (): void {
    $branch = Branch::query()->create([
        'code' => 'ROLLBACK-GUARD',
        'name' => 'Rollback Guard',
        'phone' => '02920000001',
        'address' => 'C?n Th?',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Guard Service',
        'slug' => 'guard-service',
        'duration_minutes' => 30,
        'price' => 100000,
        'is_active' => true,
    ]);
    Appointment::query()->create([
        'appointment_number' => 'APT-ROLLBACK-GUARD',
        'user_id' => null,
        'customer_name' => 'Walk-in',
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'status' => AppointmentStatus::Confirmed,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => 30,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 09:30:00',
    ]);
    $migration = require database_path(
        'migrations/2026_07_29_000000_add_walk_in_customer_fields_to_appointments_table.php',
    );

    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        'Cannot roll back walk-in appointment support while appointments with a null user_id exist.',
    );

    expect(Schema::hasColumns('appointments', ['customer_name', 'customer_phone']))->toBeTrue();
});
