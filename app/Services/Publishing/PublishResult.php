<?php

namespace App\Services\Publishing;

final readonly class PublishResult
{
    public function __construct(
        public string $externalId,
        public ?string $externalUrl = null,
        public ?string $notice = null,
    ) {}
}
