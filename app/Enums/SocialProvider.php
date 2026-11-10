<?php

namespace App\Enums;

enum SocialProvider: string
{
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::YouTube => 'YouTube',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function availabilityNote(): string
    {
        return match ($this) {
            self::LinkedIn => 'Coming in piece 03',
            default => 'Coming later',
        };
    }
}
