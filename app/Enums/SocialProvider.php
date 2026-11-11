<?php

namespace App\Enums;

use App\Services\Facebook\FacebookConnectService;
use App\Services\LinkedIn\LinkedInConnectService;
use App\Services\TikTok\TikTokConnectService;
use App\Services\YouTube\YouTubeConnectService;

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
            self::YouTube => YouTubeConnectService::isConfigured(),
            self::Facebook => FacebookConnectService::isConfigured(),
            self::TikTok => TikTokConnectService::isConfigured(),
            default => false,
        };
    }

    public function availabilityNote(): string
    {
        return match ($this) {
            self::LinkedIn => $this->isAvailable()
                ? 'Personal profile'
                : 'Add LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET to .env',
            self::YouTube => $this->isAvailable()
                ? 'Channel'
                : 'Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env',
            self::Facebook => $this->isAvailable()
                ? 'Pages you manage'
                : 'Add FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET to .env',
            self::TikTok => $this->isAvailable()
                ? 'Profile (videos)'
                : 'Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env',
            default => 'Coming later',
        };
    }

    public function connectRouteName(): ?string
    {
        return match ($this) {
            self::LinkedIn => 'accounts.linkedin.redirect',
            self::YouTube => 'accounts.youtube.redirect',
            self::Facebook => 'accounts.facebook.redirect',
            self::TikTok => 'accounts.tiktok.redirect',
            default => null,
        };
    }
}
