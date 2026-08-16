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

test('a customer can log in with phone and password', function (): void {
    $user = User::factory()->create([
        'email' => 'phone-customer@example.com',
        'phone' => '0368123456',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0368123456',
        'password' => 'secret-password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.phone', '0368123456')
        ->assertJsonPath('message', 'Đăng nhập thành công!');

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

test('customer login requires exactly one valid email or phone identifier', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'phone' => '0268123456',
        'password' => 'secret-password',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.phone.0', 'Số điện thoại không đúng định dạng');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'phone' => '0368123456',
        'password' => 'secret-password',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['email', 'phone']]]);

    $this->postJson('/api/v1/auth/login', [
        'password' => 'secret-password',
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['email', 'phone']]]);
});

test('wrong phone credentials use the same generic authentication failure', function (): void {
    User::factory()->create([
        'phone' => '0368123456',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0368123456',
        'password' => 'wrong-password',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'Thông tin đăng nhập không đúng!');

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0399999999',
        'password' => 'secret-password',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'Thông tin đăng nhập không đúng!');
});

test('customer phone login still rejects staff accounts after identity lookup', function (): void {
    User::factory()->create([
        'phone' => '0368123456',
        'password' => 'secret-password',
        'role' => UserRole::Cashier,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0368123456',
        'password' => 'secret-password',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'Tài khoản không có quyền đăng nhập khu vực khách hàng!');
});
