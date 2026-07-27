<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a customer can log in with email and password', function (): void {
    $user = User::factory()->create([
        'name' => 'Mizuki Customer',
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đăng nhập thành công!')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.role', UserRole::Customer->value);

    $this->assertAuthenticatedAs($user);
});

test('customer login rejects invalid credentials', function (): void {
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Thông tin đăng nhập không đúng!')
        ->assertJsonPath('data', null)
        ->assertJsonPath('meta', []);
});

test('customer login rejects internal staff accounts', function (): void {
    User::factory()->create([
        'email' => 'cashier@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Cashier,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'cashier@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Tài khoản không có quyền đăng nhập khu vực khách hàng!');
});

test('customer login validation errors use the API envelope', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'invalid-email',
        'password' => '',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Dữ liệu không hợp lệ')
        ->assertJsonPath('data.errors.email.0', 'Email không đúng định dạng')
        ->assertJsonPath('data.errors.password.0', 'Vui lòng nhập mật khẩu')
        ->assertJsonPath('meta', []);
});
