<?php

namespace App\Http\Resources\Concerns;

use App\Services\Media\MediaUrlResolver;
use App\Support\MediaUrl;

trait SerializesMedia
{
    protected function mediaUrl(?string $value, ?string $preset = null): ?string
    {
        return app(MediaUrlResolver::class)->resolvePublic($value, $preset);
    }

    protected function brandLogoUrl(?string $value, string $slug, string $name, ?string $preset = null): ?string
    {
        if (is_string($value)
            && (str_starts_with($value, 'public/') || str_starts_with($value, 'cloudinary:'))) {
            return app(MediaUrlResolver::class)->resolvePublic($value, $preset);
        }

        return app(MediaUrl::class)->brandLogo($value, $slug, $name);
    }
}
