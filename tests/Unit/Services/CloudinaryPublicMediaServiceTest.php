<?php

use App\Services\Media\CloudinaryClientContract;
use App\Services\Media\CloudinaryPublicMediaService;
use App\Services\Media\LocalPublicMediaService;
use App\Services\Media\PublicMediaServiceContract;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set([
        'media.public_disk' => 'public',
        'media.cloudinary_url' => 'cloudinary://key:secret@demo-cloud',
    ]);
});

test('public media binding defaults to local and selects cloudinary explicitly', function (): void {
    config()->set('media.public_driver', 'local');
    expect(app(PublicMediaServiceContract::class))->toBeInstanceOf(LocalPublicMediaService::class);

    config()->set('media.public_driver', 'cloudinary');
    expect(app(PublicMediaServiceContract::class))->toBeInstanceOf(CloudinaryPublicMediaService::class);
});

test('cloudinary upload uses a deterministic owned public id and returns canonical provider metadata', function (): void {
    $uuid = Str::uuid()->toString();
    $key = "public/products/42/gallery/{$uuid}.png";
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->once()->withArgs(function ($contents, array $options) use ($uuid): bool {
        expect($contents)->toBeResource()
            ->and($options['public_id'])->toBe("mizuki/products/42/gallery/{$uuid}")
            ->and($options['resource_type'])->toBe('image')
            ->and($options['overwrite'])->toBeFalse();

        return true;
    })->andReturn([
        'public_id' => "mizuki/products/42/gallery/{$uuid}",
        'resource_type' => 'image',
        'format' => 'webp',
        'bytes' => 321,
        'width' => 640,
        'height' => 480,
    ]);
    app()->instance(CloudinaryClientContract::class, $client);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'image-bytes');
    rewind($stream);

    try {
        $object = app(CloudinaryPublicMediaService::class)->put($key, $stream, 'image/png', 'source.png');
    } finally {
        fclose($stream);
    }

    expect($object->key)->toBe("cloudinary:mizuki/products/42/gallery/{$uuid}")
        ->and($object->extension)->toBe('webp')
        ->and($object->bytes)->toBe(321)
        ->and($object->width)->toBe(640)
        ->and($object->height)->toBe(480);
});

test('cloudinary delivery URL is pure secure optimized and unresized', function (): void {
    $reference = 'cloudinary:mizuki/brands/7/logo/550e8400-e29b-41d4-a716-446655440000';

    expect(app(CloudinaryPublicMediaService::class)->url($reference))
        ->toBe('https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/mizuki/brands/7/logo/550e8400-e29b-41d4-a716-446655440000')
        ->not->toContain('w_')
        ->not->toContain('h_');
});

test('every fixed rendition preset produces the expected pure delivery transformation', function (): void {
    $reference = 'cloudinary:mizuki/products/42/gallery/550e8400-e29b-41d4-a716-446655440000';
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldNotReceive('upload');
    $client->shouldNotReceive('destroy');
    app()->instance(CloudinaryClientContract::class, $client);
    $service = app(CloudinaryPublicMediaService::class);
    $expected = [
        'thumb' => 'c_fill,g_auto,h_160,w_160',
        'card' => 'c_fill,g_auto,h_480,w_480',
        'detail' => 'c_limit,h_1200,w_1200',
        'avatar' => 'c_fill,g_auto:faces,h_256,w_256',
        'brand_logo' => 'c_fit,h_160,w_320',
        'brand_banner' => 'c_fill,g_auto,h_600,w_1600',
        'category' => 'c_fill,g_auto,h_640,w_640',
    ];

    foreach ($expected as $preset => $transformation) {
        expect($service->url($reference, $preset))->toBe(
            "https://res.cloudinary.com/demo-cloud/image/upload/{$transformation}/f_auto/q_auto/mizuki/products/42/gallery/550e8400-e29b-41d4-a716-446655440000",
        );
    }
});

test('cloudinary failures are surfaced and missing configuration fails only when used', function (): void {
    $key = 'public/categories/3/550e8400-e29b-41d4-a716-446655440000.png';
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->once()->andThrow(new RuntimeException('provider unavailable'));
    app()->instance(CloudinaryClientContract::class, $client);

    expect(fn () => app(CloudinaryPublicMediaService::class)->put($key, 'bytes', 'image/png'))
        ->toThrow(RuntimeException::class, 'provider unavailable');

    config()->set('media.cloudinary_url');
    expect(fn () => app(CloudinaryPublicMediaService::class)->url(
        'cloudinary:mizuki/categories/3/550e8400-e29b-41d4-a716-446655440000',
    ))->toThrow(RuntimeException::class, 'CLOUDINARY_URL');
});

test('cloudinary deletion is idempotent and never sends local external or malformed references to destroy', function (): void {
    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('destroy')->once()->with(
        'mizuki/users/9/avatar/550e8400-e29b-41d4-a716-446655440000',
        ['resource_type' => 'image', 'type' => 'upload', 'invalidate' => true],
    )->andReturn(['result' => 'not found']);
    app()->instance(CloudinaryClientContract::class, $client);
    $service = app(CloudinaryPublicMediaService::class);

    expect($service->delete('cloudinary:mizuki/users/9/avatar/550e8400-e29b-41d4-a716-446655440000'))->toBeTrue()
        ->and($service->delete('public/categories/3/550e8400-e29b-41d4-a716-446655440000.png'))->toBeTrue();

    expect(fn () => $service->delete('https://example.test/image.png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->delete('cloudinary:mizuki/categories/3/not-a-uuid'))->toThrow(InvalidArgumentException::class);
});
