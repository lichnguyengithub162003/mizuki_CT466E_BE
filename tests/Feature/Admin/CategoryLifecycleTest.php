<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

test('lifecycle migration backfills legacy active and inactive categories without changing identity or hierarchy', function (): void {
    $migration = require base_path('database/migrations/2026_10_26_000001_add_lifecycle_and_featured_to_categories_table.php');
    $migration->down();

    $rootId = DB::table('categories')->insertGetId([
        'name' => 'Legacy active', 'slug' => 'legacy-active', 'is_active' => true,
    ]);
    $childId = DB::table('categories')->insertGetId([
        'parent_id' => $rootId, 'name' => 'Legacy inactive', 'slug' => 'legacy-inactive', 'is_active' => false,
    ]);

    $migration->up();

    $root = Category::query()->findOrFail($rootId);
    $child = Category::query()->findOrFail($childId);
    expect($root->slug)->toBe('legacy-active')
        ->and($root->status)->toBe(Category::STATUS_PUBLISHED)
        ->and($root->is_active)->toBeTrue()
        ->and($root->is_featured)->toBeFalse()
        ->and($child->parent_id)->toBe($rootId)
        ->and($child->slug)->toBe('legacy-inactive')
        ->and($child->status)->toBe(Category::STATUS_HIDDEN)
        ->and($child->is_active)->toBeFalse()
        ->and($child->is_featured)->toBeFalse();
});

test('create supports draft published and hidden while preserving legacy defaults', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));

    foreach ([
        Category::STATUS_DRAFT => false,
        Category::STATUS_PUBLISHED => true,
        Category::STATUS_HIDDEN => false,
    ] as $status => $active) {
        $response = $this->postJson('/api/v1/admin/categories', [
            'name' => ucfirst($status), 'slug' => 'lifecycle-'.$status,
            'status' => $status, 'is_featured' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.is_active', $active)
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.product_count', 0);

        expect(Category::query()->findOrFail($response->json('data.id'))->is_active)->toBe($active);
    }

    $this->postJson('/api/v1/admin/categories', ['name' => 'Legacy default', 'slug' => 'legacy-default'])
        ->assertCreated()
        ->assertJsonPath('data.status', Category::STATUS_PUBLISHED)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_featured', false);
});

test('status and legacy is_active updates remain synchronized', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
    $id = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Lifecycle updates', 'slug' => 'lifecycle-updates',
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/admin/categories/{$id}", ['status' => Category::STATUS_DRAFT, 'is_featured' => true])
        ->assertOk()->assertJsonPath('data.status', Category::STATUS_DRAFT)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_featured', true);
    $this->patchJson("/api/v1/admin/categories/{$id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.status', Category::STATUS_HIDDEN)
        ->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/v1/admin/categories/{$id}", ['is_active' => true, 'is_featured' => false])
        ->assertOk()->assertJsonPath('data.status', Category::STATUS_PUBLISHED)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_featured', false);
    $this->putJson("/api/v1/admin/categories/{$id}", ['status' => Category::STATUS_HIDDEN])
        ->assertOk()->assertJsonPath('data.status', Category::STATUS_HIDDEN)
        ->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/admin/categories?is_active=0')
        ->assertOk()->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.status', Category::STATUS_HIDDEN);
    $this->getJson("/api/v1/admin/categories/{$id}")
        ->assertOk()->assertJsonPath('data.status', Category::STATUS_HIDDEN);
});

test('compatible status and is_active pairs are accepted and contradictions rejected', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));

    $draftId = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Draft pair', 'slug' => 'draft-pair',
        'status' => Category::STATUS_DRAFT, 'is_active' => false,
    ])->assertCreated()->assertJsonPath('data.status', Category::STATUS_DRAFT)->json('data.id');
    $this->postJson('/api/v1/admin/categories', [
        'name' => 'Published pair', 'slug' => 'published-pair',
        'status' => Category::STATUS_PUBLISHED, 'is_active' => true,
    ])->assertCreated();
    $this->postJson('/api/v1/admin/categories', [
        'name' => 'Hidden pair', 'slug' => 'hidden-pair',
        'status' => Category::STATUS_HIDDEN, 'is_active' => false,
    ])->assertCreated();

    foreach ([
        [Category::STATUS_DRAFT, true],
        [Category::STATUS_HIDDEN, true],
        [Category::STATUS_PUBLISHED, false],
    ] as [$status, $active]) {
        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Contradiction', 'slug' => 'contradiction-'.$status,
            'status' => $status, 'is_active' => $active,
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['is_active']]]);
        $this->patchJson("/api/v1/admin/categories/{$draftId}", [
            'status' => $status, 'is_active' => $active,
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['is_active']]]);
    }

    expect(Category::query()->findOrFail($draftId)->status)->toBe(Category::STATUS_DRAFT)
        ->and(Category::query()->where('name', 'Contradiction')->exists())->toBeFalse();
});

test('model writes through legacy and status fields preserve the lifecycle invariant', function (): void {
    $category = Category::query()->create([
        'name' => 'Model compatibility', 'slug' => 'model-compatibility', 'is_active' => false,
    ]);
    expect($category->status)->toBe(Category::STATUS_HIDDEN);

    $category->update(['status' => Category::STATUS_DRAFT]);
    expect($category->refresh()->is_active)->toBeFalse();
    $category->update(['is_active' => true]);
    expect($category->refresh()->status)->toBe(Category::STATUS_PUBLISHED);
});

test('model keeps draft on a no-op legacy assignment and unrelated updates', function (): void {
    $category = Category::query()->create([
        'name' => 'Model draft', 'slug' => 'model-draft', 'status' => Category::STATUS_DRAFT,
    ]);
    expect($category->is_active)->toBeFalse();

    $category->update(['is_active' => false]);
    expect($category->refresh()->status)->toBe(Category::STATUS_DRAFT)
        ->and($category->is_active)->toBeFalse();

    $category->update(['is_featured' => true]);
    expect($category->refresh()->status)->toBe(Category::STATUS_DRAFT)
        ->and($category->is_active)->toBeFalse()
        ->and($category->is_featured)->toBeTrue();

    $category->update(['status' => Category::STATUS_HIDDEN]);
    expect($category->refresh()->status)->toBe(Category::STATUS_HIDDEN)
        ->and($category->is_active)->toBeFalse();
});

test('legacy admin payload changes draft to hidden even when is_active is already false', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
    $category = Category::query()->create([
        'name' => 'Admin draft', 'slug' => 'admin-draft', 'status' => Category::STATUS_DRAFT,
    ]);

    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.status', Category::STATUS_HIDDEN)
        ->assertJsonPath('data.is_active', false);
    expect($category->refresh()->status)->toBe(Category::STATUS_HIDDEN);
});

test('importer-style restore with an active assignment publishes a draft', function (): void {
    $category = Category::query()->create([
        'name' => 'Restored draft', 'slug' => 'restored-draft', 'status' => Category::STATUS_DRAFT,
    ]);
    $category->delete();

    $category->is_active = true;
    $category->restore();

    expect($category->refresh()->status)->toBe(Category::STATUS_PUBLISHED)
        ->and($category->is_active)->toBeTrue()
        ->and($category->trashed())->toBeFalse();
});
