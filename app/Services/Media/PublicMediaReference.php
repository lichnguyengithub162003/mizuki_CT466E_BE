<?php

namespace App\Services\Media;

use InvalidArgumentException;

final class PublicMediaReference
{
    private const LOCAL_PATTERN = '~^public/(?<path>(?:(?:products/\d+/(?:gallery|variants/\d+)|brands/\d+/(?:logo|banner)|categories/\d+|users/\d+/avatar)|system/smoke)/[0-9a-f-]{36})\.(?:jpg|png|webp)$~i';

    private const CLOUDINARY_PATTERN = '~^cloudinary:(?<public_id>mizuki/(?:(?:products/\d+/(?:gallery|variants/\d+)|brands/\d+/(?:logo|banner)|categories/\d+|users/\d+/avatar)|system/smoke)/[0-9a-f-]{36})$~i';

    public function cloudinaryFromLocal(string $key): string
    {
        if (preg_match(self::LOCAL_PATTERN, $key, $matches) !== 1) {
            throw new InvalidArgumentException('Cloudinary uploads require an app-owned public image key.');
        }

        return 'cloudinary:mizuki/'.$matches['path'];
    }

    public function cloudinaryPublicId(string $reference): string
    {
        if (preg_match(self::CLOUDINARY_PATTERN, $reference, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid or unsupported Cloudinary media reference.');
        }

        return $matches['public_id'];
    }

    public function isCloudinary(string $reference): bool
    {
        return preg_match(self::CLOUDINARY_PATTERN, $reference) === 1;
    }

    public function isOwned(string $reference): bool
    {
        return preg_match(self::LOCAL_PATTERN, $reference) === 1 || $this->isCloudinary($reference);
    }
}
