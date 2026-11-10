<?php

namespace App\Enums;

enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }
}
