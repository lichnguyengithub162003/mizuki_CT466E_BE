<?php

namespace App\Services\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LocalPrivateFileService implements PrivateFileServiceContract
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly MediaKeyGenerator $keys,
    ) {}

    /** @param array<string, mixed> $options */
    public function put(
        string $key,
        mixed $contents,
        ?string $mimeType = null,
        ?string $originalName = null,
        array $options = [],
    ): MediaObject {
        $this->assertPrivateKey($key);
        if ($mimeType !== null) {
            $options['ContentType'] = $mimeType;
        }

        $stored = $this->write($key, $contents, $options);
        if (! $stored) {
            throw new RuntimeException('Unable to store the private file.');
        }

        return new MediaObject(
            key: $key,
            visibility: MediaVisibility::Private,
            mimeType: $mimeType,
            extension: strtolower((string) pathinfo($key, PATHINFO_EXTENSION)),
            bytes: $this->contentBytes($contents),
            originalName: $originalName,
        );
    }

    public function delete(string $key): bool
    {
        $this->assertPrivateKey($key);

        return $this->disk()->delete($key);
    }

    public function exists(string $key): bool
    {
        $this->assertPrivateKey($key);

        return $this->disk()->exists($key);
    }

    public function response(string $key): ?StreamedResponse
    {
        $this->assertPrivateKey($key);
        if (! $this->disk()->exists($key)) {
            return null;
        }

        return $this->disk()->response($key, null, self::privateHeaders());
    }

    /** @param array<string, mixed> $options */
    private function write(string $key, mixed $contents, array $options): bool
    {
        if (! $contents instanceof UploadedFile) {
            return $this->disk()->put($key, $contents, $options);
        }

        $stream = fopen($contents->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the uploaded private file.');
        }

        try {
            return $this->disk()->put($key, $stream, $options);
        } finally {
            fclose($stream);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return $this->filesystems->disk((string) config('media.private_disk', 'local'));
    }

    private function assertPrivateKey(string $key): void
    {
        $this->keys->assertValid($key);
        if (! str_starts_with($key, 'private/')) {
            throw new RuntimeException('Private file key must use the private/ prefix.');
        }
    }

    /** @return array<string, string> */
    private static function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
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
