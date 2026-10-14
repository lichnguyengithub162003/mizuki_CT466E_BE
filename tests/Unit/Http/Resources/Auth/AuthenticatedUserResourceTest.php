<?php

use App\Enums\UserRole;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Models\Branch;
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

    $payload = (new AuthenticatedUserResource($user))->toArray(new Request);

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

test('it serializes only a loaded assigned branch and preserves a missing branch as null', function (): void {
    $user = new User([
        'name' => 'Branch Manager',
        'email' => 'manager@example.com',
        'role' => UserRole::BranchManager,
        'branch_id' => 5,
    ]);
    $branch = new Branch(['name' => 'Mizuki Ninh Kiều']);
    $branch->id = 5;
    $user->setRelation('branch', $branch);

    $payload = (new AuthenticatedUserResource($user))->toArray(new Request);
    expect($payload['branch_id'])->toBe(5)
        ->and($payload['branch'])->toBe(['id' => 5, 'name' => 'Mizuki Ninh Kiều']);

    $user->setRelation('branch', null);
    $missingPayload = (new AuthenticatedUserResource($user))->toArray(new Request);
    expect($missingPayload['branch_id'])->toBe(5)
        ->and($missingPayload['branch'])->toBeNull();
});
