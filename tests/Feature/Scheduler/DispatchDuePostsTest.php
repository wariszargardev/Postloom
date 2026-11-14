<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Publishing\PostDispatcher;
use App\Services\Publishing\QueuedPublishJobs;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-12 08:00:00');
    config(['postloom.publishing.enabled' => true]);
    Queue::fake();

    $this->workspace = Workspace::factory()->create();
});

function duePost(CarbonInterface $scheduledAt, int $accounts = 2): Post
{
    $post = Post::factory()->for(test()->workspace)->create([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => $scheduledAt,
    ]);
    PostTarget::factory()->count($accounts)->for($post)->create();

    return $post;
}

it('queues one job per account for posts whose time has come', function () {
    $post = duePost(now()->subMinute());

    $this->artisan('posts:dispatch-due')
        ->expectsOutput('Dispatched: 1. Missed: 0. Timed out: 0.')
        ->assertSuccessful();

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertPushed(PublishPostTargetJob::class, 2);
    $post->targets->each(fn (PostTarget $target) => Queue::assertPushed(
        PublishPostTargetJob::class,
        fn (PublishPostTargetJob $job) => $job->postTargetId === $target->id,
    ));
});

it('never queues the same post twice when runs overlap', function () {
    duePost(now()->subMinute());

    $this->artisan('posts:dispatch-due');
    $this->artisan('posts:dispatch-due');

    Queue::assertPushed(PublishPostTargetJob::class, 2);
});

it('leaves future posts and drafts alone', function () {
    $future = duePost(now()->addMinute());
    $draft = Post::factory()->for($this->workspace)->create(['scheduled_at' => now()->subHour()]);

    $this->artisan('posts:dispatch-due');

    Queue::assertNothingPushed();
    expect($future->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($draft->fresh()->status)->toBe(PostStatus::Draft);
});

it('still sends a post that is less than 6 hours late', function () {
    $post = duePost(now()->subHours(5)->subMinutes(59));

    $this->artisan('posts:dispatch-due');

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertPushed(PublishPostTargetJob::class, 2);
});

it('marks a post more than 6 hours late as missed instead of sending it', function () {
    $post = duePost(now()->subHours(6)->subMinute());

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 1. Timed out: 0.');

    Queue::assertNothingPushed();
    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($post->targets()->pluck('last_error')->unique()->all())->toBe([PostDispatcher::MISSED_MESSAGE])
        ->and($post->targets()->pluck('status')->unique()->all())->toBe([PostTargetStatus::Failed]);
});

it('times out accounts stuck in publishing for 30 minutes', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now()->subHour()]);
    $stuck = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Publishing]);
    PostTarget::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(31)]);

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 0. Timed out: 1.');

    expect($stuck->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($stuck->fresh()->last_error)->toBe(PostDispatcher::TIMED_OUT_MESSAGE)
        ->and($post->fresh()->status)->toBe(PostStatus::Failed);
});

it('keeps a missed post partly published when an earlier run already reached an account', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->subHours(7)]);
    $published = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published]);
    $waiting = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending]);

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 1. Timed out: 0.');

    expect($post->fresh()->status)->toBe(PostStatus::PartlyPublished)
        ->and($published->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($waiting->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($waiting->fresh()->last_error)->toBe(PostDispatcher::MISSED_MESSAGE);
});

it('does not time out a retry that is still waiting in the queue', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now()->subHour()]);
    $waitingRetry = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Publishing]);
    PostTarget::query()->whereKey($waitingRetry->id)->update(['updated_at' => now()->subMinutes(45)]);
    $this->mock(QueuedPublishJobs::class)->shouldReceive('targetIds')->andReturn([$waitingRetry->id]);

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 0. Timed out: 0.');

    expect($waitingRetry->fresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing);
});

it('times out a waiting account whose job was lost', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now()->subHour()]);
    $lost = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending]);
    PostTarget::query()->whereKey($lost->id)->update(['updated_at' => now()->subMinutes(31)]);

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 0. Timed out: 1.');

    expect($lost->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($lost->fresh()->last_error)->toBe(PostDispatcher::TIMED_OUT_MESSAGE)
        ->and($post->fresh()->status)->toBe(PostStatus::Failed);
});

it('leaves accounts of a scheduled post alone however old they are', function () {
    $post = duePost(now()->addHour(), 1);
    PostTarget::query()->where('post_id', $post->id)->update(['updated_at' => now()->subDays(2)]);

    $this->artisan('posts:dispatch-due')->expectsOutput('Dispatched: 0. Missed: 0. Timed out: 0.');

    expect($post->targets()->sole()->status)->toBe(PostTargetStatus::Pending);
});

it('does nothing while publishing is turned off', function () {
    config(['postloom.publishing.enabled' => false]);
    $post = duePost(now()->subMinute());

    $this->artisan('posts:dispatch-due')
        ->expectsOutput('Publishing is turned off (POSTLOOM_PUBLISHING_ENABLED=false). Due posts stay scheduled.');

    Queue::assertNothingPushed();
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

it('runs every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('posts:dispatch-due')->assertSuccessful();
});
