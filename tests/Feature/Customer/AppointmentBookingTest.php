<?php

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-28 08:00:00');
    CarbonImmutable::setTestNow('2026-07-28 08:00:00');

    $token = Str::upper(Str::random(8));
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->branch = Branch::query()->create([
        'code' => 'APT-'.$token,
        'name' => 'Mizuki Clinic '.$token,
        'branch_type' => BranchType::Hybrid,
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $this->service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Chăm sóc da '.$token,
        'slug' => 'appointment-service-'.strtolower($token),
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $this->branchService = BranchService::query()->create([
        'branch_id' => $this->branch->id,
        'service_id' => $this->service->id,
        'is_available' => true,
        'capacity' => 2,
    ]);
    BranchBusinessHour::query()->create([
        'branch_id' => $this->branch->id,
        'weekday' => 1,
        'opens_at' => '08:00:00',
        'closes_at' => '21:00:00',
        'is_closed' => false,
    ]);

    $this->bookingDate = '2026-08-03';
    $this->validPayload = [
        'branch_id' => $this->branch->id,
        'service_id' => $this->service->id,
        'appointment_date' => $this->bookingDate,
        'start_time' => '09:00',
        'customer_note' => 'Da nhạy cảm',
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function createBookedAppointment(
    User $user,
    Branch $branch,
    Service $service,
    array $overrides = [],
): Appointment {
    return Appointment::query()->create(array_merge([
        'appointment_number' => 'APT-TEST-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'status' => AppointmentStatus::Pending,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => $service->duration_minutes,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 10:00:00',
    ], $overrides));
}

test('guest is denied on every customer appointment endpoint', function (): void {
    $this->postJson('/api/v1/customer/appointments', $this->validPayload)->assertUnauthorized();
    $this->getJson('/api/v1/customer/appointments')->assertUnauthorized();
    $this->getJson('/api/v1/customer/appointments/1')->assertUnauthorized();
    $this->postJson('/api/v1/customer/appointments/1/cancel')->assertUnauthorized();
});

test('staff is denied on every customer appointment endpoint', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician]);
    $this->actingAs($staff);

    $this->postJson('/api/v1/customer/appointments', $this->validPayload)->assertForbidden();
    $this->getJson('/api/v1/customer/appointments')->assertForbidden();
    $this->getJson('/api/v1/customer/appointments/1')->assertForbidden();
    $this->postJson('/api/v1/customer/appointments/1/cancel')->assertForbidden();
});

test('customer creates a pending appointment with immutable service snapshots', function (): void {
    $this->actingAs($this->customer);

    $response = $this->postJson('/api/v1/customer/appointments', $this->validPayload)
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.branch.id', $this->branch->id)
        ->assertJsonPath('data.service.id', $this->service->id)
        ->assertJsonPath('data.service.name', $this->service->name)
        ->assertJsonPath('data.service.price', 450000)
        ->assertJsonPath('data.service.duration_minutes', 60)
        ->assertJsonPath('data.customer_note', 'Da nhạy cảm');

    expect($response->json('data.starts_at'))->toContain('T09:00:00+07:00')
        ->and($response->json('data.ends_at'))->toContain('T10:00:00+07:00');

    $this->assertDatabaseHas('appointments', [
        'id' => $response->json('data.id'),
        'user_id' => $this->customer->id,
        'status' => 'pending',
        'service_name' => $this->service->name,
        'service_price' => 450000,
        'duration_minutes' => 60,
    ]);
});

test('store-only and inactive clinic branches cannot accept booking', function (array $changes): void {
    $this->branch->update($changes);
    $this->actingAs($this->customer);

    $this->postJson('/api/v1/customer/appointments', $this->validPayload)
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('appointments', 0);
})->with([
    'store-only branch' => [['branch_type' => BranchType::Store]],
    'inactive clinic branch' => [['is_active' => false]],
]);

test('inactive or unavailable service cannot accept booking', function (string $target): void {
    if ($target === 'service') {
        $this->service->update(['is_active' => false]);
    } else {
        $this->branchService->update(['is_available' => false]);
    }

    $this->actingAs($this->customer);
    $this->postJson('/api/v1/customer/appointments', $this->validPayload)
        ->assertUnprocessable();
    $this->assertDatabaseCount('appointments', 0);
})->with(['inactive service' => ['service'], 'unavailable branch service' => ['branch_service']]);

test('past and more than ninety day future booking dates are rejected', function (string $date): void {
    $this->actingAs($this->customer);

    $this->postJson('/api/v1/customer/appointments', [
        ...$this->validPayload,
        'appointment_date' => $date,
    ])->assertUnprocessable()->assertJsonStructure([
        'data' => ['errors' => ['appointment_date']],
    ]);
})->with(['past' => ['2026-07-27'], 'over 90 days' => ['2026-10-27']]);

test('non-grid and outside-hours starts are rejected without partial writes', function (string $startTime): void {
    $this->actingAs($this->customer);

    $this->postJson('/api/v1/customer/appointments', [
        ...$this->validPayload,
        'start_time' => $startTime,
    ])->assertUnprocessable()->assertJsonStructure([
        'data' => ['errors' => ['start_time']],
    ]);

    $this->assertDatabaseCount('appointments', 0);
})->with([
    'non-grid' => ['09:15'],
    'before clinic window' => ['08:30'],
    'too late for service duration' => ['19:30'],
]);

test('full capacity rejects booking and creates no partial appointment', function (): void {
    $otherCustomers = User::factory()->count(2)->create(['role' => UserRole::Customer]);

    foreach ($otherCustomers as $otherCustomer) {
        createBookedAppointment($otherCustomer, $this->branch, $this->service);
    }

    $this->actingAs($this->customer);
    $this->postJson('/api/v1/customer/appointments', $this->validPayload)
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['start_time']]]);

    $this->assertDatabaseCount('appointments', 2);
});

test('cancelled appointments do not consume capacity', function (): void {
    $otherCustomers = User::factory()->count(2)->create(['role' => UserRole::Customer]);
    createBookedAppointment($otherCustomers[0], $this->branch, $this->service);
    createBookedAppointment($otherCustomers[1], $this->branch, $this->service, [
        'status' => AppointmentStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $this->actingAs($this->customer);
    $this->postJson('/api/v1/customer/appointments', $this->validPayload)->assertCreated();
    $this->assertDatabaseCount('appointments', 3);
});

test('customer cannot create duplicate or overlapping active appointments', function (string $startTime): void {
    createBookedAppointment($this->customer, $this->branch, $this->service);
    $this->actingAs($this->customer);

    $this->postJson('/api/v1/customer/appointments', [
        ...$this->validPayload,
        'start_time' => $startTime,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.start_time.0', 'Bạn đã có lịch hẹn trùng với khung giờ này!');

    $this->assertDatabaseCount('appointments', 1);
})->with(['same slot' => ['09:00'], 'overlapping slot' => ['09:30']]);

test('customer list is owned scoped filterable paginated and newest first', function (): void {
    $older = createBookedAppointment($this->customer, $this->branch, $this->service, [
        'status' => AppointmentStatus::Confirmed,
        'created_at' => now()->subDay(),
    ]);
    $newer = createBookedAppointment($this->customer, $this->branch, $this->service, [
        'status' => AppointmentStatus::Pending,
        'starts_at' => '2026-08-03 10:00:00',
        'ends_at' => '2026-08-03 11:00:00',
        'created_at' => now(),
    ]);
    $other = User::factory()->create(['role' => UserRole::Customer]);
    createBookedAppointment($other, $this->branch, $this->service, [
        'starts_at' => '2026-08-03 11:00:00',
        'ends_at' => '2026-08-03 12:00:00',
    ]);
    $this->actingAs($this->customer);

    $this->getJson('/api/v1/customer/appointments?per_page=10')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('meta.pagination.total', 2);

    $this->getJson('/api/v1/customer/appointments?status=confirmed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $older->id);
});

test('customer can view own detail but another customer receives 404', function (): void {
    $appointment = createBookedAppointment($this->customer, $this->branch, $this->service);
    $other = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($this->customer)
        ->getJson("/api/v1/customer/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $appointment->id);

    $this->actingAs($other)
        ->getJson("/api/v1/customer/appointments/{$appointment->id}")
        ->assertNotFound();
});

test('customer can cancel pending and confirmed appointments', function (AppointmentStatus $status): void {
    $appointment = createBookedAppointment($this->customer, $this->branch, $this->service, [
        'status' => $status,
    ]);
    $this->actingAs($this->customer);

    $this->postJson("/api/v1/customer/appointments/{$appointment->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointment->cancelled_at)->not->toBeNull();
})->with([
    'pending' => [AppointmentStatus::Pending],
    'confirmed' => [AppointmentStatus::Confirmed],
]);

test('customer cannot cancel in-progress terminal or already cancelled appointments', function (AppointmentStatus $status): void {
    $appointment = createBookedAppointment($this->customer, $this->branch, $this->service, [
        'status' => $status,
        'cancelled_at' => $status === AppointmentStatus::Cancelled ? now() : null,
    ]);
    $this->actingAs($this->customer);

    $this->postJson("/api/v1/customer/appointments/{$appointment->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['status']]]);
})->with([
    'in progress' => [AppointmentStatus::InProgress],
    'completed' => [AppointmentStatus::Completed],
    'no show' => [AppointmentStatus::NoShow],
    'already cancelled' => [AppointmentStatus::Cancelled],
]);

test('customer cannot cancel another customers appointment', function (): void {
    $other = User::factory()->create(['role' => UserRole::Customer]);
    $appointment = createBookedAppointment($other, $this->branch, $this->service);
    $this->actingAs($this->customer);

    $this->postJson("/api/v1/customer/appointments/{$appointment->id}/cancel")
        ->assertNotFound();
});

test('appointment insert failures roll back the transaction without partial records', function (): void {
    $eventName = 'eloquent.created: '.Appointment::class;
    Event::listen($eventName, function (): never {
        throw new RuntimeException('Simulated appointment persistence failure');
    });
    $this->actingAs($this->customer);
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->postJson('/api/v1/customer/appointments', $this->validPayload))
            ->toThrow(RuntimeException::class, 'Simulated appointment persistence failure');
    } finally {
        Event::forget($eventName);
    }

    $this->assertDatabaseCount('appointments', 0);
});
test('appointment creation is limited to five attempts per customer per minute', function (): void {
    $this->branchService->update(['capacity' => 10]);
    $this->actingAs($this->customer);

    foreach (['09:00', '10:00', '11:00', '12:00', '13:00'] as $startTime) {
        $this->postJson('/api/v1/customer/appointments', [
            ...$this->validPayload,
            'start_time' => $startTime,
        ])->assertCreated();
    }

    $this->postJson('/api/v1/customer/appointments', [
        ...$this->validPayload,
        'start_time' => '14:00',
    ])
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);

    $this->assertDatabaseCount('appointments', 5);
});
