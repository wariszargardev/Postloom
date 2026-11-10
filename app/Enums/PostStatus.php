<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartlyPublished = 'partly_published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PartlyPublished => 'Partly published',
            self::Failed => 'Failed',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Publishing;
    }

    /**
     * Statuses that still keep their accounts and media "in use".
     *
     * @return array<int, self>
     */
    public static function upcoming(): array
    {
        return [self::Scheduled, self::Publishing];
    }
}
