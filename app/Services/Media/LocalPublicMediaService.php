<?php

namespace App\Services\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class LocalPublicMediaService implements PublicMediaServiceContract
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly MediaKeyGenerator $keys,
        private readonly MediaRenditionPresets $renditions,
    ) {}

    /** @param array<string, mixed> $options */
    public function put(
        string $key,
        mixed $contents,
        ?string $mimeType = null,
        ?string $originalName = null,
        array $options = [],
    ): MediaObject {
        $this->assertPublicKey($key);
        $stored = $this->write($key, $contents, $mimeType, $options);

        if (! $stored) {
            throw new RuntimeException('Unable to store the public media object.');
        }

        return new MediaObject(
            key: $key,
            visibility: MediaVisibility::Public,
            mimeType: $mimeType,
            extension: strtolower((string) pathinfo($key, PATHINFO_EXTENSION)),
            bytes: $this->contentBytes($contents),
            originalName: $originalName,
        );
    }

    public function delete(string $key): bool
    {
        $this->assertPublicKey($key);

        return $this->disk()->delete($key);
    }

    public function canonicalReference(string $key): string
    {
        $this->assertPublicKey($key);

        return $key;
    }

    public function exists(string $key): bool
    {
        $this->assertPublicKey($key);

        return $this->disk()->exists($key);
    }

    public function url(string $key, ?string $preset = null): string
    {
        $this->assertPublicKey($key);
        $this->renditions->cloudinaryTransformation($preset);

        return $this->disk()->url($key);
    }

    /** @param array<string, mixed> $options */
    private function write(string $key, mixed $contents, ?string $mimeType, array $options): bool
    {
        if ($mimeType !== null) {
            $options['ContentType'] = $mimeType;
        }

        if (! $contents instanceof UploadedFile) {
            return $this->disk()->put($key, $contents, $options);
        }

        $stream = fopen($contents->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the uploaded public media object.');
        }

        try {
            return $this->disk()->put($key, $stream, $options);
        } finally {
            fclose($stream);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return $this->filesystems->disk((string) config('media.public_disk', 'public'));
    }

    private function assertPublicKey(string $key): void
    {
        $this->keys->assertValid($key);
        if (! str_starts_with($key, 'public/')) {
            throw new RuntimeException('Public media key must use the public/ prefix.');
        }
    }

    private function contentBytes(mixed $contents): ?int
    {
        if (is_string($contents)) {
            return strlen($contents);
        }

        if ($contents instanceof UploadedFile) {
            $size = $contents->getSize();

            return $size === false ? null : $size;
        }

        return null;
    }
}
