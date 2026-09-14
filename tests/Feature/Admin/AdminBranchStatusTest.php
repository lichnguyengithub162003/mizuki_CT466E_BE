<?php

use App\Enums\BranchStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function branchStatusFixture(BranchStatus $status = BranchStatus::Active): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => 'BS-'.$token,
        'name' => 'Mizuki Branch '.$token,
        'phone' => '02923888888',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'status' => $status,
    ]);
}

test('status migration maps legacy booleans without inventing suspended state', function (): void {
    $migration = require database_path('migrations/2026_09_10_000001_add_status_to_branches_table.php');
    $migration->down();

    $base = [
        'phone' => '02923888888',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('branches')->insert([
        $base + ['code' => 'LEGACY-ACTIVE', 'name' => 'Legacy Active', 'is_active' => true],
        $base + ['code' => 'LEGACY-INACTIVE', 'name' => 'Legacy Inactive', 'is_active' => false],
    ]);

    $migration->up();

    expect(DB::table('branches')->where('code', 'LEGACY-ACTIVE')->value('status'))
        ->toBe(BranchStatus::Active->value)
        ->and(DB::table('branches')->where('code', 'LEGACY-INACTIVE')->value('status'))
        ->toBe(BranchStatus::Inactive->value)
        ->and(DB::table('branches')->where('status', BranchStatus::Suspended->value)->count())
        ->toBe(0);
});

test('admin branch list and detail expose canonical status consistently', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    foreach (BranchStatus::cases() as $status) {
        $branch = branchStatusFixture($status);

        $this->getJson("/api/v1/admin/branches?status={$status->value}")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $branch->id)
            ->assertJsonPath('data.0.status', $status->value)
            ->assertJsonPath('data.0.status_label', $status->label())
            ->assertJsonPath('data.0.is_active', $status->isActive());

        $this->getJson("/api/v1/admin/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.status', $status->value)
            ->assertJsonPath('data.status_label', $status->label())
            ->assertJsonPath('data.is_active', $status->isActive());
    }
});

test('admin branch status transitions synchronize the legacy active flag', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    foreach ([
        [BranchStatus::Active, BranchStatus::Suspended],
        [BranchStatus::Suspended, BranchStatus::Active],
        [BranchStatus::Active, BranchStatus::Inactive],
        [BranchStatus::Inactive, BranchStatus::Active],
    ] as [$from, $to]) {
        $branch = branchStatusFixture($from);

        $this->patchJson("/api/v1/admin/branches/{$branch->id}", ['status' => $to->value])
            ->assertOk()
            ->assertJsonPath('data.status', $to->value)
            ->assertJsonPath('data.status_label', $to->label())
            ->assertJsonPath('data.is_active', $to->isActive());

        $branch->refresh();
        expect($branch->status)->toBe($to)
            ->and($branch->is_active)->toBe($to->isActive());
    }
});

test('invalid branch statuses use standard validation errors', function (): void {
    $branch = branchStatusFixture();
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/branches?status=paused')->assertUnprocessable();
    $this->patchJson("/api/v1/admin/branches/{$branch->id}", ['status' => 'paused'])
        ->assertUnprocessable();
});

test('legacy active filters remain compatible and explicit status takes precedence', function (): void {
    $active = branchStatusFixture(BranchStatus::Active);
    $suspended = branchStatusFixture(BranchStatus::Suspended);
    $inactive = branchStatusFixture(BranchStatus::Inactive);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/branches?is_active=1')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $active->id);
    $this->getJson('/api/v1/admin/branches?is_active=0')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonFragment(['id' => $suspended->id])
        ->assertJsonFragment(['id' => $inactive->id]);
    $this->getJson('/api/v1/admin/branches?status=active&is_active=0')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $active->id);
});

test('legacy active updates map deterministically to canonical status', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    foreach ([
        [true, BranchStatus::Active],
        [false, BranchStatus::Inactive],
    ] as [$isActive, $expectedStatus]) {
        $branch = branchStatusFixture($isActive ? BranchStatus::Inactive : BranchStatus::Active);

        $this->patchJson("/api/v1/admin/branches/{$branch->id}", ['is_active' => $isActive])
            ->assertOk()
            ->assertJsonPath('data.status', $expectedStatus->value)
            ->assertJsonPath('data.is_active', $isActive);

        expect($branch->refresh()->status)->toBe($expectedStatus)
            ->and($branch->is_active)->toBe($isActive);
    }
});

test('branch manager status filtering remains scoped to their own branch', function (): void {
    $own = branchStatusFixture(BranchStatus::Suspended);
    $other = branchStatusFixture(BranchStatus::Suspended);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $own->id,
    ]);
    $this->actingAs($manager);

    $this->getJson('/api/v1/admin/branches?status=suspended')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonMissing(['id' => $other->id]);
    $this->getJson("/api/v1/admin/branches/{$other->id}")->assertNotFound();
    $this->patchJson("/api/v1/admin/branches/{$other->id}", ['status' => 'active'])
        ->assertNotFound();
});

test('explicit status wins over a contradictory legacy flag on update', function (): void {
    $branch = branchStatusFixture(BranchStatus::Active);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->patchJson("/api/v1/admin/branches/{$branch->id}", [
        'status' => BranchStatus::Suspended->value,
        'is_active' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', BranchStatus::Suspended->value)
        ->assertJsonPath('data.is_active', false);

    expect($branch->refresh()->status)->toBe(BranchStatus::Suspended)
        ->and($branch->is_active)->toBeFalse();
});
