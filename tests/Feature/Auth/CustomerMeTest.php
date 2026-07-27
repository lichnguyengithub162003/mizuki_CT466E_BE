<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('an authenticated customer can view their account', function (): void {
    $user = User::factory()->create([
        'name' => 'Mizuki Customer',
        'email' => 'customer@example.com',
        'role' => UserRole::Customer,
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy thông tin tài khoản thành công!')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.role', UserRole::Customer->value);
});

test('a guest cannot view the customer account endpoint', function (): void {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
});

test('an authenticated staff user can view their identity through the shared me endpoint', function (): void {
    $user = User::factory()->create([
        'email' => 'cashier@example.com',
        'role' => UserRole::Cashier,
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'cashier@example.com')
        ->assertJsonPath('data.role', UserRole::Cashier->value);
});
