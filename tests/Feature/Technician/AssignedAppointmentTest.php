<?php

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $token = Str::upper(Str::random(6));
    $makeBranch = fn (string $suffix): Branch => Branch::query()->create([
        'code' => "TECH-{$token}-{$suffix}",
        'name' => "Technician Clinic {$suffix}",
        'branch_type' => BranchType::Hybrid,
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $this->branch = $makeBranch('A');
    $this->otherBranch = $makeBranch('B');
    $this->service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Technician Service',
        'slug' => 'technician-service-'.strtolower($token),
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
    ]);
    $this->technician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->branch->id,
    ]);
    $this->otherTechnician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->branch->id,
    ]);
});

function makeAssignedAppointment(
    Branch $branch,
    Service $service,
    ?User $technician,
    AppointmentStatus $status = AppointmentStatus::Confirmed,
    array $overrides = [],
): Appointment {
    return Appointment::query()->create(array_merge([
        'appointment_number' => 'APT-TECH-'.Str::upper(Str::random(8)),
        'user_id' => null,
        'customer_name' => 'Khách kỹ thuật viên',
        'customer_phone' => '0901234567',
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'technician_id' => $technician?->id,
        'status' => $status,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => $service->duration_minutes,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 10:00:00',
    ], $overrides));
}

test('guest and non-technician roles are denied technician endpoints', function (): void {
    $this->getJson('/api/v1/technician/appointments')->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::BranchManager, UserRole::SuperAdmin] as $role) {
        $user = User::factory()->create(['role' => $role, 'branch_id' => $this->branch->id]);
        $this->actingAs($user)
            ->getJson('/api/v1/technician/appointments')
            ->assertForbidden();
    }
});

test('technician lists only own assigned appointments', function (): void {
    $own = makeAssignedAppointment($this->branch, $this->service, $this->technician);
    makeAssignedAppointment($this->branch, $this->service, $this->otherTechnician);
    makeAssignedAppointment($this->branch, $this->service, null);

    $this->actingAs($this->technician)
        ->getJson('/api/v1/technician/appointments')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $own->id);
});

test('technician schedule is chronological and validates filters', function (): void {
    $later = makeAssignedAppointment(
        $this->branch,
        $this->service,
        $this->technician,
        AppointmentStatus::Confirmed,
        [
            'starts_at' => '2026-08-03 11:00:00',
            'ends_at' => '2026-08-03 12:00:00',
        ],
    );
    $earlier = makeAssignedAppointment(
        $this->branch,
        $this->service,
        $this->technician,
        AppointmentStatus::Confirmed,
        [
            'starts_at' => '2026-08-03 09:00:00',
            'ends_at' => '2026-08-03 10:00:00',
        ],
    );
    $this->actingAs($this->technician);

    $this->getJson('/api/v1/technician/appointments?appointment_date=2026-08-03')
        ->assertOk()
        ->assertJsonPath('data.0.id', $earlier->id)
        ->assertJsonPath('data.1.id', $later->id);

    $this->getJson('/api/v1/technician/appointments?status=unknown')->assertUnprocessable();
    $this->getJson('/api/v1/technician/appointments?appointment_date=03-08-2026')->assertUnprocessable();
    $this->getJson('/api/v1/technician/appointments?per_page=101')->assertUnprocessable();
});

test('technician views only own assigned appointment', function (): void {
    $own = makeAssignedAppointment($this->branch, $this->service, $this->technician);
    $other = makeAssignedAppointment($this->branch, $this->service, $this->otherTechnician);
    $this->actingAs($this->technician);

    $this->getJson("/api/v1/technician/appointments/{$own->id}")
        ->assertOk()->assertJsonPath('data.id', $own->id);
    $this->getJson("/api/v1/technician/appointments/{$other->id}")
        ->assertNotFound();
});

test('technician starts own confirmed appointment and persists optional staff note', function (): void {
    $appointment = makeAssignedAppointment($this->branch, $this->service, $this->technician);

    $this->actingAs($this->technician)
        ->postJson("/api/v1/technician/appointments/{$appointment->id}/start", [
            'staff_note' => 'Bắt đầu liệu trình',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.allowed_actions', ['complete'])
        ->assertJsonPath('data.staff_note', 'Bắt đầu liệu trình');
});

test('technician cannot start pending or unassigned appointment', function (string $case): void {
    $appointment = makeAssignedAppointment(
        $this->branch,
        $this->service,
        $case === 'unassigned' ? null : $this->technician,
        $case === 'pending' ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
    );
    $this->actingAs($this->technician);

    $response = $this->postJson("/api/v1/technician/appointments/{$appointment->id}/start");

    $case === 'unassigned'
        ? $response->assertNotFound()
        : $response->assertUnprocessable();
})->with(['pending' => ['pending'], 'unassigned' => ['unassigned']]);

test('technician completes own in-progress appointment with staff note', function (): void {
    $appointment = makeAssignedAppointment(
        $this->branch,
        $this->service,
        $this->technician,
        AppointmentStatus::InProgress,
    );

    $this->actingAs($this->technician)
        ->postJson("/api/v1/technician/appointments/{$appointment->id}/complete", [
            'staff_note' => 'Da đáp ứng tốt',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.allowed_actions', [])
        ->assertJsonPath('data.staff_note', 'Da đáp ứng tốt');

    expect($appointment->refresh()->completed_at)->not->toBeNull();
});

test('technician cannot complete confirmed or another technicians appointment', function (): void {
    $confirmed = makeAssignedAppointment($this->branch, $this->service, $this->technician);
    $other = makeAssignedAppointment(
        $this->branch,
        $this->service,
        $this->otherTechnician,
        AppointmentStatus::InProgress,
    );
    $this->actingAs($this->technician);

    $this->postJson("/api/v1/technician/appointments/{$confirmed->id}/complete")
        ->assertUnprocessable();
    $this->postJson("/api/v1/technician/appointments/{$other->id}/complete")
        ->assertNotFound();
});

test('technician branch mismatch is rejected even when technician id is assigned', function (): void {
    $appointment = makeAssignedAppointment($this->otherBranch, $this->service, $this->technician);

    $this->actingAs($this->technician)
        ->getJson("/api/v1/technician/appointments/{$appointment->id}")
        ->assertNotFound();
    $this->postJson("/api/v1/technician/appointments/{$appointment->id}/start")
        ->assertNotFound();
});
