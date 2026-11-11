<?php

namespace App\Services\Facebook;

use App\Services\Publishing\PublishException;
use Throwable;

class FacebookApiException extends PublishException
{
    public function __construct(
        string $message,
        bool $isRetryable,
        public readonly ?int $statusCode = null,
        public readonly ?int $errorCode = null,
        public readonly ?int $errorSubcode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $isRetryable, $previous);
    }

    public function isInvalidToken(): bool
    {
        return $this->errorCode === FacebookGraphClient::INVALID_TOKEN_CODE;
    }
}
