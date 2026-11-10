<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishAttemptStatus;
use App\Enums\SocialProvider;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePublisher;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now()]);
    $this->account = SocialAccount::factory()->for($this->workspace)->create();
    $this->target = PostTarget::factory()->for($this->post)->for($this->account)->create();
    $this->resolver = app(PostStatusResolver::class);
});

function runPublishJob(PostTarget $target, FakePublisher $publisher, int $attempt = 1): PublishPostTargetJob
{
    $job = (new PublishPostTargetJob($target->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(new PublisherRegistry([$publisher]), test()->resolver);

    return $job;
}

it('publishes, saves the link and marks the post published', function () {
    runPublishJob($this->target, new FakePublisher);

    $target = $this->target->fresh();

    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->external_id)->toBe("urn:li:share:{$target->id}")
        ->and($target->external_url)->toContain('linkedin.com/feed/update')
        ->and($target->published_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published)
        ->and($this->account->fresh()->last_used_at)->not->toBeNull();

    $attempt = $target->publishAttempts()->sole();
    expect($attempt->status)->toBe(PublishAttemptStatus::Succeeded)
        ->and($attempt->external_id)->toBe($target->external_id)
        ->and($attempt->finished_at)->not->toBeNull();
});

it('retries a temporary error after 1 minute, then 5 minutes', function () {
    $publisher = FakePublisher::failingWith('LinkedIn returned 503', isRetryable: true);

    runPublishJob($this->target, $publisher, attempt: 1)->assertReleased(delay: 60);
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Publishing);

    runPublishJob($this->target, $publisher, attempt: 2)->assertReleased(delay: 300);
});

it('succeeds on a retry after a temporary error', function () {
    $publisher = new FakePublisher(SocialProvider::LinkedIn, [PublishException::retryable('Timeout')]);

    runPublishJob($this->target, $publisher, attempt: 1);
    runPublishJob($this->target, $publisher, attempt: 2);

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($this->target->fresh()->attempts)->toBe(2)
        ->and($this->target->publishAttempts()->pluck('status')->all())->toBe([PublishAttemptStatus::Failed, PublishAttemptStatus::Succeeded]);
});

it('fails for good after the third temporary error', function () {
    $job = runPublishJob($this->target, FakePublisher::failingWith('LinkedIn returned 503', isRetryable: true), attempt: 3);

    $job->assertNotReleased();
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($this->target->fresh()->last_error)->toBe('LinkedIn returned 503')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

it('fails at once on a permanent error without retrying', function () {
    $job = runPublishJob($this->target, FakePublisher::failingWith('Invalid post content', isRetryable: false));

    $job->assertNotReleased();
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($this->target->publishAttempts()->sole()->retryable)->toBeFalse();
});

it('fails an account that needs reconnecting and still publishes the others', function () {
    $this->account->update(['needs_reconnect' => true]);
    $second = PostTarget::factory()->for($this->post)->for(SocialAccount::factory()->for($this->workspace)->create())->create();
    $publisher = new FakePublisher;

    runPublishJob($this->target, $publisher);
    runPublishJob($second, $publisher);

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($this->target->fresh()->last_error)->toContain('Reconnect')
        ->and($second->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($this->post->fresh()->status)->toBe(PostStatus::PartlyPublished)
        ->and($publisher->publishedTargetIds)->toBe([$second->id]);
});

it('fails clearly when the platform has no publisher yet', function () {
    runPublishJob($this->target, new FakePublisher(SocialProvider::YouTube));

    expect($this->target->fresh()->last_error)->toBe('Posting to LinkedIn is not available yet.');
});

it('keeps the post publishing until every account has finished', function () {
    PostTarget::factory()->for($this->post)->create();

    runPublishJob($this->target, new FakePublisher);

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing);
});

it('does not publish an account twice', function () {
    $publisher = new FakePublisher;

    runPublishJob($this->target, $publisher);
    runPublishJob($this->target, $publisher);

    expect($publisher->publishedTargetIds)->toBe([$this->target->id])
        ->and($this->target->publishAttempts()->count())->toBe(1);
});
