<?php

namespace App\Enums;

enum BranchStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Đang hoạt động',
            self::Suspended => 'Tạm ngưng',
            self::Inactive => 'Ngừng hoạt động',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public static function fromLegacyIsActive(bool $isActive): self
    {
        return $isActive ? self::Active : self::Inactive;
    }
}
