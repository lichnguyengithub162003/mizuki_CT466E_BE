<?php

namespace App\Services\Media;

use InvalidArgumentException;

final class CloudinarySmokeReference
{
    private const PATTERN = '~^cloudinary:(?<public_id>mizuki/system/smoke/[0-9a-f-]{36})$~i';

    public function publicId(string $reference): string
    {
        if (preg_match(self::PATTERN, $reference, $matches) !== 1) {
            throw new InvalidArgumentException('Refusing to clean up an asset outside the Cloudinary smoke namespace.');
        }

        return $matches['public_id'];
    }
}
