<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MediaUpload;
use App\Models\Product;
use App\Models\User;
use App\Services\Media\PublicMediaServiceContract;
use App\Services\Media\StagingMediaStorageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
});

function atomicCategory(string $slug, ?int $parentId = null): Category
{
    return Category::query()->create(['name' => $slug, 'slug' => $slug, 'parent_id' => $parentId]);
}

function atomicPost(object $test, string $slug, array $extra = []): mixed
{
    return $test->postJson('/api/v1/admin/categories', array_merge(['name' => $slug, 'slug' => $slug], $extra));
}

test('legacy create and explicit empty collections keep the single category response', function (): void {
    atomicPost($this, 'legacy-single')->assertCreated()->assertJsonPath('data.children_count', 0);
    $root = atomicPost($this, 'empty-root', [
        'attach_existing_child_ids' => [], 'new_children' => [],
    ])->assertCreated()->assertJsonPath('data.parent_id', null)->json('data.id');
    atomicPost($this, 'under-root', ['parent_id' => $root])->assertCreated()->assertJsonPath('data.parent_id', $root);

    expect(Category::query()->count())->toBe(3);
});

test('validated nested arrays can discard unknown keys without rejecting the payload', function (): void {
    $validator = Validator::make([
        'new_children' => [['name' => 'Child', 'slug' => 'child', 'id' => 99]],
    ], [
        'new_children' => ['array', 'list'],
        'new_children.*.name' => ['required', 'string'],
        'new_children.*.slug' => ['required', 'string'],
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($validator->validated()['new_children'][0])->not->toHaveKey('id');
});

test('rejects every unexpected child field before writing any category', function (): void {
    foreach (['id', 'parent_id', 'is_active', 'image_url', 'new_children', 'attach_existing_child_ids', 'unknown'] as $field) {
        atomicPost($this, 'unexpected-parent', ['new_children' => [[
            'name' => 'Unexpected child', 'slug' => 'unexpected-child', $field => 'unexpected',
        ]]])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['new_children.0']]]);
    }

    expect(Category::query()->count())->toBe(0);
});

test('rejects scalar list and empty child elements', function (): void {
    foreach (['child', 5, ['name', 'slug'], []] as $child) {
        atomicPost($this, 'invalid-element-parent', ['new_children' => [$child]])->assertUnprocessable();
    }

    expect(Category::query()->count())->toBe(0);
});

test('rejects duplicate image token shared by parent and child', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_driver', 'local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
    $image = UploadedFile::fake()->createWithContent(
        'duplicate.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
    $token = $this->post('/api/v1/admin/media/images', ['image' => $image], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    atomicPost($this, 'duplicate-token-parent', [
        'image_upload_token' => $token,
        'new_children' => [['name' => 'Duplicate token child', 'slug' => 'duplicate-token-child', 'image_upload_token' => $token]],
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['new_children']]]);

    expect(Category::query()->count())->toBe(0);
});

test('rejects parent and child slugs that differ only by case', function (): void {
    atomicPost($this, 'Case-Variant', [
        'new_children' => [['name' => 'Case child', 'slug' => 'case-variant']],
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['new_children.0.slug']]]);

    expect(Category::query()->count())->toBe(0);
});

test('creates multiple new direct children with lifecycle fields', function (): void {
    $id = atomicPost($this, 'new-parent', ['new_children' => [
        ['name' => 'Draft child', 'slug' => 'new-draft', 'status' => 'draft', 'is_featured' => true],
        ['name' => 'Hidden child', 'slug' => 'new-hidden', 'status' => 'hidden'],
    ]])->assertCreated()->assertJsonPath('data.children_count', 2)->json('data.id');

    expect(Category::query()->where('parent_id', $id)->count())->toBe(2)
        ->and(Category::query()->where('slug', 'new-draft')->firstOrFail()->is_active)->toBeFalse()
        ->and(Category::query()->where('slug', 'new-draft')->firstOrFail()->is_featured)->toBeTrue()
        ->and(Category::query()->where('slug', 'new-hidden')->firstOrFail()->status)->toBe('hidden');
});

test('reparents multiple existing children without changing product categories', function (): void {
    $first = atomicCategory('attach-first');
    $second = atomicCategory('attach-second');
    $brand = Brand::query()->create(['name' => 'Atomic brand', 'slug' => 'atomic-brand']);
    $product = Product::query()->create([
        'brand_id' => $brand->id, 'category_id' => $first->id,
        'name' => 'Atomic product', 'slug' => 'atomic-product', 'is_active' => true,
    ]);

    $id = atomicPost($this, 'attach-parent', ['attach_existing_child_ids' => [$first->id, $second->id]])
        ->assertCreated()->assertJsonPath('data.children_count', 2)->json('data.id');

    expect($first->fresh()->parent_id)->toBe($id)
        ->and($second->fresh()->parent_id)->toBe($id)
        ->and($product->fresh()->category_id)->toBe($first->id)
        ->and($product->fresh()->is_active)->toBeTrue()
        ->and(Category::query()->count())->toBe(3);
});

test('combines existing and new children', function (): void {
    $existing = atomicCategory('combined-existing');
    $id = atomicPost($this, 'combined-parent', [
        'attach_existing_child_ids' => [$existing->id],
        'new_children' => [['name' => 'Combined new', 'slug' => 'combined-new']],
    ])->assertCreated()->assertJsonPath('data.children_count', 2)->json('data.id');

    expect($existing->fresh()->parent_id)->toBe($id)
        ->and(Category::query()->where('slug', 'combined-new')->firstOrFail()->parent_id)->toBe($id);
});

test('creates level three children under a new level two category', function (): void {
    $root = atomicCategory('valid-depth-root');
    $existing = atomicCategory('valid-depth-existing');

    $id = atomicPost($this, 'valid-depth-parent', [
        'parent_id' => $root->id,
        'attach_existing_child_ids' => [$existing->id],
        'new_children' => [['name' => 'Valid depth child', 'slug' => 'valid-depth-child']],
    ])->assertCreated()->assertJsonPath('data.children_count', 2)->json('data.id');

    expect($existing->fresh()->parent_id)->toBe($id)
        ->and(Category::query()->where('slug', 'valid-depth-child')->firstOrFail()->parent_id)->toBe($id);
});

test('rejects children beneath a new level three category', function (): void {
    $root = atomicCategory('depth-root');
    $levelTwo = atomicCategory('depth-two', $root->id);
    $existing = atomicCategory('depth-existing');
    $before = Category::query()->count();

    atomicPost($this, 'depth-parent-new', [
        'parent_id' => $levelTwo->id,
        'attach_existing_child_ids' => [$existing->id],
        'new_children' => [['name' => 'Too deep', 'slug' => 'depth-new-child']],
    ])->assertUnprocessable();

    expect(Category::query()->count())->toBe($before)
        ->and($existing->fresh()->parent_id)->toBeNull();
});

test('rejects attached subtree that would reach level four', function (): void {
    $branch = atomicCategory('subtree-branch');
    $leaf = atomicCategory('subtree-leaf', $branch->id);
    $root = atomicCategory('subtree-root');
    $before = Category::query()->count();

    atomicPost($this, 'subtree-parent-new', [
        'parent_id' => $root->id, 'attach_existing_child_ids' => [$branch->id],
    ])->assertUnprocessable();

    expect(Category::query()->count())->toBe($before)
        ->and($branch->fresh()->parent_id)->toBeNull()
        ->and($leaf->fresh()->parent_id)->toBe($branch->id);
});

test('rejects duplicate missing deleted and overlapping existing children', function (): void {
    $first = atomicCategory('invalid-first');
    $child = atomicCategory('invalid-child', $first->id);
    $deleted = atomicCategory('invalid-deleted');
    $deleted->delete();

    foreach ([[$first->id, $first->id], [$first->id, 999999], [$deleted->id], [$first->id, $child->id]] as $ids) {
        atomicPost($this, 'invalid-new-parent', ['attach_existing_child_ids' => $ids])->assertUnprocessable();
    }

    expect(Category::query()->where('slug', 'invalid-new-parent')->exists())->toBeFalse()
        ->and($first->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($first->id);
});

test('rejects attaching an ancestor of the selected parent', function (): void {
    $root = atomicCategory('cycle-existing-root');
    $child = atomicCategory('cycle-existing-child', $root->id);

    atomicPost($this, 'cycle-new-parent', [
        'parent_id' => $child->id, 'attach_existing_child_ids' => [$root->id],
    ])->assertUnprocessable();

    expect(Category::query()->where('slug', 'cycle-new-parent')->exists())->toBeFalse()
        ->and($root->fresh()->parent_id)->toBeNull();
});

test('rejects duplicate request and database slugs without partial writes', function (): void {
    atomicCategory('taken-slug');
    foreach ([
        ['slug' => 'duplicate-root', 'new_children' => [['name' => 'Same', 'slug' => 'duplicate-root']]],
        ['new_children' => [['name' => 'One', 'slug' => 'same-child'], ['name' => 'Two', 'slug' => 'same-child']]],
        ['new_children' => [['name' => 'Taken', 'slug' => 'taken-slug']]],
    ] as $extra) {
        atomicPost($this, 'duplicate-root', $extra)->assertUnprocessable();
    }

    expect(Category::query()->count())->toBe(1);
});

test('mid operation persistence failure rolls back parent and reparenting', function (): void {
    $existing = atomicCategory('failure-existing');
    Category::saving(function (Category $category): void {
        if ($category->slug === 'failure-child') {
            throw new RuntimeException('Injected child save failure');
        }
    });

    atomicPost($this, 'failure-parent', [
        'attach_existing_child_ids' => [$existing->id],
        'new_children' => [['name' => 'Failure child', 'slug' => 'failure-child']],
    ])->assertInternalServerError();

    expect(Category::query()->count())->toBe(1)
        ->and($existing->fresh()->parent_id)->toBeNull();
});

test('failed image promotion rolls back parent and every reparent', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_driver', 'local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
    $existing = atomicCategory('media-existing');
    $image = UploadedFile::fake()->createWithContent(
        'atomic.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
    $token = $this->post('/api/v1/admin/media/images', ['image' => $image], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');
    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    $staging = Mockery::mock(StagingMediaStorageContract::class);
    $staging->shouldReceive('exists')->with($upload->staging_key)->andReturnTrue();
    $staging->shouldReceive('readStream')->once()->andThrow(new RuntimeException('Injected promotion failure'));
    $staging->shouldReceive('delete')->andReturnTrue();
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(fn (string $key): string => $key);
    $publicMedia->shouldReceive('delete')->once()->andReturnTrue();
    $this->app->instance(StagingMediaStorageContract::class, $staging);
    $this->app->instance(PublicMediaServiceContract::class, $publicMedia);

    atomicPost($this, 'media-parent', [
        'attach_existing_child_ids' => [$existing->id],
        'new_children' => [['name' => 'Media child', 'slug' => 'media-child', 'image_upload_token' => $token]],
    ])->assertInternalServerError();

    expect(Category::query()->count())->toBe(1)
        ->and($existing->fresh()->parent_id)->toBeNull()
        ->and(Category::query()->where('slug', 'media-child')->exists())->toBeFalse();
});

test('new child image token is promoted to its own category', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_driver', 'local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
    $image = UploadedFile::fake()->createWithContent(
        'child.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
    $token = $this->post('/api/v1/admin/media/images', ['image' => $image], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    $parentId = atomicPost($this, 'image-child-parent', [
        'new_children' => [['name' => 'Image child', 'slug' => 'image-child', 'image_upload_token' => $token]],
    ])->assertCreated()->json('data.id');
    $child = Category::query()->where('slug', 'image-child')->firstOrFail();

    expect($child->parent_id)->toBe($parentId)
        ->and($child->image_url)->toStartWith("public/categories/{$child->id}/");
    Storage::disk('public')->assertExists($child->image_url);
});

test('staff without mutation permission cannot use atomic create', function (): void {
    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::BranchManager]));
    atomicPost($this, 'forbidden-atomic', [
        'new_children' => [['name' => 'Forbidden child', 'slug' => 'forbidden-child']],
    ])->assertForbidden();
    expect(Category::query()->count())->toBe(0);
});
