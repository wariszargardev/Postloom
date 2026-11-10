<?php

namespace App\Services\LinkedIn;

use App\Services\Publishing\PublishException;
use Throwable;

class LinkedInApiException extends PublishException
{
    public function __construct(
        string $message,
        bool $isRetryable,
        public readonly ?int $statusCode = null,
        public readonly ?string $serviceErrorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $isRetryable, $previous);
    }
}
