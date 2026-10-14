<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\StaffAssignment;
use App\Models\StaffLifecycleEvent;
use App\Models\User;
use App\Repositories\AdminPortalRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function revocationBranch(): Branch
{
    return Branch::query()->create([
        'code' => 'P1-REVOCATION',
        'name' => 'Mizuki P1',
        'phone' => '02923999999',
        'email' => 'p1-revocation@mizuki.test',
        'address' => 'Cần Thơ',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

test('an old staff cookie stays revoked after trash and restore while a fresh login works', function (): void {
    $branch = revocationBranch();
    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'email' => 'p1-cookie-staff@mizuki.test',
        'password' => 'secret-password',
    ])->refresh();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin])->refresh();
    $assignment = StaffAssignment::query()->create([
        'staff_id' => $staff->id,
        'branch_id' => $branch->id,
        'role' => $staff->role,
        'job_title' => $staff->job_title,
        'work_area' => 'clinic',
        'effective_from' => now()->subDay(),
        'reason' => 'Phân công ban đầu',
    ]);
    $originalGeneration = $staff->auth_generation;
    $repository = app(AdminPortalRepository::class);

    // Origin makes both requests use Sanctum's stateful cookie path.
    $this->withHeader('Origin', 'http://localhost:5173');
    $login = $this->postJson('/api/v1/auth/staff-login', [
        'email' => $staff->email,
        'password' => 'secret-password',
    ])->assertOk();
    $sessionCookie = collect($login->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($sessionCookie)->not->toBeNull();
    $this->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue());
    expect(Auth::guard('web')->getSession()->get('staff_auth_generation'))
        ->toBe($originalGeneration);
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $staff->id);

    // Mutate as a separate administrator without replacing the staff cookie.
    $repository->trashStaff($admin, $staff->id);
    expect(User::withTrashed()->findOrFail($staff->id)->auth_generation)
        ->toBe($originalGeneration + 1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/staff-login', [
        'email' => $staff->email,
        'password' => 'secret-password',
    ])->assertUnauthorized();

    $repository->restoreStaff($admin, $staff->id);
    expect($staff->refresh()->auth_generation)->toBe($originalGeneration + 1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect(Auth::guard('web')->getSession()->get('staff_auth_generation'))
        ->toBe($originalGeneration);

    $freshLogin = $this->postJson('/api/v1/auth/staff-login', [
        'email' => $staff->email,
        'password' => 'secret-password',
    ])->assertOk();
    $freshCookie = collect($freshLogin->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($freshCookie)->not->toBeNull();
    $this->withUnencryptedCookie(config('session.cookie'), $freshCookie->getValue());
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $staff->id);

    expect(StaffAssignment::query()->find($assignment->id))->not->toBeNull()
        ->and($staff->staffAssignments()->count())->toBe(2)
        ->and($staff->staffAssignments()->whereNull('effective_to')->count())->toBe(1)
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::TRASHED)->exists())->toBeTrue()
        ->and($staff->staffLifecycleEvents()->where('event_type', StaffLifecycleEvent::RESTORED)->exists())->toBeTrue();
});

test('an old Sanctum bearer token stays revoked after trash and restore', function (): void {
    $branch = revocationBranch();
    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
    ])->refresh();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin])->refresh();
    $repository = app(AdminPortalRepository::class);
    $oldToken = $staff->createToken('before-trash')->plainTextToken;

    expect(Auth::guard('web')->guest())->toBeTrue();
    $this->withToken($oldToken)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);
    expect(Auth::guard('web')->guest())->toBeTrue();

    $repository->trashStaff($admin, $staff->id);
    expect($staff->tokens()->count())->toBe(0);
    Auth::forgetGuards();
    expect(Auth::guard('web')->guest())->toBeTrue();
    $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $repository->restoreStaff($admin, $staff->id);
    Auth::forgetGuards();
    expect(Auth::guard('web')->guest())->toBeTrue();
    $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $newToken = $staff->createToken('after-restore')->plainTextToken;
    Auth::forgetGuards();
    expect(Auth::guard('web')->guest())->toBeTrue();
    $this->withToken($newToken)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);
});

test('missing staff session generation fails closed without affecting customer login or profile', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician]);
    $this->actingAs($staff)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->getJson('/api/v1/customer/profile')->assertUnauthorized();

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'p1-customer@mizuki.test',
        'password' => 'secret-password',
    ]);
    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/login', [
        'email' => $customer->email,
        'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('data.id', $customer->id);
    $this->getJson('/api/v1/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id);
});

test('trash still protects the final super admin and final active branch manager', function (): void {
    $repository = app(AdminPortalRepository::class);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin])->refresh();
    $branch = revocationBranch();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ])->refresh();

    expect(fn () => $repository->trashStaff($admin, $admin->id))
        ->toThrow(ValidationException::class);
    expect(fn () => $repository->trashStaff($admin, $manager->id))
        ->toThrow(ValidationException::class);
    expect($admin->refresh()->trashed())->toBeFalse()
        ->and($manager->refresh()->trashed())->toBeFalse();
});
