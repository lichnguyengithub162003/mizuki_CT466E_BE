<?php

namespace App\Services\Media;

use InvalidArgumentException;

final class MediaRenditionPresets
{
    /** @var array<string, string> */
    private const CLOUDINARY_TRANSFORMATIONS = [
        'thumb' => 'c_fill,g_auto,h_160,w_160',
        'card' => 'c_fill,g_auto,h_480,w_480',
        'detail' => 'c_limit,h_1200,w_1200',
        'avatar' => 'c_fill,g_auto:faces,h_256,w_256',
        'brand_logo' => 'c_fit,h_160,w_320',
        'brand_banner' => 'c_fill,g_auto,h_600,w_1600',
        'category' => 'c_fill,g_auto,h_640,w_640',
    ];

    public function cloudinaryTransformation(?string $preset): ?string
    {
        if ($preset === null) {
            return null;
        }

        if (! array_key_exists($preset, self::CLOUDINARY_TRANSFORMATIONS)) {
            throw new InvalidArgumentException("Unknown public media rendition preset [{$preset}].");
        }

        return self::CLOUDINARY_TRANSFORMATIONS[$preset];
    }
}
