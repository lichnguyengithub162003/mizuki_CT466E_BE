<?php

namespace App\Services\Media;

use App\Support\MediaUrl;

/**
 * Resolves the new object-key contract while preserving the existing mixed
 * legacy URL/path behavior. It intentionally does not replace MediaUrl yet.
 */
final class MediaUrlResolver
{
    public function __construct(
        private readonly LocalPublicMediaService $localPublicMedia,
        private readonly CloudinaryPublicMediaService $cloudinaryPublicMedia,
        private readonly MediaUrl $legacy,
        private readonly MediaRenditionPresets $renditions,
    ) {}

    public function resolvePublic(?string $value, ?string $preset = null): ?string
    {
        $this->renditions->cloudinaryTransformation($preset);

        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'private/') || str_starts_with($value, 'staging/')) {
            return null;
        }

        if (str_starts_with($value, 'cloudinary:')) {
            return $this->cloudinaryPublicMedia->url($value, $preset);
        }

        if (str_starts_with($value, 'public/')) {
            return $this->localPublicMedia->url($value, $preset);
        }

        return $this->legacy->resolve($value);
    }
}
