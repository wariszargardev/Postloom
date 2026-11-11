<?php

namespace App\Exceptions;

use RuntimeException;

class TikTokConnectException extends RuntimeException
{
    public static function stateMismatch(): self
    {
        return new self('The TikTok login expired. Please connect again.');
    }

    public static function publishScopeMissing(): self
    {
        return new self('TikTok did not allow posting videos. Tick every permission on the TikTok screen, then connect again.');
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self("TikTok did not accept the login ({$reason}). Check TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET and TIKTOK_REDIRECT_URI, then connect again.");
    }

    public static function profileLookupFailed(): self
    {
        return new self('TikTok did not return the account details. Please connect again.');
    }
}
