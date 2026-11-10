<?php

namespace App\Services\Publishing;

use App\Enums\SocialProvider;
use App\Models\PostTarget;

interface Publisher
{
    public function provider(): SocialProvider;

    /**
     * Send one post to one account. Throw PublishException::retryable() for temporary problems
     * (network, 5xx, rate limit) and PublishException::permanent() for problems a retry can't fix.
     *
     * @throws PublishException
     */
    public function publish(PostTarget $target): PublishResult;
}
