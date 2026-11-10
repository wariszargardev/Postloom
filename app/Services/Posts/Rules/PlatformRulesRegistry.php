<?php

namespace App\Services\Posts\Rules;

use App\Enums\SocialProvider;

class PlatformRulesRegistry
{
    public function __construct(private LinkedInPostRules $linkedInPostRules) {}

    public function forProvider(SocialProvider $provider): ?PlatformPostRules
    {
        return match ($provider) {
            SocialProvider::LinkedIn => $this->linkedInPostRules,
            default => null,
        };
    }
}
