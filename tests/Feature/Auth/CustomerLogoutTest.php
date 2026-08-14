<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an authenticated customer can log out', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'secret-password',
    ])->assertOk();

    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đăng xuất thành công!')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.role', UserRole::Customer->value)
        ->assertJsonPath('meta', []);

    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
});

test('a guest cannot log out', function (): void {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
});

test('a repeated logout request is rejected after the session is invalidated', function (): void {
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'secret-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson('/api/v1/auth/logout')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
});

test('an authenticated staff user can log out through the shared endpoint', function (
    UserRole $role,
    string $email,
): void {
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'secret-password',
        'role' => $role,
    ]);

    $this->postJson('/api/v1/auth/staff-login', [
        'email' => $email,
        'password' => 'secret-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', $role->value)
        ->assertJsonPath('message', 'Đăng xuất thành công!');

    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
})->with([
    'super admin' => [UserRole::SuperAdmin, 'super-admin@example.com'],
    'branch manager' => [UserRole::BranchManager, 'branch-manager@example.com'],
]);
