<?php

namespace App\Services\Publishing;

use RuntimeException;
use Throwable;

class PublishException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $isRetryable, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }
}
