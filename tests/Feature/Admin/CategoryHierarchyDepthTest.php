<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
});

function createHierarchyCategory(object $test, string $slug, ?int $parentId = null, array $extra = []): int
{
    return (int) $test->postJson('/api/v1/admin/categories', array_merge([
        'name' => $slug,
        'slug' => $slug,
        'parent_id' => $parentId,
    ], $extra))->assertCreated()->json('data.id');
}

test('creates three empty levels and rejects a fourth without changing relationships', function (): void {
    $root = createHierarchyCategory($this, 'depth-root');
    $child = createHierarchyCategory($this, 'depth-child', $root);
    $grandchild = createHierarchyCategory($this, 'depth-grandchild', $child);

    $this->postJson('/api/v1/admin/categories', [
        'name' => 'Too deep', 'slug' => 'depth-too-deep', 'parent_id' => $grandchild,
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['parent_id']]]);

    expect(Category::query()->count())->toBe(3)
        ->and(Category::query()->findOrFail($root)->parent_id)->toBeNull()
        ->and(Category::query()->findOrFail($child)->parent_id)->toBe($root)
        ->and(Category::query()->findOrFail($grandchild)->parent_id)->toBe($child)
        ->and(Category::query()->findOrFail($grandchild)->products()->count())->toBe(0);
});

test('reparents a leaf within the three level limit', function (): void {
    $firstRoot = createHierarchyCategory($this, 'leaf-first-root');
    $secondRoot = createHierarchyCategory($this, 'leaf-second-root');
    $leaf = createHierarchyCategory($this, 'movable-leaf', $firstRoot);

    $this->patchJson("/api/v1/admin/categories/{$leaf}", ['parent_id' => $secondRoot])
        ->assertOk()->assertJsonPath('data.parent_id', $secondRoot);

    expect(Category::query()->findOrFail($leaf)->parent_id)->toBe($secondRoot);
});

test('rejects a branch move that would push its descendants to level four and retains products', function (): void {
    $firstRoot = createHierarchyCategory($this, 'branch-first-root');
    $branch = createHierarchyCategory($this, 'branch-to-move', $firstRoot);
    $leaf = createHierarchyCategory($this, 'branch-leaf', $branch);
    $secondRoot = createHierarchyCategory($this, 'branch-second-root');
    $secondLevel = createHierarchyCategory($this, 'branch-second-level', $secondRoot);
    $brand = Brand::query()->create(['name' => 'Hierarchy brand', 'slug' => 'hierarchy-brand']);
    $product = Product::query()->create([
        'category_id' => $leaf, 'brand_id' => $brand->id,
        'name' => 'Attached product', 'slug' => 'attached-product',
    ]);

    $this->patchJson("/api/v1/admin/categories/{$branch}", ['parent_id' => $secondLevel])
        ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['parent_id']]]);

    expect(Category::query()->findOrFail($branch)->parent_id)->toBe($firstRoot)
        ->and(Category::query()->findOrFail($leaf)->parent_id)->toBe($branch)
        ->and($product->fresh()->category_id)->toBe($leaf);
});

test('rejects self parenting and descendant cycles without changing the tree', function (): void {
    $root = createHierarchyCategory($this, 'cycle-root');
    $child = createHierarchyCategory($this, 'cycle-child', $root);
    $grandchild = createHierarchyCategory($this, 'cycle-grandchild', $child);

    $this->patchJson("/api/v1/admin/categories/{$root}", ['parent_id' => $root])
        ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['parent_id']]]);
    $this->patchJson("/api/v1/admin/categories/{$root}", ['parent_id' => $grandchild])
        ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['parent_id']]]);

    expect(Category::query()->findOrFail($root)->parent_id)->toBeNull()
        ->and(Category::query()->findOrFail($child)->parent_id)->toBe($root)
        ->and(Category::query()->findOrFail($grandchild)->parent_id)->toBe($child);
});

test('draft hidden and featured categories follow the same hierarchy limit', function (): void {
    $root = createHierarchyCategory($this, 'state-root', null, ['status' => Category::STATUS_DRAFT, 'is_featured' => true]);
    $child = createHierarchyCategory($this, 'state-child', $root, ['status' => Category::STATUS_HIDDEN, 'is_featured' => true]);
    $grandchild = createHierarchyCategory($this, 'state-grandchild', $child, ['status' => Category::STATUS_DRAFT]);

    $this->postJson('/api/v1/admin/categories', [
        'name' => 'State fourth', 'slug' => 'state-fourth', 'parent_id' => $grandchild,
        'status' => Category::STATUS_HIDDEN, 'is_featured' => true,
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['parent_id']]]);

    expect(Category::query()->findOrFail($root)->is_featured)->toBeTrue()
        ->and(Category::query()->findOrFail($child)->status)->toBe(Category::STATUS_HIDDEN)
        ->and(Category::query()->findOrFail($grandchild)->parent_id)->toBe($child);
});

test('a valid category still promotes a staged image', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_driver', 'local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
    $image = UploadedFile::fake()->createWithContent(
        'category.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
    $token = $this->post('/api/v1/admin/media/images', ['image' => $image], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    $id = createHierarchyCategory($this, 'image-category', null, ['image_upload_token' => $token]);
    $key = Category::query()->findOrFail($id)->image_url;
    expect($key)->toStartWith("public/categories/{$id}/");
    Storage::disk('public')->assertExists($key);
});
