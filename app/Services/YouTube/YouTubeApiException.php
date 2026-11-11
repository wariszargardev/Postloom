<?php

namespace App\Services\YouTube;

use App\Services\Publishing\PublishException;
use Throwable;

class YouTubeApiException extends PublishException
{
    public function __construct(
        string $message,
        bool $isRetryable,
        public readonly ?int $statusCode = null,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $isRetryable, $previous);
    }
}
