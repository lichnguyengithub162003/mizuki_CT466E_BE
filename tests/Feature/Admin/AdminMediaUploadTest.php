<?php

use App\Enums\UserRole;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('admin staff can upload a validated image', function (UserRole $role): void {
    Storage::fake('local');
    $admin = User::factory()->create(['role' => $role]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $response = $this->actingAs($admin)->postJson('/api/v1/admin/media/images', [
        'image' => UploadedFile::fake()->createWithContent('qa-product.png', $png),
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonStructure(['data' => [
            'upload_token', 'preview_url', 'preview_expires_at', 'expires_at',
            'mime_type', 'bytes', 'width', 'height',
        ]])
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.url');

    $upload = MediaUpload::query()->where('upload_token', $response->json('data.upload_token'))->firstOrFail();
    Storage::disk('local')->assertExists($upload->staging_key);
    expect($upload->uploaded_by_user_id)->toBe($admin->id)
        ->and($upload->staging_key)->toStartWith("staging/{$admin->id}/{$upload->upload_token}/");
})->with([UserRole::SuperAdmin, UserRole::BranchManager]);

test('admin image upload rejects invalid files and unauthorized roles', function (): void {
    Storage::fake('public');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)->postJson('/api/v1/admin/media/images', [
        'image' => UploadedFile::fake()->create('payload.txt', 5, 'text/plain'),
    ])->assertUnprocessable()->assertJsonPath('data.errors.image.0', 'Tệp đã chọn phải là hình ảnh.');

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer)->postJson('/api/v1/admin/media/images', [
        'image' => UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)),
    ])->assertForbidden();
});
