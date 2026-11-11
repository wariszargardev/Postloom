<?php

namespace App\Enums;

enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case ReadyToPost = 'ready_to_post';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::ReadyToPost => 'Ready for you',
            self::Failed => 'Failed',
        };
    }
}
