<?php

namespace App\Exceptions;

use RuntimeException;

class YouTubeConnectException extends RuntimeException
{
    public static function uploadScopeMissing(): self
    {
        return new self('Google did not allow uploading videos. Tick "Manage your YouTube videos" on the Google screen, then connect again.');
    }

    public static function refreshTokenMissing(): self
    {
        return new self('Google did not return a refresh token. Remove Postloom at myaccount.google.com/permissions, then connect again.');
    }

    public static function noChannel(): self
    {
        return new self('This Google account has no YouTube channel. Create one on youtube.com, or pick another account, then connect again.');
    }
}
