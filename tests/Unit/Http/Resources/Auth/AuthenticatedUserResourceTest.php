<?php

use App\Enums\UserRole;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\Request;

test('it transforms an authenticated user for API responses', function (): void {
    $user = new User([
        'name' => 'Mizuki Customer',
        'email' => 'customer@example.com',
        'role' => UserRole::Customer,
        'branch_id' => null,
        'password' => 'secret-password',
    ]);
    $user->id = 10;
    $user->created_at = now();

    $payload = (new AuthenticatedUserResource($user))->toArray(new Request());

    expect($payload)->toHaveKeys([
        'id',
        'name',
        'email',
        'role',
        'role_label',
        'branch_id',
        'email_verified_at',
        'created_at',
    ])
        ->and($payload['id'])->toBe(10)
        ->and($payload['name'])->toBe('Mizuki Customer')
        ->and($payload['email'])->toBe('customer@example.com')
        ->and($payload['role'])->toBe(UserRole::Customer->value)
        ->and($payload['role_label'])->toBe(UserRole::Customer->label())
        ->and($payload)->not->toHaveKeys(['password', 'remember_token']);
});
