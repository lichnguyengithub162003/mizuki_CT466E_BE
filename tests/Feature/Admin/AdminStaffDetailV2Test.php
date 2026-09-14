<?php

use App\Enums\AppointmentStatus;
use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\PosSession;
use App\Models\Service;
use App\Models\StaffAssignment;
use App\Models\StaffLifecycleEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function createStaffDetailBranch(string $prefix): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix.$token,
        'name' => "Mizuki Staff Detail {$token}",
        'phone' => '02923999999',
        'email' => strtolower($token).'@staff-detail.test',
        'address' => 'Cần Thơ',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

function createStaffDetailMember(
    UserRole $role,
    ?Branch $branch,
    array $attributes = [],
    bool $withAssignment = true,
): User {
    $staff = User::factory()->create(array_merge([
        'role' => $role,
        'branch_id' => $branch?->id,
        'employment_status' => StaffEmploymentStatus::Working,
    ], $attributes))->refresh();

    if ($withAssignment && $staff->employment_status === StaffEmploymentStatus::Working) {
        StaffAssignment::query()->create([
            'staff_id' => $staff->id,
            'branch_id' => $staff->branch_id,
            'role' => $staff->role,
            'job_title' => $staff->job_title,
            'work_area' => match ($staff->role) {
                UserRole::Technician => 'clinic',
                UserRole::Cashier, UserRole::SalesStaff => 'retail',
                UserRole::BranchManager => 'management',
                UserRole::SuperAdmin => 'system',
                default => null,
            },
            'effective_from' => '2026-01-01 08:00:00',
            'reason' => 'Phân công ban đầu',
        ]);
    }

    return $staff;
}

function createStaffDetailPosSession(
    User $cashier,
    string $status = 'open',
    string $expiresAt = '2026-09-11 10:00:00',
): PosSession {
    return PosSession::query()->create([
        'code' => 'SD-POS-'.Str::upper(Str::random(12)),
        'cashier_id' => $cashier->id,
        'branch_id' => $cashier->branch_id,
        'status' => $status,
        'expires_at' => $expiresAt,
        'completed_at' => $status === 'completed' ? '2026-09-11 08:00:00' : null,
    ]);
}

function createStaffDetailAppointment(User $technician, AppointmentStatus $status): Appointment
{
    $branch = $technician->branch;
    $service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Staff Detail Service '.Str::random(6),
        'slug' => 'staff-detail-service-'.Str::lower(Str::random(10)),
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    return Appointment::query()->create([
        'appointment_number' => 'SD-'.Str::upper(Str::random(12)),
        'user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_phone' => '0900000000',
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'status' => $status,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => 60,
        'starts_at' => '2026-09-12 09:00:00',
        'ends_at' => '2026-09-12 10:00:00',
        'completed_at' => $status === AppointmentStatus::Completed ? '2026-09-10 10:00:00' : null,
    ]);
}

test('staff detail returns current assignment employment timeline permissions and history', function (): void {
    $branch = createStaffDetailBranch('SDD');
    $staff = createStaffDetailMember(UserRole::Technician, $branch, [
        'job_title' => 'Bác sĩ da liễu',
    ]);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $this->actingAs($admin)->getJson("/api/v1/admin/staff/{$staff->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id)
        ->assertJsonPath('data.code', $staff->staff_code)
        ->assertJsonPath('data.job_title', 'Bác sĩ da liễu')
        ->assertJsonPath('data.status', StaffEmploymentStatus::Working->value)
        ->assertJsonPath('data.status_label', 'Đang làm việc')
        ->assertJsonStructure(['data' => ['avatar', 'avatar_rendition_url']])
        ->assertJsonPath('data.current_assignment.branch.id', $branch->id)
        ->assertJsonPath('data.current_assignment.role', UserRole::Technician->value)
        ->assertJsonPath('data.current_assignment.job_title', 'Bác sĩ da liễu')
        ->assertJsonPath('data.current_assignment.work_area', 'clinic')
        ->assertJsonPath('data.employment_started_at', CarbonImmutable::parse('2026-01-01 08:00:00')->toISOString())
        ->assertJsonPath('data.employment_ended_at', null)
        ->assertJsonPath('data.permissions.change_assignment', true)
        ->assertJsonPath('data.permissions.trash', true)
        ->assertJsonCount(1, 'data.history.assignments');
});

test('staff detail preserves super admin visibility and branch manager scope', function (): void {
    $ownBranch = createStaffDetailBranch('SDO');
    $otherBranch = createStaffDetailBranch('SDX');
    $manager = createStaffDetailMember(UserRole::BranchManager, $ownBranch);
    $ownStaff = createStaffDetailMember(UserRole::Cashier, $ownBranch);
    $otherStaff = createStaffDetailMember(UserRole::Technician, $otherBranch);
    $superAdmin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $this->actingAs($manager)->getJson("/api/v1/admin/staff/{$ownStaff->id}")->assertOk();
    $this->getJson("/api/v1/admin/staff/{$otherStaff->id}")->assertNotFound();
    $this->getJson("/api/v1/admin/staff/{$superAdmin->id}")->assertNotFound();

    $this->actingAs($superAdmin)->getJson("/api/v1/admin/staff/{$otherStaff->id}")->assertOk();
});

test('assignment change closes current assignment synchronizes user and records immutable history', function (): void {
    $oldBranch = createStaffDetailBranch('SDA');
    $newBranch = createStaffDetailBranch('SDB');
    $staff = createStaffDetailMember(UserRole::Cashier, $oldBranch, ['job_title' => 'Thu ngân']);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $historical = StaffAssignment::query()->create([
        'staff_id' => $staff->id,
        'branch_id' => $oldBranch->id,
        'role' => UserRole::Technician,
        'job_title' => 'Kỹ thuật viên cũ',
        'work_area' => 'clinic',
        'effective_from' => '2025-01-01 08:00:00',
        'effective_to' => '2025-12-31 17:00:00',
        'reason' => 'Lịch sử cũ',
    ]);
    $historicalSnapshot = collect($historical->refresh()->getAttributes())->sortKeys()->all();
    $current = $staff->staffAssignments()->whereNull('effective_to')->firstOrFail();

    $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'branch_id' => $newBranch->id,
        'role' => UserRole::Technician->value,
        'job_title' => 'Bác sĩ da liễu',
        'work_area' => 'clinic',
        'reason' => 'Điều chuyển phục vụ chuyên môn',
    ])->assertOk()
        ->assertJsonPath('data.branch.id', $newBranch->id)
        ->assertJsonPath('data.role', UserRole::Technician->value)
        ->assertJsonPath('data.current_assignment.work_area', 'clinic');

    $staff->refresh();
    expect($staff->branch_id)->toBe($newBranch->id)
        ->and($staff->role)->toBe(UserRole::Technician)
        ->and($staff->job_title)->toBe('Bác sĩ da liễu')
        ->and($current->refresh()->effective_to)->not->toBeNull()
        ->and($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(1)
        ->and(collect($historical->refresh()->getAttributes())->sortKeys()->all())->toBe($historicalSnapshot)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::ASSIGNMENT_CHANGED)->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::BRANCH_TRANSFERRED)->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::ROLE_CHANGED)->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::JOB_TITLE_CHANGED)->count())->toBe(1);
});

test('same-branch role and job title change creates a new assignment period', function (): void {
    $branch = createStaffDetailBranch('SDR');
    $staff = createStaffDetailMember(UserRole::Cashier, $branch, ['job_title' => 'Nhân viên bán hàng']);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'role' => UserRole::Technician->value,
        'job_title' => 'Kỹ thuật viên chăm sóc da',
        'work_area' => 'clinic',
    ])->assertOk();

    expect($staff->refresh()->role)->toBe(UserRole::Technician)
        ->and($staff->staffAssignments()->count())->toBe(2)
        ->and($staff->staffAssignments()->whereNull('effective_to')->first()->job_title)->toBe('Kỹ thuật viên chăm sóc da');

    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'role' => UserRole::Technician->value,
        'job_title' => 'Kỹ thuật viên chăm sóc da',
        'work_area' => 'clinic',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['assignment']]]);
});

test('sales staff role transitions keep role work area and assignment synchronized', function (): void {
    $firstBranch = createStaffDetailBranch('SDS');
    createStaffDetailMember(UserRole::BranchManager, $firstBranch);
    $secondBranch = createStaffDetailBranch('SDW');
    $technician = createStaffDetailMember(UserRole::Technician, $firstBranch);
    $manager = createStaffDetailMember(UserRole::BranchManager, $firstBranch);
    $salesStaff = createStaffDetailMember(UserRole::SalesStaff, $firstBranch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/staff/{$technician->id}/assignment", [
        'role' => UserRole::SalesStaff->value,
        'work_area' => 'retail',
    ])->assertOk()
        ->assertJsonPath('data.role', UserRole::SalesStaff->value)
        ->assertJsonPath('data.role_label', 'Nhân viên bán hàng')
        ->assertJsonPath('data.current_assignment.work_area', 'retail');

    $this->postJson("/api/v1/admin/staff/{$technician->id}/assignment", [
        'role' => UserRole::Technician->value,
        'work_area' => 'clinic',
    ])->assertOk()
        ->assertJsonPath('data.current_assignment.role', UserRole::Technician->value)
        ->assertJsonPath('data.current_assignment.work_area', 'clinic');

    $this->postJson("/api/v1/admin/staff/{$manager->id}/assignment", [
        'role' => UserRole::SalesStaff->value,
        'work_area' => 'retail',
    ])->assertOk()
        ->assertJsonPath('data.role', UserRole::SalesStaff->value);

    $this->postJson("/api/v1/admin/staff/{$salesStaff->id}/assignment", [
        'branch_id' => $secondBranch->id,
        'role' => UserRole::Cashier->value,
        'work_area' => 'retail',
    ])->assertOk()
        ->assertJsonPath('data.branch.id', $secondBranch->id)
        ->assertJsonPath('data.current_assignment.role', UserRole::Cashier->value);

    foreach ([$technician, $manager, $salesStaff] as $staff) {
        $staff->refresh();
        $current = $staff->staffAssignments()->whereNull('effective_to')->sole();
        expect($current->branch_id)->toBe($staff->branch_id)
            ->and($current->role)->toBe($staff->role)
            ->and($current->job_title)->toBe($staff->job_title);
    }
});

test('branch manager can manage same branch sales staff without gaining sales staff admin access', function (): void {
    $branch = createStaffDetailBranch('SDG');
    $manager = createStaffDetailMember(UserRole::BranchManager, $branch);
    $salesStaff = createStaffDetailMember(UserRole::SalesStaff, $branch, [
        'email' => 'sales.staff@mizuki.test',
        'password' => 'secret-password',
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => 'sales.staff@mizuki.test',
        'password' => 'secret-password',
    ])->assertOk()
        ->assertJsonPath('data.role', UserRole::SalesStaff->value);
    $this->getJson('/api/v1/admin/staff')->assertForbidden();

    $this->actingAs($manager)->getJson("/api/v1/admin/staff/{$salesStaff->id}")
        ->assertOk()
        ->assertJsonPath('data.permissions.change_assignment', true);
    $this->postJson("/api/v1/admin/staff/{$salesStaff->id}/assignment", [
        'role' => UserRole::Technician->value,
        'work_area' => 'clinic',
    ])->assertOk();

    $this->actingAs($salesStaff->refresh())->getJson('/api/v1/admin/staff')->assertForbidden();
});

test('assignment rejects role and work area combinations that are operationally nonsensical', function (): void {
    $branch = createStaffDetailBranch('SDI');
    $staff = createStaffDetailMember(UserRole::Technician, $branch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->actingAs($admin);

    foreach ([
        [UserRole::SalesStaff, 'system'],
        [UserRole::Cashier, 'management'],
        [UserRole::Technician, 'retail'],
    ] as [$role, $workArea]) {
        $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
            'role' => $role->value,
            'work_area' => $workArea,
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['work_area']]]);
    }

    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'role' => UserRole::SuperAdmin->value,
        'branch_id' => $branch->id,
        'work_area' => 'retail',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['assignment']]]);

    expect($staff->refresh()->role)->toBe(UserRole::Technician)
        ->and($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(1);
});

test('existing staff create and update endpoints initialize and preserve assignment history', function (): void {
    $branch = createStaffDetailBranch('SDC');
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/staff', [
        'name' => 'Nhân viên mới',
        'email' => 'new.staff.detail@mizuki.test',
        'password' => 'password123',
        'role' => UserRole::Cashier->value,
        'branch_id' => $branch->id,
        'job_title' => 'Nhân viên bán hàng',
    ])->assertCreated()
        ->assertJsonPath('data.current_assignment.work_area', 'retail');
    $staffId = $response->json('data.id');

    expect(StaffAssignment::query()->where('staff_id', $staffId)->whereNull('effective_to')->count())->toBe(1)
        ->and(StaffLifecycleEvent::query()->where('staff_id', $staffId)->where('event_type', StaffLifecycleEvent::ACCOUNT_CREATED)->count())->toBe(1);

    $this->patchJson("/api/v1/admin/staff/{$staffId}", [
        'job_title' => 'Thu ngân trưởng',
    ])->assertOk()
        ->assertJsonPath('data.current_assignment.job_title', 'Thu ngân trưởng');

    expect(StaffAssignment::query()->where('staff_id', $staffId)->count())->toBe(2)
        ->and(StaffAssignment::query()->where('staff_id', $staffId)->whereNull('effective_to')->count())->toBe(1)
        ->and(StaffLifecycleEvent::query()->where('staff_id', $staffId)->where('event_type', StaffLifecycleEvent::JOB_TITLE_CHANGED)->count())->toBe(1);
});

test('branch manager cannot transfer staff outside own branch', function (): void {
    $ownBranch = createStaffDetailBranch('SDM');
    $otherBranch = createStaffDetailBranch('SDN');
    $manager = createStaffDetailMember(UserRole::BranchManager, $ownBranch);
    $staff = createStaffDetailMember(UserRole::Technician, $ownBranch);

    $this->actingAs($manager)->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'branch_id' => $otherBranch->id,
        'reason' => 'Không được phép',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['branch_id']]]);

    expect($staff->refresh()->branch_id)->toBe($ownBranch->id)
        ->and($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(1);
});

test('role change updates authorization scope immediately', function (): void {
    $branch = createStaffDetailBranch('SDU');
    createStaffDetailMember(UserRole::BranchManager, $branch);
    $manager = createStaffDetailMember(UserRole::BranchManager, $branch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$manager->id}/assignment", [
        'role' => UserRole::Cashier->value,
        'work_area' => 'retail',
        'reason' => 'Điều chỉnh nhiệm vụ',
    ])->assertOk()
        ->assertJsonPath('data.role', UserRole::Cashier->value);

    $this->actingAs($manager->refresh())->getJson('/api/v1/admin/staff')->assertForbidden();
    expect($manager->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::ROLE_CHANGED)->exists())->toBeTrue();
});

test('preflight reports active appointment blockers but ignores completed history and transfer rechecks', function (): void {
    $oldBranch = createStaffDetailBranch('SDP');
    $newBranch = createStaffDetailBranch('SDQ');
    $staff = createStaffDetailMember(UserRole::Technician, $oldBranch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    createStaffDetailAppointment($staff, AppointmentStatus::Completed);

    $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$staff->id}/assignment/preflight", [
        'branch_id' => $newBranch->id,
    ])->assertOk()
        ->assertJsonPath('data.can_transfer', true)
        ->assertJsonCount(0, 'data.blockers');

    createStaffDetailAppointment($staff, AppointmentStatus::Confirmed);

    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment/preflight", [
        'branch_id' => $newBranch->id,
    ])->assertOk()
        ->assertJsonPath('data.can_transfer', false)
        ->assertJsonPath('data.blockers.0.type', 'appointments')
        ->assertJsonPath('data.blockers.0.count', 1)
        ->assertJsonPath('data.blockers.0.action', 'reassign_appointments');

    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'branch_id' => $newBranch->id,
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['assignment']]]);

    expect($staff->refresh()->branch_id)->toBe($oldBranch->id);
});

test('preflight and assignment recheck current open pos sessions but ignore completed or expired sessions', function (): void {
    $oldBranch = createStaffDetailBranch('SDP');
    $newBranch = createStaffDetailBranch('SDQ');
    $cashier = createStaffDetailMember(UserRole::Cashier, $oldBranch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    createStaffDetailPosSession($cashier, 'completed');
    createStaffDetailPosSession($cashier, 'open', '2026-09-11 08:30:00');
    $activeSession = createStaffDetailPosSession($cashier);

    $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$cashier->id}/assignment/preflight", [
        'branch_id' => $newBranch->id,
    ])->assertOk()
        ->assertJsonPath('data.can_transfer', false)
        ->assertJsonPath('data.blockers.0.type', 'pos_sessions')
        ->assertJsonPath('data.blockers.0.count', 1)
        ->assertJsonPath('data.blockers.0.action', 'complete_pos_sessions');

    $this->postJson("/api/v1/admin/staff/{$cashier->id}/assignment", [
        'branch_id' => $newBranch->id,
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['assignment']]]);
    expect($cashier->refresh()->branch_id)->toBe($oldBranch->id);

    $activeSession->update(['status' => 'completed', 'completed_at' => now()]);
    $this->postJson("/api/v1/admin/staff/{$cashier->id}/assignment/preflight", [
        'branch_id' => $newBranch->id,
    ])->assertOk()
        ->assertJsonPath('data.can_transfer', true)
        ->assertJsonCount(0, 'data.blockers');
});

test('employment status transitions preserve assignment history and create lifecycle events', function (): void {
    $branch = createStaffDetailBranch('SDE');
    $staff = createStaffDetailMember(UserRole::Cashier, $branch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);

    $this->actingAs($admin)->patchJson("/api/v1/admin/staff/{$staff->id}/employment-status", [])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['status']]]);

    $this->patchJson("/api/v1/admin/staff/{$staff->id}/employment-status", [
        'status' => StaffEmploymentStatus::Left->value,
    ])->assertOk()
        ->assertJsonPath('data.status', StaffEmploymentStatus::Left->value)
        ->assertJsonPath('data.current_assignment', null);

    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'role' => UserRole::SalesStaff->value,
        'work_area' => 'retail',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['status']]]);
    expect($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(0);

    $this->patchJson("/api/v1/admin/staff/{$staff->id}/employment-status", [
        'status' => StaffEmploymentStatus::Working->value,
    ])->assertOk()
        ->assertJsonPath('data.status', StaffEmploymentStatus::Working->value)
        ->assertJsonPath('data.current_assignment.role', UserRole::Cashier->value);

    expect($staff->refresh()->staffAssignments()->count())->toBe(2)
        ->and($staff->staffAssignments()->whereNotNull('effective_to')->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::EMPLOYMENT_STATUS_CHANGED)->count())->toBe(2);
});

test('authorized trash and restore preserve historical related data and normal visibility', function (): void {
    $branch = createStaffDetailBranch('SDT');
    $staff = createStaffDetailMember(UserRole::Technician, $branch, [
        'email' => 'trashed.staff@mizuki.test',
        'password' => 'secret-password',
    ]);
    $customer = User::factory()->create([
        'email' => 'unaffected.customer@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $appointment = createStaffDetailAppointment($staff, AppointmentStatus::Completed);

    $this->actingAs($admin)->deleteJson("/api/v1/admin/staff/{$staff->id}")
        ->assertOk();

    expect($staff->refresh()->trashed())->toBeTrue()
        ->and(Appointment::query()->find($appointment->id))->not->toBeNull()
        ->and(StaffLifecycleEvent::query()->where('staff_id', $staff->id)->where('event_type', StaffLifecycleEvent::TRASHED)->exists())->toBeTrue();
    $this->getJson('/api/v1/admin/staff')->assertJsonMissing(['id' => $staff->id]);
    $this->getJson("/api/v1/admin/staff/{$staff->id}")->assertNotFound();
    $this->postJson("/api/v1/admin/staff/{$staff->id}/assignment", [
        'job_title' => 'Không được phân công',
    ])->assertNotFound();
    $this->postJson('/api/v1/auth/staff-login', [
        'email' => 'trashed.staff@mizuki.test',
        'password' => 'secret-password',
    ])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', [
        'email' => 'unaffected.customer@mizuki.test',
        'password' => 'secret-password',
    ])->assertOk()
        ->assertJsonPath('data.id', $customer->id);

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/staff/{$staff->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);
    expect($staff->refresh()->trashed())->toBeFalse()
        ->and($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::RESTORED)->exists())->toBeTrue();
});

test('system preserves a final usable super admin and permits transitions while another remains', function (): void {
    $branch = createStaffDetailBranch('SDX');
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->actingAs($admin)->deleteJson("/api/v1/admin/staff/{$admin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.staff.0', 'Không thể vô hiệu hóa Super Admin cuối cùng. Vui lòng tạo một Super Admin khác trước');
    $this->patchJson("/api/v1/admin/staff/{$admin->id}/employment-status", [
        'status' => StaffEmploymentStatus::Left->value,
    ])->assertUnprocessable();
    $this->postJson("/api/v1/admin/staff/{$admin->id}/assignment", [
        'branch_id' => $branch->id,
        'role' => UserRole::SalesStaff->value,
        'work_area' => 'retail',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.staff.0', 'Không thể vô hiệu hóa Super Admin cuối cùng. Vui lòng tạo một Super Admin khác trước');

    $demotedAdmin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->postJson("/api/v1/admin/staff/{$demotedAdmin->id}/assignment", [
        'branch_id' => $branch->id,
        'role' => UserRole::SalesStaff->value,
        'work_area' => 'retail',
    ])->assertOk();

    $deactivatedAdmin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->patchJson("/api/v1/admin/staff/{$deactivatedAdmin->id}/employment-status", [
        'status' => StaffEmploymentStatus::Left->value,
    ])->assertOk();

    $trashedAdmin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->deleteJson("/api/v1/admin/staff/{$trashedAdmin->id}")->assertOk();

    expect($admin->refresh()->trashed())->toBeFalse()
        ->and($demotedAdmin->refresh()->role)->toBe(UserRole::SalesStaff)
        ->and($deactivatedAdmin->refresh()->employment_status)->toBe(StaffEmploymentStatus::Left)
        ->and($trashedAdmin->refresh()->trashed())->toBeTrue();
});

test('active branch preserves its last usable manager across all staff mutation paths', function (string $operation, string $replacement): void {
    $branch = createStaffDetailBranch('BMG');
    $destination = createStaffDetailBranch('BMD');
    $manager = createStaffDetailMember(UserRole::BranchManager, $branch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    if ($replacement !== 'none') {
        $other = createStaffDetailMember(UserRole::BranchManager, $replacement === 'other_branch' ? $destination : $branch, [
            'employment_status' => $replacement === 'left' ? StaffEmploymentStatus::Left : StaffEmploymentStatus::Working,
        ]);
        if ($replacement === 'trashed') {
            $other->delete();
        }
    }
    $assignmentCount = $manager->staffAssignments()->count();
    $eventCount = $manager->staffLifecycleEvents()->count();
    $this->actingAs($admin);
    $url = "/api/v1/admin/staff/{$manager->id}";
    $response = match ($operation) {
        'left' => $this->patchJson($url.'/employment-status', ['status' => 'left']),
        'trash' => $this->deleteJson($url),
        'role' => $this->postJson($url.'/assignment', ['role' => 'cashier', 'work_area' => 'retail']),
        'transfer' => $this->postJson($url.'/assignment', ['branch_id' => $destination->id]),
        'generic_left' => $this->patchJson($url, ['status' => 'left']),
        'generic_role' => $this->patchJson($url, ['role' => 'cashier']),
        'generic_transfer' => $this->patchJson($url, ['branch_id' => $destination->id]),
    };

    if ($replacement === 'working') {
        $response->assertOk();
        $manager->refresh();
        match ($operation) {
            'left', 'generic_left' => expect($manager->employment_status)->toBe(StaffEmploymentStatus::Left),
            'trash' => expect($manager->trashed())->toBeTrue(),
            'role', 'generic_role' => expect($manager->role)->toBe(UserRole::Cashier),
            'transfer', 'generic_transfer' => expect($manager->branch_id)->toBe($destination->id),
        };
    } else {
        $response->assertUnprocessable()->assertJsonPath('data.errors.staff.0',
            'Chi nhánh phải có ít nhất một quản lý đang làm việc. Vui lòng bổ nhiệm người thay thế trước.');
        expect($manager->refresh()->role)->toBe(UserRole::BranchManager)
            ->and($manager->employment_status)->toBe(StaffEmploymentStatus::Working)
            ->and($manager->branch_id)->toBe($branch->id)
            ->and($manager->trashed())->toBeFalse()
            ->and($manager->staffAssignments()->count())->toBe($assignmentCount)
            ->and($manager->staffAssignments()->whereNull('effective_to')->count())->toBe(1)
            ->and($manager->staffLifecycleEvents()->count())->toBe($eventCount);
    }
})->with(['left', 'trash', 'role', 'transfer', 'generic_left', 'generic_role', 'generic_transfer'])
    ->with(['none', 'working', 'left', 'trashed', 'other_branch']);

test('last manager guard allows harmless updates and does not apply to inactive branches', function (): void {
    $branch = createStaffDetailBranch('BMI');
    $manager = createStaffDetailMember(UserRole::BranchManager, $branch);
    $admin = createStaffDetailMember(UserRole::SuperAdmin, null);
    $this->actingAs($admin)->patchJson("/api/v1/admin/staff/{$manager->id}", [
        'job_title' => 'Quản lý vận hành',
    ])->assertOk();
    $branch->update(['is_active' => false]);
    $this->patchJson("/api/v1/admin/staff/{$manager->id}/employment-status", [
        'status' => 'left',
    ])->assertOk();
});

test('assignment migration backfills one current assignment for staff and none for customers', function (): void {
    $lifecycleMigration = require database_path('migrations/2026_09_11_000003_create_staff_lifecycle_events_table.php');
    $assignmentMigration = require database_path('migrations/2026_09_11_000002_create_staff_assignments_table.php');
    $lifecycleMigration->down();
    $assignmentMigration->down();

    $branch = createStaffDetailBranch('SDF');
    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'job_title' => 'Điều dưỡng',
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $assignmentMigration->up();
    $lifecycleMigration->up();

    expect(DB::table('staff_assignments')->where('staff_id', $staff->id)->count())->toBe(1)
        ->and(DB::table('staff_assignments')->where('staff_id', $staff->id)->value('job_title'))->toBe('Điều dưỡng')
        ->and(DB::table('staff_assignments')->where('staff_id', $customer->id)->count())->toBe(0);
});
