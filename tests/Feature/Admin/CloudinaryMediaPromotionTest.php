<?php

use App\Enums\MediaUploadStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\Media\CloudinaryClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set([
        'media.public_driver' => 'cloudinary',
        'media.public_disk' => 'public',
        'media.private_disk' => 'local',
        'media.cloudinary_url' => 'cloudinary://key:secret@demo-cloud',
    ]);
});

function cloudinaryStagingImage(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
}

function cloudinaryStageImage(object $test, User $admin, string $name): string
{
    return (string) $test->actingAs($admin)->post(
        '/api/v1/admin/media/images',
        ['image' => cloudinaryStagingImage($name)],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.upload_token');
}

test('staged image promotes to a canonical cloudinary reference and stores provider metadata', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $token = cloudinaryStageImage($this, $admin, 'category.png');
    $stagedKey = MediaUpload::query()->where('upload_token', $token)->value('staging_key');
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->once()->andReturnUsing(function ($contents, array $options): array {
        expect($contents)->toBeResource()
            ->and($options['public_id'])->toMatch('~^mizuki/categories/\d+/[0-9a-f-]{36}$~');

        return [
            'public_id' => $options['public_id'],
            'resource_type' => 'image',
            'format' => 'png',
            'bytes' => 456,
            'width' => 20,
            'height' => 30,
        ];
    });
    app()->instance(CloudinaryClientContract::class, $client);

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/categories', [
        'name' => 'Cloud category',
        'slug' => 'cloud-category',
        'image_upload_token' => $token,
    ])->assertCreated();

    $category = Category::query()->findOrFail($response->json('data.id'));
    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    expect($category->image_url)->toMatch("~^cloudinary:mizuki/categories/{$category->id}/[0-9a-f-]{36}$~")
        ->and($response->json('data.image_url'))->toStartWith('https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/')
        ->and($upload->final_key)->toBe($category->image_url)
        ->and($upload->status)->toBe(MediaUploadStatus::Promoted)
        ->and($upload->bytes)->toBe(456)
        ->and($upload->width)->toBe(20)
        ->and($upload->height)->toBe(30);
    Storage::disk('local')->assertMissing($stagedKey);
    expect(Storage::disk('public')->allFiles())->toBe([]);

    $this->patchJson("/api/v1/admin/categories/{$category->id}", [
        'description' => 'Canonical round trip',
        'image_url' => $response->json('data.image_url'),
    ])->assertOk();
    expect($category->refresh()->image_url)->toBe($upload->final_key);
});

test('provider upload failure rolls back entity and leaves no saved final reference', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $token = cloudinaryStageImage($this, $admin, 'failure.png');
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->once()->andThrow(new RuntimeException('cloudinary unavailable'));
    $client->shouldReceive('destroy')->once()->andReturn(['result' => 'not found']);
    app()->instance(CloudinaryClientContract::class, $client);

    $this->actingAs($admin)->postJson('/api/v1/admin/categories', [
        'name' => 'Must rollback',
        'slug' => 'cloudinary-must-rollback',
        'image_upload_token' => $token,
    ])->assertInternalServerError();

    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    expect(Category::query()->where('slug', 'cloudinary-must-rollback')->exists())->toBeFalse()
        ->and($upload->status)->toBe(MediaUploadStatus::Deleted)
        ->and($upload->final_key)->toStartWith('cloudinary:mizuki/categories/');
    Storage::disk('local')->assertMissing($upload->staging_key);
});

test('replacement deletes an unreferenced owned cloudinary asset only after commit', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $firstToken = cloudinaryStageImage($this, $admin, 'first.png');
    $secondToken = cloudinaryStageImage($this, $admin, 'second.png');
    $destroyed = [];
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->twice()->andReturnUsing(fn ($contents, array $options): array => [
        'public_id' => $options['public_id'],
        'resource_type' => 'image',
        'format' => 'png',
    ]);
    $client->shouldReceive('destroy')->once()->andReturnUsing(function (string $publicId) use (&$destroyed): array {
        $destroyed[] = $publicId;

        return ['result' => 'ok'];
    });
    app()->instance(CloudinaryClientContract::class, $client);

    $created = $this->actingAs($admin)->postJson('/api/v1/admin/brands', [
        'name' => 'Cloud brand',
        'slug' => 'cloud-brand',
        'logo_upload_token' => $firstToken,
    ])->assertCreated();
    $brand = Brand::query()->findOrFail($created->json('data.id'));
    $oldReference = $brand->logo_url;

    $this->patchJson("/api/v1/admin/brands/{$brand->id}", [
        'logo_upload_token' => $secondToken,
    ])->assertOk();

    expect($brand->refresh()->logo_url)->not->toBe($oldReference)
        ->and($destroyed)->toBe([substr($oldReference, strlen('cloudinary:'))]);
});
