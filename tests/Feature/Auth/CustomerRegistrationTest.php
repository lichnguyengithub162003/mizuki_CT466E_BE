<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('a customer can register with email and password', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Mizuki Customer',
        'email' => 'customer@example.com',
        'phone' => '0368123456',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đăng ký tài khoản thành công!')
        ->assertJsonPath('data.name', 'Mizuki Customer')
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.phone', '0368123456')
        ->assertJsonPath('data.role', UserRole::Customer->value)
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'email',
                'role',
                'role_label',
                'branch_id',
                'email_verified_at',
                'created_at',
            ],
            'message',
            'meta',
        ]);

    $user = User::query()->where('email', 'customer@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Customer)
        ->and($user->branch_id)->toBeNull()
        ->and($user->phone)->toBe('0368123456')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('customer registration validation errors use the API envelope', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => '',
        'email' => 'invalid-email',
        'phone' => '1234567890',
        'password' => 'short',
        'password_confirmation' => 'different',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Dữ liệu không hợp lệ')
        ->assertJsonPath('data.errors.name.0', 'Vui lòng nhập họ tên')
        ->assertJsonPath('data.errors.email.0', 'Email không đúng định dạng')
        ->assertJsonPath('data.errors.phone.0', 'Số điện thoại không đúng định dạng')
        ->assertJsonPath('data.errors.password.0', 'Xác nhận mật khẩu không khớp')
        ->assertJsonPath('meta', []);
});

test('customer registration requires a unique canonical Vietnamese mobile phone', function (): void {
    User::factory()->create(['phone' => '0368123456']);
    $basePayload = [
        'name' => 'Mizuki Customer',
        'email' => 'new-customer@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ];

    $this->postJson('/api/v1/auth/register', $basePayload)
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.phone.0', 'Vui lòng nhập số điện thoại');

    $this->postJson('/api/v1/auth/register', [...$basePayload, 'phone' => '0268123456'])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.phone.0', 'Số điện thoại không đúng định dạng');

    $this->postJson('/api/v1/auth/register', [...$basePayload, 'phone' => '0368123456'])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.phone.0', 'Số điện thoại này đã được sử dụng');
});

test('existing customer registration email validation remains enforced', function (): void {
    User::factory()->create(['email' => 'used@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Mizuki Customer',
        'email' => 'used@example.com',
        'phone' => '0368123456',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.email.0', 'Email này đã được sử dụng');
});
