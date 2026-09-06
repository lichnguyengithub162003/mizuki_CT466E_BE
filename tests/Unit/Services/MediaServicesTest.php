<?php

use App\Services\Media\LocalPrivateFileService;
use App\Services\Media\LocalPublicMediaService;
use App\Services\Media\LocalStagingMediaStorage;
use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\MediaVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->mediaTestRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mizuki-media-'.Str::uuid();
    config([
        'filesystems.disks.media-public-test' => [
            'driver' => 'local',
            'root' => $this->mediaTestRoot.DIRECTORY_SEPARATOR.'public',
            'url' => 'http://localhost/storage',
        ],
        'filesystems.disks.media-private-test' => [
            'driver' => 'local',
            'root' => $this->mediaTestRoot.DIRECTORY_SEPARATOR.'private',
        ],
        'media.public_disk' => 'media-public-test',
        'media.private_disk' => 'media-private-test',
    ]);
});

afterEach(function (): void {
    Storage::forgetDisk(['media-public-test', 'media-private-test']);
    File::deleteDirectory($this->mediaTestRoot);
});

test('public media and private business files use separate local services and disks', function (): void {
    $keys = app(MediaKeyGenerator::class);
    $public = app(LocalPublicMediaService::class);
    $private = app(LocalPrivateFileService::class);
    $publicKey = $keys->avatar(8, 'png');
    $privateKey = $keys->refundImage(5, 'jpg');

    $publicObject = $public->put($publicKey, 'public-bytes', 'image/png', 'avatar.png');
    $privateObject = $private->put($privateKey, 'private-bytes', 'image/jpeg', 'proof.jpg');

    Storage::disk('media-public-test')->assertExists($publicKey);
    Storage::disk('media-public-test')->assertMissing($privateKey);
    Storage::disk('media-private-test')->assertExists($privateKey);
    Storage::disk('media-private-test')->assertMissing($publicKey);

    expect($publicObject->visibility)->toBe(MediaVisibility::Public)
        ->and($publicObject->bytes)->toBe(12)
        ->and($privateObject->visibility)->toBe(MediaVisibility::Private)
        ->and($private->exists($privateKey))->toBeTrue()
        ->and($public->url($publicKey))->toBe('http://localhost/storage/'.$publicKey);
});

test('private files stream through the application with no-store headers', function (): void {
    $key = app(MediaKeyGenerator::class)->refundVideo(12, 'mp4');
    $upload = UploadedFile::fake()
        ->createWithContent('proof.mp4', 'original-video-bytes')
        ->mimeType('video/mp4');
    $private = app(LocalPrivateFileService::class);
    $private->put($key, $upload, 'video/mp4', $upload->getClientOriginalName());

    $response = $private->response($key);

    expect(Storage::disk('media-private-test')->get($key))->toBe('original-video-bytes')
        ->and($response)->not->toBeNull()
        ->and($response?->headers->get('Cache-Control'))->toContain('private')
        ->and($response?->headers->get('Cache-Control'))->toContain('no-store');
});

test('staging stays private and can be copied to the transitional public service', function (): void {
    $keys = app(MediaKeyGenerator::class);
    $staging = app(LocalStagingMediaStorage::class);
    $public = app(LocalPublicMediaService::class);
    $stagingKey = $keys->staging(2, 'upload-abc', 'png');
    $destination = $keys->brandLogo(3, 'png');
    $staging->put($stagingKey, 'logo-bytes', 'image/png');

    $stream = $staging->readStream($stagingKey);
    expect($stream)->not->toBeFalse();
    try {
        $stored = $public->put($destination, $stream, 'image/png');
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $staging->delete($stagingKey);

    expect($stored->key)->toBe($destination);
    Storage::disk('media-private-test')->assertMissing($stagingKey);
    Storage::disk('media-public-test')->assertExists($destination);
});

test('storage boundaries reject keys belonging to another media role', function (): void {
    $privateKey = app(MediaKeyGenerator::class)->refundImage(5, 'jpg');

    app(LocalPublicMediaService::class)->url($privateKey);
})->throws(RuntimeException::class);
