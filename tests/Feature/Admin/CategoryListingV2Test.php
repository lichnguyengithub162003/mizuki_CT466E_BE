<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

function listingCategory(string $name, ?Category $parent = null, int $order = 0, bool $active = true): Category
{
    return Category::query()->create([
        'parent_id' => $parent?->id,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'sort_order' => $order,
        'is_active' => $active,
    ]);
}

beforeEach(function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
});

test('root and direct child pages retain sibling pagination and deep paths', function (): void {
    $root = listingCategory('Root Alpha');
    $otherRoot = listingCategory('Root Beta');
    $child = listingCategory('Child Alpha', $root);
    $second = listingCategory('Child Beta', $root);
    $grandchild = listingCategory('Grandchild', $child);

    $this->getJson('/api/v1/admin/categories?mode=root&per_page=1&page=2')
        ->assertOk()->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.id', $otherRoot->id);
    $this->getJson("/api/v1/admin/categories?mode=children&parent_id={$root->id}&per_page=1")
        ->assertOk()->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.id', $child->id)
        ->assertJsonPath('data.0.children_count', 1)
        ->assertJsonPath('data.0.has_children', true);
    $this->getJson("/api/v1/admin/categories?mode=children&parent_id={$root->id}&per_page=1&page=2")
        ->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/v1/admin/categories?search=grandchild')
        ->assertOk()->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $grandchild->id)
        ->assertJsonPath('data.0.ancestors.0.id', $root->id)
        ->assertJsonPath('data.0.ancestors.1.id', $child->id);
});

test('slug search is global by default and filters compose with sorting', function (): void {
    $root = listingCategory('Root Gamma');
    $inactive = listingCategory('Hidden Child', $root, 2, false);
    $leaf = listingCategory('Visible Child', $root, 1);
    listingCategory('Nested Leaf', $inactive);

    $this->getJson('/api/v1/admin/categories?search=hidden-child')
        ->assertOk()->assertJsonPath('data.0.id', $inactive->id);
    $this->getJson("/api/v1/admin/categories?mode=children&parent_id={$root->id}&is_active=0&has_children=1")
        ->assertOk()->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $inactive->id)
        ->assertJsonPath('data.0.is_active', false);
    $this->getJson("/api/v1/admin/categories?mode=children&parent_id={$root->id}&has_children=0")
        ->assertOk()->assertJsonPath('data.0.id', $leaf->id);
    $this->getJson('/api/v1/admin/categories?sort=unexpected')->assertUnprocessable();
    $this->getJson('/api/v1/admin/categories?direction=sideways')->assertUnprocessable();
    $this->getJson('/api/v1/admin/categories?mode=children')->assertUnprocessable();
});

test('allowed sort keys and directions are applied with stable id ties', function (): void {
    $a = listingCategory('Alpha', null, 2);
    $b = listingCategory('Beta', null, 1);
    $c = listingCategory('Gamma', null, 1);
    $a->forceFill(['created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'])->save();
    $b->forceFill(['created_at' => '2024-01-02 00:00:00', 'updated_at' => '2024-01-02 00:00:00'])->save();
    $c->forceFill(['created_at' => '2024-01-03 00:00:00', 'updated_at' => '2024-01-03 00:00:00'])->save();
    $brand = Brand::query()->create(['name' => 'Sort Brand', 'slug' => 'sort-brand', 'is_active' => true]);
    Product::query()->create([
        'category_id' => $b->id, 'brand_id' => $brand->id, 'name' => 'Sort Product', 'slug' => 'sort-product',
        'is_active' => true, 'is_featured' => false,
    ]);

    foreach ([
        'sort_order&direction=asc' => [$b->id, $c->id, $a->id],
        'sort_order&direction=desc' => [$a->id, $b->id, $c->id],
        'name&direction=asc' => [$a->id, $b->id, $c->id],
        'name&direction=desc' => [$c->id, $b->id, $a->id],
        'created_at&direction=asc' => [$a->id, $b->id, $c->id],
        'created_at&direction=desc' => [$c->id, $b->id, $a->id],
        'updated_at&direction=asc' => [$a->id, $b->id, $c->id],
        'updated_at&direction=desc' => [$c->id, $b->id, $a->id],
        'product_count&direction=asc' => [$a->id, $c->id, $b->id],
        'product_count&direction=desc' => [$b->id, $a->id, $c->id],
    ] as $query => $ids) {
        $response = $this->getJson('/api/v1/admin/categories?sort='.$query)->assertOk();
        expect(array_column($response->json('data'), 'id'))->toBe($ids);
    }
});

test('existing category mutation and cycle rules remain intact without deletion', function (): void {
    $root = listingCategory('Cycle Root');
    $child = listingCategory('Cycle Child', $root);
    $this->patchJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $child->id])->assertUnprocessable();
    $this->patchJson("/api/v1/admin/categories/{$child->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/v1/admin/categories', ['name' => 'No Image', 'slug' => 'no-image'])
        ->assertCreated()->assertJsonPath('data.image_url', null);
    $this->postJson('/api/v1/admin/categories', [
        'name' => 'Bad Token', 'slug' => 'bad-token', 'image_upload_token' => 'invalid',
    ])->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/categories/{$root->id}")->assertStatus(405);
});

test('category listing is available to branch managers and forbidden to other staff', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::BranchManager]));
    $this->getJson('/api/v1/admin/categories?mode=root')->assertOk();
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::Cashier]));
    $this->getJson('/api/v1/admin/categories?mode=root')->assertForbidden();
});
