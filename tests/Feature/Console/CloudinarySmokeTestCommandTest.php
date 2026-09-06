<?php

use App\Services\Media\MediaObject;
use App\Services\Media\MediaVisibility;
use App\Services\Media\PublicMediaServiceContract;
use Illuminate\Console\Command;

beforeEach(function (): void {
    config()->set([
        'media.public_driver' => 'cloudinary',
        'media.cloudinary_url' => 'cloudinary://key:secret@demo-cloud',
    ]);
});

test('cloudinary smoke command refuses a non-cloudinary public driver', function (): void {
    config()->set('media.public_driver', 'local');
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldNotReceive('canonicalReference', 'put', 'url', 'delete');
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('MEDIA_PUBLIC_DRIVER must be cloudinary')
        ->assertExitCode(Command::FAILURE);
});

test('cloudinary smoke command refuses missing credentials before media access', function (): void {
    config()->set('media.cloudinary_url', '');
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldNotReceive('canonicalReference', 'put', 'url', 'delete');
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('CLOUDINARY_URL must use')
        ->assertExitCode(Command::FAILURE);
});

test('cloudinary smoke command uses its isolated namespace and cleans a successful upload', function (): void {
    $localKey = null;
    $reference = null;
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(
        function (string $key) use (&$localKey, &$reference): string {
            expect($key)->toMatch('~^public/system/smoke/[0-9a-f-]{36}\.png$~');
            $localKey = $key;
            $reference = 'cloudinary:mizuki/system/smoke/'.pathinfo($key, PATHINFO_FILENAME);

            return $reference;
        },
    );
    $publicMedia->shouldReceive('put')->once()->andReturnUsing(
        function (string $key, $contents, ?string $mimeType, ?string $originalName) use (&$localKey, &$reference): MediaObject {
            expect($key)->toBe($localKey)
                ->and($contents)->toBeResource()
                ->and($mimeType)->toBe('image/png')
                ->and($originalName)->toBe('cloudinary-smoke.png');

            return new MediaObject(
                key: $reference,
                visibility: MediaVisibility::Public,
                mimeType: 'image/png',
                extension: 'png',
                bytes: 68,
                width: 1,
                height: 1,
                originalName: $originalName,
            );
        },
    );
    $publicMedia->shouldReceive('url')->once()->andReturnUsing(
        fn (string $key): string => 'https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/'.substr($key, 11),
    );
    $publicMedia->shouldReceive('delete')->once()->withArgs(
        function (string $key) use (&$reference): bool {
            return $key === $reference;
        },
    )->andReturnTrue();
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('cloudinary:mizuki/system/smoke/')
        ->expectsOutputToContain('Cleanup succeeded.')
        ->assertSuccessful();
});

test('cloudinary smoke command cleans its allocated asset when upload fails', function (): void {
    $reference = null;
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(
        function (string $key) use (&$reference): string {
            $reference = 'cloudinary:mizuki/system/smoke/'.pathinfo($key, PATHINFO_FILENAME);

            return $reference;
        },
    );
    $publicMedia->shouldReceive('put')->once()->andThrow(new RuntimeException('upload unavailable'));
    $publicMedia->shouldReceive('delete')->once()->withArgs(
        function (string $key) use (&$reference): bool {
            return $key === $reference;
        },
    )->andReturnTrue();
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('upload unavailable')
        ->expectsOutputToContain('Cleanup succeeded.')
        ->assertExitCode(Command::FAILURE);
});

test('cloudinary smoke command cleans an uploaded asset when delivery verification fails', function (): void {
    $reference = null;
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(
        function (string $key) use (&$reference): string {
            $reference = 'cloudinary:mizuki/system/smoke/'.pathinfo($key, PATHINFO_FILENAME);

            return $reference;
        },
    );
    $publicMedia->shouldReceive('put')->once()->andReturnUsing(
        function () use (&$reference): MediaObject {
            return new MediaObject(
                key: $reference,
                visibility: MediaVisibility::Public,
                mimeType: 'image/png',
                extension: 'png',
                bytes: 68,
                width: 1,
                height: 1,
            );
        },
    );
    $publicMedia->shouldReceive('url')->once()->andThrow(new RuntimeException('verification failed'));
    $publicMedia->shouldReceive('delete')->once()->withArgs(
        function (string $key) use (&$reference): bool {
            return $key === $reference;
        },
    )->andReturnTrue();
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('verification failed')
        ->expectsOutputToContain('Cleanup succeeded.')
        ->assertExitCode(Command::FAILURE);
});

test('cloudinary smoke command tolerates a provider that consumes and closes its input stream', function (): void {
    $reference = null;
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturnUsing(
        function (string $key) use (&$reference): string {
            $reference = 'cloudinary:mizuki/system/smoke/'.pathinfo($key, PATHINFO_FILENAME);

            return $reference;
        },
    );
    $publicMedia->shouldReceive('put')->once()->andReturnUsing(
        function (string $key, $contents) use (&$reference): MediaObject {
            fclose($contents);

            return new MediaObject(
                key: $reference,
                visibility: MediaVisibility::Public,
                mimeType: 'image/png',
                extension: 'png',
                bytes: 68,
                width: 1,
                height: 1,
            );
        },
    );
    $publicMedia->shouldReceive('url')->once()->andReturnUsing(
        fn (string $key): string => 'https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/'.substr($key, 11),
    );
    $publicMedia->shouldReceive('delete')->once()->withArgs(
        function (string $key) use (&$reference): bool {
            return $key === $reference;
        },
    )->andReturnTrue();
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('Cleanup succeeded.')
        ->assertSuccessful();
});

test('cloudinary smoke command cannot upload or delete outside its smoke namespace', function (): void {
    $publicMedia = Mockery::mock(PublicMediaServiceContract::class);
    $publicMedia->shouldReceive('canonicalReference')->once()->andReturn(
        'cloudinary:mizuki/products/1/gallery/550e8400-e29b-41d4-a716-446655440000',
    );
    $publicMedia->shouldNotReceive('put', 'url', 'delete');
    app()->instance(PublicMediaServiceContract::class, $publicMedia);

    $this->artisan('media:cloudinary-smoke')
        ->expectsOutputToContain('outside the Cloudinary smoke namespace')
        ->assertExitCode(Command::FAILURE);
});
