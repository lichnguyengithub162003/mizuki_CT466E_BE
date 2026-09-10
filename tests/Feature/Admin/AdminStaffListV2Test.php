<?php

use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createStaffListBranch(string $prefix): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix.$token,
        'name' => "Mizuki Staff {$token}",
        'phone' => '02923999999',
        'email' => strtolower($token).'@mizuki.test',
        'address' => 'Cần Thơ',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

/** @return array<string, mixed> */
function staffListRow(array $rows, int $id): array
{
    return collect($rows)->firstWhere('id', $id) ?? [];
}

test('staff contract migration safely backfills existing staff only', function (): void {
    $migration = require database_path('migrations/2026_09_10_000002_add_staff_list_contract_to_users_table.php');
    $migration->down();

    $staffId = DB::table('users')->insertGetId([
        'name' => 'Legacy Staff',
        'email' => 'legacy.staff@mizuki.test',
        'password' => 'legacy-password',
        'role' => UserRole::Technician->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customerId = DB::table('users')->insertGetId([
        'name' => 'Legacy Customer',
        'email' => 'legacy.customer@mizuki.test',
        'password' => 'legacy-password',
        'role' => UserRole::Customer->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('users')->find($staffId))
        ->staff_code->toBe('NV-'.str_pad((string) $staffId, 5, '0', STR_PAD_LEFT))
        ->job_title->toBeNull()
        ->employment_status->toBe(StaffEmploymentStatus::Working->value)
        ->and(DB::table('users')->find($customerId))
        ->staff_code->toBeNull()
        ->job_title->toBeNull()
        ->employment_status->toBeNull();
});

test('super admin sees cross-branch staff and super admins with the complete list contract', function (): void {
    $firstBranch = createStaffListBranch('SLA');
    $secondBranch = createStaffListBranch('SLB');
    $technician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $firstBranch->id,
        'name' => 'Staff Contract Technician',
        'phone' => '0901000001',
        'job_title' => 'Bác sĩ da liễu',
        'employment_status' => StaffEmploymentStatus::Working,
    ])->refresh();
    $cashier = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $secondBranch->id,
        'employment_status' => StaffEmploymentStatus::Left,
    ])->refresh();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin])->refresh();

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/staff?per_page=100')->assertOk();
    $rows = $response->json('data');
    $technicianRow = staffListRow($rows, $technician->id);
    $cashierRow = staffListRow($rows, $cashier->id);
    $adminRow = staffListRow($rows, $admin->id);

    expect($technicianRow)
        ->toMatchArray([
            'id' => $technician->id,
            'code' => 'NV-'.str_pad((string) $technician->id, 5, '0', STR_PAD_LEFT),
            'name' => 'Staff Contract Technician',
            'email' => $technician->email,
            'phone' => '0901000001',
            'role' => UserRole::Technician->value,
            'role_label' => 'Kỹ thuật viên',
            'job_title' => 'Bác sĩ da liễu',
            'status' => StaffEmploymentStatus::Working->value,
            'status_label' => 'Đang làm việc',
        ])
        ->and($technicianRow['branch'])->toMatchArray([
            'id' => $firstBranch->id,
            'code' => $firstBranch->code,
            'name' => $firstBranch->name,
        ])
        ->and($cashierRow['status'])->toBe(StaffEmploymentStatus::Left->value)
        ->and($cashierRow['status_label'])->toBe('Đã nghỉ việc')
        ->and($cashierRow['job_title'])->toBeNull()
        ->and($cashierRow['branch']['id'])->toBe($secondBranch->id)
        ->and($adminRow['role'])->toBe(UserRole::SuperAdmin->value)
        ->and($adminRow['role_label'])->toBe('Quản trị viên hệ thống')
        ->and($adminRow['branch'])->toBeNull()
        ->and($admin->staff_code)->not->toBeNull()
        ->and($admin->employment_status)->toBe(StaffEmploymentStatus::Working);
});

test('branch manager scope cannot be escaped and excludes super admins', function (): void {
    $ownBranch = createStaffListBranch('SLO');
    $otherBranch = createStaffListBranch('SLX');
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $ownTechnician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $ownBranch->id,
        'job_title' => 'Trưởng nhóm kỹ thuật',
    ]);
    $ownCashier = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $ownBranch->id,
    ]);
    $ownManager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $otherStaff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $otherBranch->id,
    ]);
    $superAdmin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'branch_id' => $ownBranch->id,
    ]);

    $response = $this->actingAs($manager)
        ->getJson("/api/v1/admin/staff?branch_id={$otherBranch->id}&per_page=100")
        ->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($ownTechnician->id)
        ->toContain($ownCashier->id)
        ->toContain($ownManager->id)
        ->not->toContain($otherStaff->id)
        ->not->toContain($superAdmin->id);

    $this->getJson("/api/v1/admin/staff/{$ownManager->id}")
        ->assertOk()
        ->assertJsonPath('data.role', UserRole::BranchManager->value);

    $this->patchJson("/api/v1/admin/staff/{$ownManager->id}", ['job_title' => 'Không được cập nhật'])
        ->assertUnprocessable();
    expect($ownManager->refresh()->job_title)->toBeNull();
});

test('branch role and employment filters compose correctly for super admin', function (): void {
    $firstBranch = createStaffListBranch('SLF');
    $secondBranch = createStaffListBranch('SLG');
    $matching = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $firstBranch->id,
        'employment_status' => StaffEmploymentStatus::Left,
    ]);
    $working = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $firstBranch->id,
        'employment_status' => StaffEmploymentStatus::Working,
    ]);
    $wrongRole = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $firstBranch->id,
        'job_title' => 'Thu ngân',
        'employment_status' => StaffEmploymentStatus::Left,
    ]);
    $wrongBranch = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $secondBranch->id,
        'employment_status' => StaffEmploymentStatus::Left,
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/staff?'.http_build_query([
        'branch_id' => $firstBranch->id,
        'role' => UserRole::Cashier->value,
        'status' => StaffEmploymentStatus::Left->value,
        'per_page' => 100,
    ]))->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($matching->id)
        ->not->toContain($working->id)
        ->not->toContain($wrongRole->id)
        ->not->toContain($wrongBranch->id);

    foreach ([StaffEmploymentStatus::Working, StaffEmploymentStatus::Left] as $status) {
        $statusResponse = $this->getJson("/api/v1/admin/staff?status={$status->value}&per_page=100")->assertOk();
        expect(collect($statusResponse->json('data'))->every(fn (array $row): bool => $row['status'] === $status->value))->toBeTrue();
    }
});

test('invalid staff role and employment status filters are rejected', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->getJson('/api/v1/admin/staff?role=customer')
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['role']]]);
    $this->getJson('/api/v1/admin/staff?role=doctor')
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['role']]]);
    $this->getJson('/api/v1/admin/staff?status=inactive')
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['status']]]);
});

test('staff search supports code name email phone and job title', function (): void {
    $branch = createStaffListBranch('SLS');
    $target = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'name' => 'Nguyễn Minh Searchable',
        'email' => 'staff.searchable@mizuki.test',
        'phone' => '0912345678',
        'job_title' => 'Điều dưỡng da liễu',
    ])->refresh();
    User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $branch->id,
        'name' => 'Unrelated Staff',
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    foreach ([$target->staff_code, 'Minh Searchable', 'staff.searchable@', '0912345678', 'Điều dưỡng da'] as $search) {
        $response = $this->getJson('/api/v1/admin/staff?search='.urlencode($search))->assertOk();
        expect(collect($response->json('data'))->pluck('id'))->toContain($target->id);
    }
});

test('staff employment status and job title can be persisted through the existing mutation contract', function (): void {
    $branch = createStaffListBranch('SLM');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/staff', [
        'name' => 'New Staff Member',
        'email' => 'new.staff@mizuki.test',
        'phone' => '0901999999',
        'password' => 'password123',
        'role' => UserRole::Cashier->value,
        'branch_id' => $branch->id,
        'job_title' => '  Nhân viên bán hàng  ',
        'status' => StaffEmploymentStatus::Left->value,
    ])->assertCreated();

    $id = $response->json('data.id');
    $response->assertJsonPath('data.code', 'NV-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT))
        ->assertJsonPath('data.job_title', 'Nhân viên bán hàng')
        ->assertJsonPath('data.status', StaffEmploymentStatus::Left->value)
        ->assertJsonPath('data.status_label', 'Đã nghỉ việc');

    expect(User::query()->findOrFail($id))
        ->employment_status->toBe(StaffEmploymentStatus::Left)
        ->job_title->toBe('Nhân viên bán hàng');

    $this->patchJson("/api/v1/admin/staff/{$id}", ['job_title' => null])
        ->assertOk()
        ->assertJsonPath('data.job_title', null);

    expect(User::query()->findOrFail($id)->job_title)->toBeNull();
});

test('staff mutation rejects invalid and oversized job titles', function (): void {
    $branch = createStaffListBranch('SLV');
    $staff = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $branch->id,
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['job_title' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['job_title']]]);
    $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['job_title' => ['Bác sĩ']])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['job_title']]]);
});

test('staff list retains current no-soft-delete behavior and admin authorization', function (): void {
    expect(Schema::hasColumn('users', 'deleted_at'))->toBeFalse();

    $defaultCustomer = User::factory()->create()->refresh();
    expect($defaultCustomer->role)->toBe(UserRole::Customer)
        ->and($defaultCustomer->staff_code)->toBeNull()
        ->and($defaultCustomer->job_title)->toBeNull()
        ->and($defaultCustomer->employment_status)->toBeNull();

    $this->getJson('/api/v1/admin/staff')->assertUnauthorized();

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer)->getJson('/api/v1/admin/staff')->assertForbidden();

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $row = $this->actingAs($admin)->getJson('/api/v1/admin/staff')->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('deleted_at');
});
