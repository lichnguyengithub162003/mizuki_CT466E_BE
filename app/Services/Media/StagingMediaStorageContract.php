<?php

namespace App\Services\Media;

use Symfony\Component\HttpFoundation\StreamedResponse;

interface StagingMediaStorageContract
{
    /** @param array<string, mixed> $options */
    public function put(
        string $key,
        mixed $contents,
        ?string $mimeType = null,
        ?string $originalName = null,
        array $options = [],
    ): MediaObject;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    public function readStream(string $key): mixed;

    public function response(string $key): ?StreamedResponse;
}
