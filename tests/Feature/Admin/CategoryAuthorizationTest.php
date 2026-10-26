<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

function authorizationCategory(): Category
{
    return Category::query()->create(['name' => 'Authorization category', 'slug' => 'authorization-category']);
}

test('super admin can read and mutate categories', function (): void {
    $category = authorizationCategory();
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/categories')->assertOk();
    $this->getJson("/api/v1/admin/categories/{$category->id}")->assertOk();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Created category', 'slug' => 'created-category'])
        ->assertCreated();
    $this->putJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Updated category'])
        ->assertOk()->assertJsonPath('data.name', 'Updated category');
    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
});

test('other internal staff can read categories but cannot mutate them', function (): void {
    $category = authorizationCategory();

    foreach ([UserRole::BranchManager, UserRole::Cashier, UserRole::SalesStaff, UserRole::Technician] as $role) {
        actingAsPortalUser($this, User::factory()->create(['role' => $role]));

        $this->getJson('/api/v1/admin/categories')->assertOk();
        $this->getJson("/api/v1/admin/categories/{$category->id}")->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Denied', 'slug' => 'denied'])
            ->assertForbidden();
        $this->putJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])
            ->assertForbidden();
        $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false])
            ->assertForbidden();
    }

    expect($category->refresh()->name)->toBe('Authorization category')
        ->and($category->is_active)->toBeTrue()
        ->and(Category::query()->where('slug', 'denied')->exists())->toBeFalse();
});

test('guest is unauthenticated and customer is forbidden on category admin routes', function (): void {
    $category = authorizationCategory();

    $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
    $this->getJson("/api/v1/admin/categories/{$category->id}")->assertUnauthorized();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Denied', 'slug' => 'denied'])->assertUnauthorized();
    $this->putJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])->assertUnauthorized();
    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])->assertUnauthorized();

    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::Customer]));
    $this->getJson('/api/v1/admin/categories')->assertForbidden();
    $this->getJson("/api/v1/admin/categories/{$category->id}")->assertForbidden();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Denied', 'slug' => 'denied'])->assertForbidden();
    $this->putJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])->assertForbidden();
    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])->assertForbidden();
});

test('invalidated and trashed staff sessions cannot access category admin routes', function (): void {
    $category = authorizationCategory();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    actingAsPortalUser($this, $admin);

    Auth::guard('web')->getSession()->put('staff_auth_generation', $admin->auth_generation + 1);
    $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Denied', 'slug' => 'denied'])->assertUnauthorized();

    actingAsPortalUser($this, $admin);
    $admin->delete();
    $this->getJson("/api/v1/admin/categories/{$category->id}")->assertUnauthorized();
    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Denied'])->assertUnauthorized();
});

test('unrelated admin module permissions remain restricted', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::Cashier]));
    $this->getJson('/api/v1/admin/brands')->assertForbidden();
    $this->postJson('/api/v1/admin/brands', ['name' => 'Denied', 'slug' => 'denied'])->assertForbidden();
});
