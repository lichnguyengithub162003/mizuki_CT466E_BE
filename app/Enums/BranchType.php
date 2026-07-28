<?php

namespace App\Enums;

enum BranchType: string
{
    case Store = 'store';
    case Clinic = 'clinic';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Store => "C\u{1EED}a h\u{00E0}ng",
            self::Clinic => "Ph\u{00F2}ng kh\u{00E1}m",
            self::Hybrid => "C\u{1EED}a h\u{00E0}ng v\u{00E0} ph\u{00F2}ng kh\u{00E1}m",
        };
    }

    public function supportsRetail(): bool
    {
        return in_array($this, [self::Store, self::Hybrid], true);
    }

    public function supportsClinic(): bool
    {
        return in_array($this, [self::Clinic, self::Hybrid], true);
    }
}
