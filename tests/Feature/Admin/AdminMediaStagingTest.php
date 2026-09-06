<?php

use App\Enums\MediaUploadStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MediaUpload;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Media\PublicMediaServiceContract;
use App\Services\Media\StagingMediaStorageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
});

function stagingPng(string $name = 'image.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
}

function stageImageFor(object $test, User $actor, string $name = 'image.png'): string
{
    return (string) $test->actingAs($actor)->post(
        '/api/v1/admin/media/images',
        ['image' => stagingPng($name)],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.upload_token');
}

function stagingBranch(): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => 'MS'.$token,
        'name' => 'Media Staging '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

test('staging upload is private, owned, metadata backed, and previewed through an authenticated signed proxy', function (): void {
    config()->set('media.upload_staging_ttl_hours', 2);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $response = $this->actingAs($admin)->post(
        '/api/v1/admin/media/images',
        ['image' => stagingPng()],
        ['Accept' => 'application/json'],
    )->assertCreated()
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonPath('data.width', 1)
        ->assertJsonPath('data.height', 1)
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.url');

    $upload = MediaUpload::query()->where('upload_token', $response->json('data.upload_token'))->firstOrFail();
    expect($upload->uploaded_by_user_id)->toBe($admin->id)
        ->and($upload->status)->toBe(MediaUploadStatus::Staged)
        ->and($upload->staging_key)->toMatch("~^staging/{$admin->id}/{$upload->upload_token}/[0-9a-f-]{36}\\.png$~")
        ->and($response->json('data.preview_url'))->toContain("/api/v1/admin/media/uploads/{$upload->upload_token}/preview")
        ->and($upload->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue();
    Storage::disk('local')->assertExists($upload->staging_key);
    Storage::disk('public')->assertMissing($upload->staging_key);

    $this->get($response->json('data.preview_url'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $this->get("/api/v1/admin/media/uploads/{$upload->upload_token}/preview")->assertForbidden();

    $otherAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($otherAdmin)->get($response->json('data.preview_url'))->assertNotFound();
});

test('staging upload and delete endpoints enforce admin roles and strict owner idempotency', function (): void {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $other = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $technician = User::factory()->create(['role' => UserRole::Technician]);

    $this->postJson('/api/v1/admin/media/images')->assertUnauthorized();
    $this->actingAs($technician)->post('/api/v1/admin/media/images', ['image' => stagingPng()])->assertForbidden();

    $token = stageImageFor($this, $owner);
    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    $this->actingAs($other)->deleteJson("/api/v1/admin/media/uploads/{$token}")->assertNotFound();
    $this->actingAs($owner)->deleteJson("/api/v1/admin/media/uploads/{$token}")
        ->assertOk()->assertJsonPath('data.status', 'deleted');
    Storage::disk('local')->assertMissing($upload->staging_key);
    $this->deleteJson("/api/v1/admin/media/uploads/{$token}")
        ->assertOk()->assertJsonPath('data.status', 'deleted');
    $this->deleteJson('/api/v1/admin/media/uploads/not-a-uuid')->assertNotFound();
});

test('only a fresh owner token can promote and raw owned keys cannot bypass ownership', function (): void {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $other = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $otherToken = stageImageFor($this, $owner, 'other.png');

    $this->actingAs($other)->postJson('/api/v1/admin/categories', [
        'name' => 'Other token category', 'slug' => 'other-token-category',
        'image_upload_token' => $otherToken,
    ])->assertUnprocessable();

    $expiredToken = stageImageFor($this, $owner, 'expired.png');
    MediaUpload::query()->where('upload_token', $expiredToken)->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($owner)->postJson('/api/v1/admin/categories', [
        'name' => 'Expired token category', 'slug' => 'expired-token-category',
        'image_upload_token' => $expiredToken,
    ])->assertUnprocessable();

    $missingToken = stageImageFor($this, $owner, 'missing.png');
    $missingUpload = MediaUpload::query()->where('upload_token', $missingToken)->firstOrFail();
    Storage::disk('local')->delete($missingUpload->staging_key);
    $this->actingAs($owner)->postJson('/api/v1/admin/categories', [
        'name' => 'Missing object category', 'slug' => 'missing-object-category',
        'image_upload_token' => $missingToken,
    ])->assertUnprocessable();

    foreach (['staging/1/fake/object.png', 'public/categories/1/'.Str::uuid().'.png'] as $forged) {
        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Forged '.Str::random(5), 'slug' => 'forged-'.Str::random(8),
            'image_url' => $forged,
        ])->assertUnprocessable();
    }

    $validToken = stageImageFor($this, $owner, 'valid.png');
    $created = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Owned category', 'slug' => 'owned-category',
        'image_upload_token' => $validToken,
    ])->assertCreated();
    $category = Category::query()->findOrFail($created->json('data.id'));
    expect($category->image_url)->toMatch("~^public/categories/{$category->id}/[0-9a-f-]{36}\\.png$~");
    Storage::disk('public')->assertExists($category->image_url);

    $this->postJson('/api/v1/admin/brands', [
        'name' => 'Reuse', 'slug' => 'reuse-token', 'logo_upload_token' => $validToken,
    ])->assertUnprocessable();
});

test('replacement cleanup retains a canonical object until its last database reference is removed', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $token = stageImageFor($this, $admin, 'shared.png');
    $firstResponse = $this->actingAs($admin)->postJson('/api/v1/admin/brands', [
        'name' => 'Shared first', 'slug' => 'shared-first', 'logo_upload_token' => $token,
    ])->assertCreated();
    $first = Brand::query()->findOrFail($firstResponse->json('data.id'));
    $sharedKey = $first->logo_url;
    $second = Brand::query()->create([
        'name' => 'Shared second', 'slug' => 'shared-second', 'logo_url' => $sharedKey,
    ]);

    $firstReplacement = stageImageFor($this, $admin, 'first-replacement.png');
    $this->patchJson("/api/v1/admin/brands/{$first->id}", [
        'logo_upload_token' => $firstReplacement,
    ])->assertOk();
    Storage::disk('public')->assertExists($sharedKey);

    $secondReplacement = stageImageFor($this, $admin, 'second-replacement.png');
    $this->patchJson("/api/v1/admin/brands/{$second->id}", [
        'logo_upload_token' => $secondReplacement,
    ])->assertOk();
    Storage::disk('public')->assertMissing($sharedKey);
});

test('product supports multiple gallery and variant tokens and safely replaces or preserves images', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $category = Category::query()->create(['name' => 'Media', 'slug' => 'media-products']);
    $brand = Brand::query()->create(['name' => 'Media', 'slug' => 'media-brand']);
    $galleryToken = stageImageFor($this, $admin, 'gallery.png');
    $variantToken = stageImageFor($this, $admin, 'variant.png');

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/products', [
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Staged product',
        'slug' => 'staged-product',
        'variants' => [[
            'name' => '50 ml', 'sku' => 'STAGED-50', 'price' => 100_000, 'weight' => 50,
        ]],
        'images' => [
            ['upload_token' => $galleryToken, 'sort_order' => 0, 'is_primary' => true],
            ['upload_token' => $variantToken, 'variant_index' => 0, 'sort_order' => 1, 'is_primary' => false],
        ],
    ])->assertCreated();

    $product = Product::query()->with(['images', 'variants'])->findOrFail($response->json('data.id'));
    $gallery = $product->images->firstWhere('is_primary', true);
    $variantImage = $product->images->firstWhere('is_primary', false);
    expect($gallery->image_url)->toMatch("~^public/products/{$product->id}/gallery/[0-9a-f-]{36}\\.png$~")
        ->and($variantImage->image_url)->toMatch("~^public/products/{$product->id}/variants/{$product->variants->first()->id}/[0-9a-f-]{36}\\.png$~")
        ->and($variantImage->product_variant_id)->toBe($product->variants->first()->id)
        ->and($variantImage->sort_order)->toBe(1);

    $oldGalleryKey = $gallery->image_url;
    $preservedVariantKey = $variantImage->image_url;
    $replacementToken = stageImageFor($this, $admin, 'replacement.png');
    $this->patchJson("/api/v1/admin/products/{$product->id}", [
        'images' => [
            ['upload_token' => $replacementToken, 'sort_order' => 0, 'is_primary' => true],
            [
                'id' => $variantImage->id,
                'image_url' => 'https://attacker.example/ignored.png',
                'product_variant_id' => $variantImage->product_variant_id,
                'sort_order' => 1,
                'is_primary' => false,
            ],
        ],
    ])->assertOk();

    Storage::disk('public')->assertMissing($oldGalleryKey);
    Storage::disk('public')->assertExists($preservedVariantKey);
    expect(ProductImage::query()->where('image_url', $preservedVariantKey)->exists())->toBeTrue();
});

test('brand logo banner category and admin-managed avatars promote to entity-specific keys', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $branch = stagingBranch();
    $logo = stageImageFor($this, $admin, 'logo.png');
    $banner = stageImageFor($this, $admin, 'banner.png');
    $brandResponse = $this->actingAs($admin)->postJson('/api/v1/admin/brands', [
        'name' => 'Staged brand', 'slug' => 'staged-brand',
        'logo_upload_token' => $logo, 'banner_upload_token' => $banner,
    ])->assertCreated();
    $brand = Brand::query()->findOrFail($brandResponse->json('data.id'));
    expect($brand->logo_url)->toStartWith("public/brands/{$brand->id}/logo/")
        ->and($brand->banner_image)->toStartWith("public/brands/{$brand->id}/banner/")
        ->and($brandResponse->json('data.logo_url'))->toContain('/storage/brands/');
    $oldLogo = $brand->logo_url;
    $this->patchJson("/api/v1/admin/brands/{$brand->id}", [
        'description' => 'Round-trip current media URL',
        'logo_url' => $brandResponse->json('data.logo_url'),
    ])->assertOk();
    expect($brand->refresh()->logo_url)->toBe($oldLogo);
    Storage::disk('public')->assertExists($oldLogo);

    $replacementLogo = stageImageFor($this, $admin, 'logo-replacement.png');
    $this->patchJson("/api/v1/admin/brands/{$brand->id}", [
        'logo_upload_token' => $replacementLogo,
    ])->assertOk();
    Storage::disk('public')->assertMissing($oldLogo);
    Storage::disk('public')->assertExists($brand->refresh()->banner_image);

    Storage::disk('public')->put('legacy/brand-logo.png', 'legacy');
    $legacyBrand = Brand::query()->create([
        'name' => 'Legacy brand', 'slug' => 'legacy-brand', 'logo_url' => 'legacy/brand-logo.png',
    ]);
    $legacyReplacement = stageImageFor($this, $admin, 'legacy-replacement.png');
    $this->patchJson("/api/v1/admin/brands/{$legacyBrand->id}", [
        'logo_upload_token' => $legacyReplacement,
    ])->assertOk();
    Storage::disk('public')->assertExists('legacy/brand-logo.png');

    $categoryToken = stageImageFor($this, $admin, 'category.png');
    $categoryResponse = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Staged category', 'slug' => 'staged-category', 'image_upload_token' => $categoryToken,
    ])->assertCreated();
    $category = Category::query()->findOrFail($categoryResponse->json('data.id'));
    expect($category->image_url)->toStartWith("public/categories/{$category->id}/");
    $oldCategoryImage = $category->image_url;
    $categoryReplacement = stageImageFor($this, $admin, 'category-replacement.png');
    $this->patchJson("/api/v1/admin/categories/{$category->id}", [
        'image_upload_token' => $categoryReplacement,
    ])->assertOk();
    Storage::disk('public')->assertMissing($oldCategoryImage);

    $staffToken = stageImageFor($this, $admin, 'staff.png');
    $staffResponse = $this->postJson('/api/v1/admin/staff', [
        'name' => 'Staged Staff', 'email' => 'staged.staff@example.test', 'password' => 'password123',
        'role' => UserRole::Technician->value, 'branch_id' => $branch->id,
        'avatar_upload_token' => $staffToken,
    ])->assertCreated();
    $staff = User::query()->findOrFail($staffResponse->json('data.id'));
    expect($staff->avatar)->toStartWith("public/users/{$staff->id}/avatar/");
    $oldStaffAvatar = $staff->avatar;
    $staffReplacement = stageImageFor($this, $admin, 'staff-replacement.png');
    $this->patchJson("/api/v1/admin/staff/{$staff->id}", [
        'avatar_upload_token' => $staffReplacement,
    ])->assertOk();
    Storage::disk('public')->assertMissing($oldStaffAvatar);

    $customerToken = stageImageFor($this, $admin, 'customer.png');
    $customerResponse = $this->postJson('/api/v1/admin/customers', [
        'name' => 'Staged Customer', 'email' => 'staged.customer@example.test',
        'avatar_upload_token' => $customerToken,
    ])->assertCreated();
    $customer = User::query()->findOrFail($customerResponse->json('data.id'));
    expect($customer->avatar)->toStartWith("public/users/{$customer->id}/avatar/");
    $oldCustomerAvatar = $customer->avatar;
    $customerReplacement = stageImageFor($this, $admin, 'customer-replacement.png');
    $this->patchJson("/api/v1/admin/customers/{$customer->id}", [
        'avatar_upload_token' => $customerReplacement,
    ])->assertOk();
    Storage::disk('public')->assertMissing($oldCustomerAvatar);
});

test('a post-promotion product failure rolls back entity state and cleans promoted objects', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $category = Category::query()->create(['name' => 'Rollback', 'slug' => 'rollback-category']);
    $brand = Brand::query()->create(['name' => 'Rollback', 'slug' => 'rollback-brand']);
    $otherProduct = Product::query()->create([
        'category_id' => $category->id, 'brand_id' => $brand->id,
        'name' => 'Other', 'slug' => 'other-product',
    ]);
    $foreignVariant = ProductVariant::query()->create([
        'product_id' => $otherProduct->id, 'name' => 'Other', 'sku' => 'FOREIGN-VARIANT',
        'price' => 10_000, 'weight' => 10,
    ]);
    $token = stageImageFor($this, $admin, 'rollback.png');

    $this->actingAs($admin)->postJson('/api/v1/admin/products', [
        'category_id' => $category->id, 'brand_id' => $brand->id,
        'name' => 'Must rollback', 'slug' => 'must-rollback',
        'variants' => [['name' => 'Own', 'sku' => 'OWN-VARIANT', 'price' => 10_000, 'weight' => 10]],
        'images' => [['upload_token' => $token, 'product_variant_id' => $foreignVariant->id]],
    ])->assertUnprocessable();

    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    expect(Product::query()->where('slug', 'must-rollback')->exists())->toBeFalse()
        ->and($upload->status)->toBe(MediaUploadStatus::Deleted)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a storage promotion failure rolls back the entity and leaves no claimed final media', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $token = Str::uuid()->toString();
    $upload = MediaUpload::query()->create([
        'upload_token' => $token,
        'uploaded_by_user_id' => $admin->id,
        'staging_key' => "staging/{$admin->id}/{$token}/".Str::uuid().'.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'bytes' => 10,
        'status' => MediaUploadStatus::Staged,
        'expires_at' => now()->addHour(),
    ]);
    $staging = Mockery::mock(StagingMediaStorageContract::class);
    $staging->shouldReceive('exists')->with($upload->staging_key)->andReturnTrue();
    $staging->shouldReceive('readStream')->once()->andThrow(new RuntimeException('promotion failed'));
    $staging->shouldReceive('delete')->andReturnTrue();
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(fn (string $key): string => $key);
    $publicMedia->shouldReceive('delete')->once()->andReturnTrue();
    $this->app->instance(StagingMediaStorageContract::class, $staging);
    $this->app->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->actingAs($admin)->postJson('/api/v1/admin/categories', [
        'name' => 'Failed promotion', 'slug' => 'failed-promotion',
        'image_upload_token' => $token,
    ])->assertInternalServerError();

    expect(Category::query()->where('slug', 'failed-promotion')->exists())->toBeFalse()
        ->and($upload->refresh()->status)->toBe(MediaUploadStatus::Deleted);
});

test('cleanup command expires abandoned staging while retaining fresh and promoted uploads idempotently', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $expiredToken = stageImageFor($this, $admin, 'expired.png');
    $freshToken = stageImageFor($this, $admin, 'fresh.png');
    $promotedToken = stageImageFor($this, $admin, 'promoted.png');
    MediaUpload::query()->where('upload_token', $expiredToken)->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($admin)->postJson('/api/v1/admin/categories', [
        'name' => 'Promoted', 'slug' => 'promoted-category', 'image_upload_token' => $promotedToken,
    ])->assertCreated();
    $expired = MediaUpload::query()->where('upload_token', $expiredToken)->firstOrFail();
    Storage::disk('local')->delete($expired->staging_key);

    $this->artisan('media:cleanup-staging --batch=1')->assertSuccessful();
    $this->artisan('media:cleanup-staging --batch=1')->assertSuccessful();

    expect(MediaUpload::query()->where('upload_token', $expiredToken)->value('status'))->toBe(MediaUploadStatus::Expired)
        ->and(MediaUpload::query()->where('upload_token', $freshToken)->value('status'))->toBe(MediaUploadStatus::Staged)
        ->and(MediaUpload::query()->where('upload_token', $promotedToken)->value('status'))->toBe(MediaUploadStatus::Promoted);
});
