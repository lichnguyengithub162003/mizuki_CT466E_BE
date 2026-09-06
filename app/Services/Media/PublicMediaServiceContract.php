<?php

namespace App\Services\Media;

interface PublicMediaServiceContract
{
    /** @param array<string, mixed> $options */
    public function put(
        string $key,
        mixed $contents,
        ?string $mimeType = null,
        ?string $originalName = null,
        array $options = [],
    ): MediaObject;

    public function canonicalReference(string $key): string;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    public function url(string $key, ?string $preset = null): string;
}
