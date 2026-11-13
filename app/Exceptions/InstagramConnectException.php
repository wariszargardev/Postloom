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
        return new self('No Instagram professional account was found on the Pages you shared. Switch the Instagram account to Business or Creator and link it to one of your Facebook Pages. Then click Connect again, choose "Edit previous settings" and tick that Instagram account and its Page.');
    }
}
