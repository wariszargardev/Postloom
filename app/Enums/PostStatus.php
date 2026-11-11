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
     * Can be dragged to a new time on the calendar.
     */
    public function isMovable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    /**
     * Calendar colour name, mapped to classes in the calendar page.
     */
    public function calendarColor(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Scheduled => 'scheduled',
            self::Publishing => 'publishing',
            self::Published => 'published',
            self::PartlyPublished => 'partly',
            self::Failed => 'failed',
        };
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
