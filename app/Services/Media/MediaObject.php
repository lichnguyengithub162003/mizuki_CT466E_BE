<?php

namespace App\Services\Media;

final readonly class MediaObject
{
    public function __construct(
        public string $key,
        public MediaVisibility $visibility,
        public ?string $mimeType,
        public string $extension,
        public ?int $bytes,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $originalName = null,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'visibility' => $this->visibility->value,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
            'original_name' => $this->originalName,
        ];
    }
}
