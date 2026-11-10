<?php

namespace App\Exceptions;

use RuntimeException;

class LinkedInScopeMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('LinkedIn did not allow posting. Check that the app has the "Share on LinkedIn" product, then connect again.');
    }
}
