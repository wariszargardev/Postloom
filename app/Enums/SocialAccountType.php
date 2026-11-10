<?php

namespace App\Enums;

enum SocialAccountType: string
{
    case Profile = 'profile';
    case Page = 'page';
    case Channel = 'channel';

    public function label(): string
    {
        return match ($this) {
            self::Profile => 'Profile',
            self::Page => 'Page',
            self::Channel => 'Channel',
        };
    }
}
