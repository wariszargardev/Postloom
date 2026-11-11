<?php

namespace App\Exceptions;

use RuntimeException;

class FacebookConnectException extends RuntimeException
{
    public static function postingPermissionMissing(): self
    {
        return new self('Facebook did not allow posting to Pages. Connect again and keep "Create and manage content on your Page" switched on.');
    }

    public static function noPages(): self
    {
        return new self('No Facebook Page was shared. Connect again and tick the Pages Postloom may post to (you must manage them).');
    }
}
