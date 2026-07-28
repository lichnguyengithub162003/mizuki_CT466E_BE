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
    $token = Str::upper(Str::random(6));

    $makeBranch = function (string $suffix) use ($token): Branch {
        return Branch::query()->create([
            'code' => "ADM-{$token}-{$suffix}",
            'name' => "Admin Clinic {$suffix}",
            'branch_type' => BranchType::Hybrid,
            'phone' => '02920000000',
            'address' => 'Cần Thơ',
            'province_code' => 'CT',
            'ghn_district_id' => 1442,
            'ghn_ward_code' => '21012',
            'is_active' => true,
        ]);
    };

    $this->branch = $makeBranch('A');
    $this->otherBranch = $makeBranch('B');
    $this->service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Admin Appointment Service',
        'slug' => 'admin-appointment-service-'.strtolower($token),
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
    ]);

    foreach ([$this->branch, $this->otherBranch] as $branch) {
        BranchService::query()->create([
            'branch_id' => $branch->id,
            'service_id' => $this->service->id,
            'is_available' => true,
            'capacity' => 2,
        ]);
        BranchBusinessHour::query()->create([
            'branch_id' => $branch->id,
            'weekday' => 1,
            'opens_at' => '09:00:00',
            'closes_at' => '20:00:00',
            'is_closed' => false,
        ]);
    }

    $this->superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $this->branch->id,
    ]);
    $this->technician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->branch->id,
    ]);
    $this->otherTechnician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->otherBranch->id,
    ]);
    $this->customer = User::factory()->create([
        'role' => UserRole::Customer,
        'phone' => '0901234567',
    ]);
    $this->bookingDate = '2026-08-03';
    $this->walkInPayload = [
        'branch_id' => $this->branch->id,
        'service_id' => $this->service->id,
        'appointment_date' => $this->bookingDate,
        'start_time' => '09:00',
        'customer_name' => 'Khách walk-in',
        'customer_phone' => '0912345678',
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function makeAdminAppointment(
    Branch $branch,
    Service $service,
    ?User $customer = null,
    array $overrides = [],
): Appointment {
    return Appointment::query()->create(array_merge([
        'appointment_number' => 'APT-ADM-'.Str::upper(Str::random(9)),
        'user_id' => $customer?->id,
        'customer_name' => $customer?->name ?? 'Walk-in Admin',
        'customer_phone' => $customer?->phone ?? '0900000000',
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

test('guest customer and technician cannot access admin appointment endpoints', function (): void {
    $this->getJson('/api/v1/admin/appointments')->assertUnauthorized();

    foreach ([$this->customer, $this->technician] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/admin/appointments')
            ->assertForbidden();
        $this->postJson('/api/v1/admin/appointments/walk-in', $this->walkInPayload)
            ->assertForbidden();
    }
});

test('super admin lists all branches while manager only sees own branch', function (): void {
    $own = makeAdminAppointment($this->branch, $this->service, $this->customer);
    $other = makeAdminAppointment($this->otherBranch, $this->service, $this->customer);

    $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/admin/appointments')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2);

    $response = $this->actingAs($this->manager)
        ->getJson('/api/v1/admin/appointments')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $own->id);

    expect(collect($response->json('data'))->pluck('id'))->not->toContain($other->id);
});

test('branch manager cannot view another branch appointment', function (): void {
    $appointment = makeAdminAppointment($this->otherBranch, $this->service);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/admin/appointments/{$appointment->id}")
        ->assertNotFound();
});

test('admin list filters by status branch technician date and keyword', function (): void {
    $match = makeAdminAppointment($this->branch, $this->service, $this->customer, [
        'status' => AppointmentStatus::Confirmed,
        'technician_id' => $this->technician->id,
        'appointment_number' => 'APT-FILTER-TARGET',
    ]);
    makeAdminAppointment($this->otherBranch, $this->service, null, [
        'starts_at' => '2026-08-04 09:00:00',
        'ends_at' => '2026-08-04 10:00:00',
    ]);
    $this->actingAs($this->superAdmin);

    foreach ([
        'status=confirmed',
        "branch_id={$this->branch->id}",
        "technician_id={$this->technician->id}",
        'appointment_date=2026-08-03',
        'keyword=FILTER-TARGET',
        'keyword='.$this->customer->email,
    ] as $query) {
        $this->getJson('/api/v1/admin/appointments?'.$query)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $match->id);
    }
});

test('walk-in with customer account snapshots customer and creates confirmed appointment', function (): void {
    $this->actingAs($this->manager);
    $payload = $this->walkInPayload;
    unset($payload['customer_name'], $payload['customer_phone']);
    $payload['customer_id'] = $this->customer->id;

    $response = $this->postJson('/api/v1/admin/appointments/walk-in', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.customer.registered', true);

    $this->assertDatabaseHas('appointments', [
        'id' => $response->json('data.id'),
        'user_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'customer_phone' => $this->customer->phone,
        'service_price' => $this->service->price,
    ]);
});

test('anonymous walk-in works and requires either name or phone', function (): void {
    $this->actingAs($this->manager);

    $this->postJson('/api/v1/admin/appointments/walk-in', $this->walkInPayload)
        ->assertCreated()
        ->assertJsonPath('data.customer.registered', false)
        ->assertJsonPath('data.customer.name', 'Khách walk-in');

    $invalid = $this->walkInPayload;
    unset($invalid['customer_name'], $invalid['customer_phone']);
    $this->postJson('/api/v1/admin/appointments/walk-in', [
        ...$invalid,
        'start_time' => '10:00',
    ])->assertUnprocessable();
});

test('manager cannot create walk-in for another branch', function (): void {
    $this->actingAs($this->manager);

    $this->postJson('/api/v1/admin/appointments/walk-in', [
        ...$this->walkInPayload,
        'branch_id' => $this->otherBranch->id,
    ])->assertForbidden();
});

test('invalid clinic branch service and slot are rejected', function (string $case): void {
    if ($case === 'service') {
        BranchService::query()
            ->where('branch_id', $this->branch->id)
            ->where('service_id', $this->service->id)
            ->update(['is_available' => false]);
    }

    if ($case === 'branch') {
        $this->branch->update(['branch_type' => BranchType::Store]);
    }

    $payload = $this->walkInPayload;
    if ($case === 'slot') {
        $payload['start_time'] = '09:15';
    }

    $this->actingAs($this->manager)
        ->postJson('/api/v1/admin/appointments/walk-in', $payload)
        ->assertUnprocessable();

    $this->assertDatabaseCount('appointments', 0);
})->with([
    'store-only branch' => ['branch'],
    'unavailable service' => ['service'],
    'invalid slot' => ['slot'],
]);

test('walk-in capacity is enforced', function (): void {
    makeAdminAppointment($this->branch, $this->service);
    makeAdminAppointment($this->branch, $this->service);
    $this->actingAs($this->manager);

    $this->postJson('/api/v1/admin/appointments/walk-in', $this->walkInPayload)
        ->assertUnprocessable();
    $this->assertDatabaseCount('appointments', 2);
});

test('admin confirms pending appointment and rejects invalid confirm transition', function (): void {
    $appointment = makeAdminAppointment($this->branch, $this->service);
    $this->actingAs($this->manager);

    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');
    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/confirm")
        ->assertUnprocessable();
});

test('technician assignment is branch scoped and idempotent', function (): void {
    $appointment = makeAdminAppointment($this->branch, $this->service, null, [
        'status' => AppointmentStatus::Confirmed,
    ]);
    $this->actingAs($this->manager);

    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/assign-technician", [
        'technician_id' => $this->technician->id,
    ])->assertOk()->assertJsonPath('data.technician.id', $this->technician->id);

    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/assign-technician", [
        'technician_id' => $this->technician->id,
    ])->assertOk();

    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/assign-technician", [
        'technician_id' => $this->otherTechnician->id,
    ])->assertUnprocessable();

    expect($appointment->refresh()->technician_id)->toBe($this->technician->id);
});

test('admin starts assigned confirmed appointment then completes it', function (): void {
    $appointment = makeAdminAppointment($this->branch, $this->service, null, [
        'status' => AppointmentStatus::Confirmed,
        'technician_id' => $this->technician->id,
    ]);
    $this->actingAs($this->manager);

    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/start")
        ->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/admin/appointments/{$appointment->id}/complete", [
        'staff_note' => 'Đã hoàn thành liệu trình',
    ])->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.staff_note', 'Đã hoàn thành liệu trình');

    expect($appointment->refresh()->completed_at)->not->toBeNull();
});

test('admin cannot start without technician assignment', function (): void {
    $appointment = makeAdminAppointment($this->branch, $this->service, null, [
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/admin/appointments/{$appointment->id}/start")
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['technician_id']]]);
});

test('admin cancels pending or confirmed but terminal transitions are rejected', function (AppointmentStatus $status): void {
    $appointment = makeAdminAppointment($this->branch, $this->service, null, ['status' => $status]);
    $this->actingAs($this->manager);

    $response = $this->postJson("/api/v1/admin/appointments/{$appointment->id}/cancel");

    if (in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect($appointment->refresh()->cancelled_at)->not->toBeNull();
    } else {
        $response->assertUnprocessable();
    }
})->with([
    'pending' => [AppointmentStatus::Pending],
    'confirmed' => [AppointmentStatus::Confirmed],
    'in progress' => [AppointmentStatus::InProgress],
    'completed' => [AppointmentStatus::Completed],
    'cancelled' => [AppointmentStatus::Cancelled],
]);

test('injected appointment creation failure rolls back walk-in transaction', function (): void {
    $eventName = 'eloquent.created: '.Appointment::class;
    Event::listen($eventName, function (): never {
        throw new RuntimeException('Simulated walk-in failure');
    });
    $this->actingAs($this->manager);
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->postJson('/api/v1/admin/appointments/walk-in', $this->walkInPayload))
            ->toThrow(RuntimeException::class, 'Simulated walk-in failure');
    } finally {
        Event::forget($eventName);
    }

    $this->assertDatabaseCount('appointments', 0);
});
