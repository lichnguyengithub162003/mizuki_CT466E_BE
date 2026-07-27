<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware(['api', 'auth:sanctum'])
        ->prefix('api/testing/role-middleware')
        ->group(function (): void {
            foreach (UserRole::cases() as $role) {
                Route::get($role->value, fn () => response()->json([
                    'success' => true,
                    'data' => ['role' => $role->value],
                    'message' => '',
                    'meta' => [],
                ]))->middleware("role:{$role->value}");
            }
        });
});

test('each supported role can access its matching route', function (UserRole $role): void {
    $user = User::factory()->create([
        'role' => $role,
    ]);
    Sanctum::actingAs($user);

    $this->getJson("/api/testing/role-middleware/{$role->value}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.role', $role->value);
})->with(UserRole::cases());

test('an authenticated user with the wrong role receives forbidden', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::Customer,
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/testing/role-middleware/cashier')
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn không có quyền truy cập chức năng này');
});

test('a guest receives unauthorized before role access is checked', function (): void {
    $this->getJson('/api/testing/role-middleware/cashier')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bạn cần đăng nhập để tiếp tục');
});
