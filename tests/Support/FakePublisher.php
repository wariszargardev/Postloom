<?php

namespace Tests\Support;

use App\Enums\SocialProvider;
use App\Models\PostTarget;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Throwable;

class FakePublisher implements Publisher
{
    /**
     * @var array<int, int>
     */
    public array $publishedTargetIds = [];

    /**
     * Outcomes to return in order; once used up, every call succeeds.
     *
     * @param  array<int, Throwable|PublishResult>  $outcomes
     */
    public function __construct(
        private SocialProvider $provider = SocialProvider::LinkedIn,
        private array $outcomes = [],
    ) {}

    public function provider(): SocialProvider
    {
        return $this->provider;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $outcome = array_shift($this->outcomes) ?? new PublishResult(
            "urn:li:share:{$target->id}",
            "https://www.linkedin.com/feed/update/urn:li:share:{$target->id}/",
        );

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        $this->publishedTargetIds[] = $target->id;

        return $outcome;
    }

    public static function failingWith(string $message, bool $isRetryable, SocialProvider $provider = SocialProvider::LinkedIn): self
    {
        $exception = $isRetryable ? PublishException::retryable($message) : PublishException::permanent($message);

        return new self($provider, [$exception, $exception, $exception]);
    }
}
