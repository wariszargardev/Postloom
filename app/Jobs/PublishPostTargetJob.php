<?php

namespace App\Jobs;

use App\Enums\LogFeature;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishAttemptStatus;
use App\Enums\SocialAccountStatus;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Services\LogService;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishPostTargetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 540;

    public int $uniqueFor = 900;

    /**
     * Seconds to wait before the 2nd and 3rd try.
     *
     * @var array<int, int>
     */
    public const RETRY_DELAYS = [60, 300];

    public function __construct(public int $postTargetId) {}

    public function uniqueId(): string
    {
        return (string) $this->postTargetId;
    }

    public function handle(PublisherRegistry $publisherRegistry, PostStatusResolver $postStatusResolver): void
    {
        $target = PostTarget::query()->with(['post', 'socialAccount'])->find($this->postTargetId);

        if ($target === null || $target->post->status !== PostStatus::Publishing || $target->status === PostTargetStatus::Published) {
            return;
        }

        $attemptNumber = $this->attempts();
        $account = $target->socialAccount;

        LogService::startFeatureLogging(LogFeature::Publishing, [
            'workspace_id' => $target->post->workspace_id,
            'post_id' => $target->post_id,
            'target_id' => $target->id,
            'provider' => $account->provider->value,
        ]);
        LogService::info('Publishing to account', ['account_id' => $account->id, 'attempt' => $attemptNumber]);

        $target->update(['status' => PostTargetStatus::Publishing, 'attempts' => $attemptNumber]);
        $startedAt = now();
        $attempt = $target->publishAttempts()->create([
            'attempt' => $attemptNumber,
            'status' => PublishAttemptStatus::Running,
            'started_at' => $startedAt,
        ]);

        try {
            if ($account->status() === SocialAccountStatus::ReconnectNeeded) {
                throw PublishException::permanent("Reconnect {$account->name} on the Accounts page, then retry.");
            }

            $publisher = $publisherRegistry->for($account->provider)
                ?? throw PublishException::permanent("Posting to {$account->provider->label()} is not available yet.");

            $result = $publisher->publish($target);

            $this->finishAttempt($attempt, PublishAttemptStatus::Succeeded, null, $result->externalId);
            $target->update([
                'status' => PostTargetStatus::Published,
                'external_id' => $result->externalId,
                'external_url' => $result->externalUrl,
                'published_at' => now(),
                'last_error' => null,
            ]);
            $account->update(['last_used_at' => now()]);

            LogService::info('Published to account', [
                'account_id' => $account->id,
                'external_id' => $result->externalId,
                'duration_ms' => $attempt->duration_ms,
            ]);
        } catch (Throwable $exception) {
            $isRetryable = $exception instanceof PublishException ? $exception->isRetryable : true;
            $this->finishAttempt($attempt, PublishAttemptStatus::Failed, $exception, null, $isRetryable);

            if ($isRetryable && $attemptNumber < $this->tries) {
                $delay = self::RETRY_DELAYS[min($attemptNumber, count(self::RETRY_DELAYS)) - 1];
                $target->update(['status' => PostTargetStatus::Publishing, 'last_error' => $exception->getMessage()]);

                LogService::warning('Publishing failed, will retry', ['attempt' => $attemptNumber, 'retry_in_seconds' => $delay], $exception);

                $this->release($delay);

                return;
            }

            $target->update(['status' => PostTargetStatus::Failed, 'last_error' => $exception->getMessage()]);

            LogService::error('Publishing failed for good', ['attempt' => $attemptNumber, 'retryable' => $isRetryable], $exception);
        }

        $postStatusResolver->resolve($target->post);
    }

    public function failed(?Throwable $exception): void
    {
        $target = PostTarget::query()->with('post')->find($this->postTargetId);

        if ($target === null || $target->status === PostTargetStatus::Published) {
            return;
        }

        $target->update([
            'status' => PostTargetStatus::Failed,
            'last_error' => $exception?->getMessage() ?? 'The publish job stopped unexpectedly.',
        ]);

        LogService::startFeatureLogging(LogFeature::Publishing, ['post_id' => $target->post_id, 'target_id' => $target->id]);
        LogService::error('Publish job failed outside the normal flow', [], $exception);

        app(PostStatusResolver::class)->resolve($target->post);
    }

    private function finishAttempt(
        PublishAttempt $attempt,
        PublishAttemptStatus $status,
        ?Throwable $exception,
        ?string $externalId,
        bool $isRetryable = false,
    ): void {
        $finishedAt = now();

        $attempt->update([
            'status' => $status,
            'retryable' => $isRetryable,
            'error_message' => $exception?->getMessage(),
            'error_class' => $exception === null ? null : $exception::class,
            'external_id' => $externalId,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $attempt->started_at->diffInMilliseconds($finishedAt),
        ]);
    }
}
