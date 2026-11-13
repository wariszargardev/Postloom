<?php

namespace App\Exceptions;

use RuntimeException;

class InstagramConnectException extends RuntimeException
{
    public static function publishingPermissionMissing(): self
    {
        return new self('Facebook did not allow publishing to Instagram. Connect again and keep every Instagram permission switched on.');
    }

    public static function noAccounts(): self
    {
        return new self('No Instagram professional account was found. Switch the Instagram account to Business or Creator, link it to a Facebook Page, then connect again and tick that Page and account.');
    }
}
