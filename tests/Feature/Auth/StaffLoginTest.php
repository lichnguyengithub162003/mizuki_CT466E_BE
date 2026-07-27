<?php

use App\Enums\UserRole;
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
