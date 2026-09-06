<?php

use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->mediaResolverRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mizuki-resolver-'.Str::uuid();
    config([
        'app.url' => 'http://localhost:8000',
        'filesystems.disks.public.driver' => 'local',
        'filesystems.disks.public.root' => $this->mediaResolverRoot,
        'filesystems.disks.public.url' => 'http://localhost:8000/storage',
        'media.public_disk' => 'public',
        'media.private_disk' => 'local',
    ]);
    Storage::forgetDisk('public');
    Storage::disk('public')->put('catalog/products/legacy/item.jpg', 'legacy');
});

afterEach(function (): void {
    Storage::forgetDisk('public');
    File::deleteDirectory($this->mediaResolverRoot);
});

test('it uses the configured public disk URL in local development', function (): void {
    $key = app(MediaKeyGenerator::class)->avatar(4, 'jpg');

    expect(app(MediaUrlResolver::class)->resolvePublic($key))
        ->toBe(Storage::disk('public')->url($key));
});

test('it preserves external URLs and legacy localhost storage values', function (): void {
    $resolver = app(MediaUrlResolver::class);
    $legacy = 'http://localhost:8000/storage/catalog/products/legacy/item.jpg';

    expect($resolver->resolvePublic('https://lh3.googleusercontent.com/avatar.jpg'))
        ->toBe('https://lh3.googleusercontent.com/avatar.jpg')
        ->and($resolver->resolvePublic($legacy))
        ->toBe(Storage::disk('public')->url('catalog/products/legacy/item.jpg'));
});

test('it resolves a cloudinary canonical reference without a provider lookup', function (): void {
    config()->set('media.cloudinary_url', 'cloudinary://key:secret@demo-cloud');

    expect(app(MediaUrlResolver::class)->resolvePublic(
        'cloudinary:mizuki/categories/3/550e8400-e29b-41d4-a716-446655440000',
    ))->toBe(
        'https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/mizuki/categories/3/550e8400-e29b-41d4-a716-446655440000',
    );
});

test('it resolves a cloudinary canonical reference with a named rendition', function (): void {
    config()->set('media.cloudinary_url', 'cloudinary://key:secret@demo-cloud');

    expect(app(MediaUrlResolver::class)->resolvePublic(
        'cloudinary:mizuki/products/42/gallery/550e8400-e29b-41d4-a716-446655440000',
        'card',
    ))->toBe(
        'https://res.cloudinary.com/demo-cloud/image/upload/c_fill,g_auto,h_480,w_480/f_auto/q_auto/mizuki/products/42/gallery/550e8400-e29b-41d4-a716-446655440000',
    );
});

test('renditions preserve external and legacy local URLs', function (): void {
    $resolver = app(MediaUrlResolver::class);
    $external = 'https://media.example.test/image.webp';
    $legacy = 'http://localhost:8000/storage/catalog/products/legacy/item.jpg';

    expect($resolver->resolvePublic($external, 'card'))->toBe($external)
        ->and($resolver->resolvePublic($legacy, 'thumb'))
        ->toBe(Storage::disk('public')->url('catalog/products/legacy/item.jpg'));
});

test('it rejects unknown rendition presets', function (): void {
    expect(fn () => app(MediaUrlResolver::class)->resolvePublic(
        'cloudinary:mizuki/categories/3/550e8400-e29b-41d4-a716-446655440000',
        'unknown',
    ))->toThrow(InvalidArgumentException::class, 'Unknown public media rendition preset');
});

test('it refuses to expose private or staging keys as public URLs', function (): void {
    $resolver = app(MediaUrlResolver::class);

    expect($resolver->resolvePublic('private/refunds/1/evidence/images/file.jpg'))->toBeNull()
        ->and($resolver->resolvePublic('staging/1/token/file.jpg'))->toBeNull()
        ->and($resolver->resolvePublic('private/refunds/1/evidence/images/file.jpg', 'detail'))->toBeNull()
        ->and($resolver->resolvePublic('staging/1/token/file.jpg', 'thumb'))->toBeNull();

    expect(fn () => $resolver->resolvePublic(
        'cloudinary:mizuki/staging/1/token/550e8400-e29b-41d4-a716-446655440000',
        'thumb',
    ))->toThrow(InvalidArgumentException::class, 'Invalid or unsupported Cloudinary media reference');
});
