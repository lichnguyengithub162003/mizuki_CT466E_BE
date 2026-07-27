<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('it creates a customer user for registration', function (): void {
    $repository = new UserRepository(new User());

    $user = $repository->createCustomer([
        'name' => 'Mizuki Customer',
        'email' => 'customer@example.com',
        'password' => 'secret-password',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Mizuki Customer')
        ->and($user->email)->toBe('customer@example.com')
        ->and($user->role)->toBe(UserRole::Customer)
        ->and($user->branch_id)->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->assertDatabaseHas('users', [
        'email' => 'customer@example.com',
        'role' => UserRole::Customer->value,
        'branch_id' => null,
    ]);
});

test('it finds a user by email', function (): void {
    $repository = new UserRepository(new User());
    $user = User::factory()->create([
        'email' => 'customer@example.com',
    ]);

    $foundUser = $repository->findByEmail('customer@example.com');

    expect($foundUser?->is($user))->toBeTrue();
});

test('it returns null when no user exists for the email', function (): void {
    $repository = new UserRepository(new User());

    expect($repository->findByEmail('missing@example.com'))->toBeNull();
});

test('it creates a customer user from a verified oauth identity', function (): void {
    $repository = new UserRepository(new User());

    $user = $repository->createCustomerFromOAuth([
        'name' => 'Google Customer',
        'email' => 'google-customer@example.com',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Google Customer')
        ->and($user->email)->toBe('google-customer@example.com')
        ->and($user->role)->toBe(UserRole::Customer)
        ->and($user->branch_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBeEmpty();
});
