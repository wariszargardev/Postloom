<?php

namespace App\Services\TikTok;

use App\Services\Publishing\PublishException;
use Throwable;

class TikTokApiException extends PublishException
{
    public function __construct(
        string $message,
        bool $isRetryable,
        public readonly int $statusCode = 0,
        public readonly string $errorCode = '',
        public readonly ?string $logId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $isRetryable, $previous);
    }

    public function isInvalidToken(): bool
    {
        return in_array($this->errorCode, ['access_token_invalid', 'scope_not_authorized'], true) || $this->statusCode === 401;
    }
}
