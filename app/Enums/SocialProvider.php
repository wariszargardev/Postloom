<?php

namespace App\Enums;

use App\Services\LinkedIn\LinkedInConnectService;

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
        return match ($this) {
            self::LinkedIn => LinkedInConnectService::isConfigured(),
            default => false,
        };
    }

    public function availabilityNote(): string
    {
        return match ($this) {
            self::LinkedIn => $this->isAvailable()
                ? 'Personal profile'
                : 'Add LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET to .env',
            default => 'Coming later',
        };
    }

    public function connectRouteName(): ?string
    {
        return match ($this) {
            self::LinkedIn => 'accounts.linkedin.redirect',
            default => null,
        };
    }
}
