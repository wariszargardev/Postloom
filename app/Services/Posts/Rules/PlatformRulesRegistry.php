<?php

namespace App\Services\Posts\Rules;

use App\Enums\SocialProvider;

class PlatformRulesRegistry
{
    public function __construct(
        private LinkedInPostRules $linkedInPostRules,
        private YouTubePostRules $youTubePostRules,
        private FacebookPostRules $facebookPostRules,
        private TikTokPostRules $tikTokPostRules,
    ) {}

    public function forProvider(SocialProvider $provider): ?PlatformPostRules
    {
        return match ($provider) {
            SocialProvider::LinkedIn => $this->linkedInPostRules,
            SocialProvider::YouTube => $this->youTubePostRules,
            SocialProvider::Facebook => $this->facebookPostRules,
            SocialProvider::TikTok => $this->tikTokPostRules,
            default => null,
        };
    }
}
