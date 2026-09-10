<?php

namespace App\Enums;

enum StaffEmploymentStatus: string
{
    case Working = 'working';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Working => 'Đang làm việc',
            self::Left => 'Đã nghỉ việc',
        };
    }
}
