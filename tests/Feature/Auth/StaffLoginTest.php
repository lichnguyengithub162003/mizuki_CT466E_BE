<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('each internal staff role can log in and is recognized by the shared session', function (UserRole $role): void {
    $email = $role->value.'@mizuki.test';
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'secret-password',
        'role' => $role,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => strtoupper($email),
        'password' => 'secret-password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đăng nhập thành công!')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', $role->value)
        ->assertJsonPath('meta', []);

    $this->assertAuthenticatedAs($user);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $email)
        ->assertJsonPath('data.role', $role->value);
})->with([
    'cashier' => UserRole::Cashier,
    'technician' => UserRole::Technician,
    'branch manager' => UserRole::BranchManager,
    'super admin' => UserRole::SuperAdmin,
]);

test('staff login and session expose the assigned branch identity', function (): void {
    $branch = Branch::query()->create([
        'code' => 'AUTH-NK',
        'name' => 'Mizuki Ninh Kiều',
        'branch_type' => 'store',
        'address' => 'Ninh Kiều, Cần Thơ',
        'phone' => '02923880001',
        'email' => 'auth-nk@mizuki.test',
        'province_code' => '92',
        'ghn_district_id' => 1,
        'ghn_ward_code' => '00001',
        'is_active' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'assigned-manager@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.branch.id', $branch->id)
        ->assertJsonPath('data.branch.name', $branch->name);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.branch.id', $branch->id)
        ->assertJsonPath('data.branch.name', $branch->name);
});

test('branchless super admin receives a null branch on login and session restore', function (): void {
    $user = User::factory()->create([
        'email' => 'system-admin@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::SuperAdmin,
        'branch_id' => null,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk()
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.branch', null);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.branch', null);
});

test('a deleted assigned branch is reported as missing without inventing an identity', function (): void {
    $branch = Branch::query()->create([
        'code' => 'AUTH-OLD',
        'name' => 'Mizuki Old Branch',
        'branch_type' => 'store',
        'address' => 'Cần Thơ',
        'phone' => '02923880002',
        'email' => 'auth-old@mizuki.test',
        'province_code' => '92',
        'ghn_district_id' => 1,
        'ghn_ward_code' => '00002',
        'is_active' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'orphaned-manager@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $branch->delete();

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.branch', null);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.branch_id', $branch->id)
        ->assertJsonPath('data.branch', null);
});

test('customer is rejected from the staff login area with a clear message', function (): void {
    User::factory()->create([
        'email' => 'customer@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => 'customer@mizuki.test',
        'password' => 'secret-password',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Vui lòng đăng nhập tại khu vực khách hàng!');

    $this->assertGuest();
});

test('staff login rejects an invalid password without authenticating the user', function (): void {
    User::factory()->create([
        'email' => 'manager@mizuki.test',
        'password' => 'secret-password',
        'role' => UserRole::BranchManager,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => 'manager@mizuki.test',
        'password' => 'wrong-password',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Thông tin đăng nhập không đúng!');

    $this->assertGuest();
});

test('staff login validation uses the standard API envelope', function (): void {
    $this->postJson('/api/v1/auth/staff-login', [
        'email' => 'invalid-email',
        'password' => '',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.errors.email.0', 'Email không đúng định dạng')
        ->assertJsonPath('data.errors.password.0', 'Vui lòng nhập mật khẩu');
});

test('staff login rejects phone credentials and remains email only', function (): void {
    User::factory()->create([
        'email' => 'phone-staff@mizuki.test',
        'phone' => '0368123456',
        'password' => 'secret-password',
        'role' => UserRole::SuperAdmin,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'phone' => '0368123456',
        'password' => 'secret-password',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.email.0', 'Vui lòng nhập email')
        ->assertJsonPath('data.errors.phone.0', 'Khu vực nhân viên chỉ hỗ trợ đăng nhập bằng email');

    $this->assertGuest();
});
