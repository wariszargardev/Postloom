<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Connected = 'connected';
    case ExpiringSoon = 'expiring_soon';
    case ReconnectNeeded = 'reconnect_needed';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::ExpiringSoon => 'Expires soon',
            self::ReconnectNeeded => 'Reconnect needed',
        };
    }
}
