<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login succeeds before the throttle limit is reached', function (): void {
    $user = User::factory()->create([
        'email' => 'normal-login@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'normal-login@example.com',
        'password' => 'secret-password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id);
});

test('login throttling rejects excessive attempts', function (): void {
    User::factory()->create([
        'email' => 'bruteforce@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruteforce@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'bruteforce@example.com',
        'password' => 'wrong-password',
    ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Quá nhiều yêu cầu. Vui lòng thử lại sau!')
        ->assertJsonStructure(['meta' => ['retry_after']]);

    expect($response->json('meta.retry_after'))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(60);
});

test('registration succeeds before the throttle limit is reached', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Mizuki Customer',
        'email' => 'normal-register@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'normal-register@example.com');
});

test('registration throttling rejects excessive attempts', function (): void {
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('/api/v1/auth/register', [
            'name' => "Mizuki Customer {$attempt}",
            'email' => "registration-abuse-{$attempt}@example.com",
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated();
    }

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Mizuki Customer 4',
        'email' => 'registration-abuse-4@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Quá nhiều yêu cầu. Vui lòng thử lại sau!')
        ->assertJsonStructure(['meta' => ['retry_after']]);

    expect($response->json('meta.retry_after'))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(60);
});
