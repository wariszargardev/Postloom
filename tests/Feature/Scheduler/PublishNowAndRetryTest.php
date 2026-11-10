<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 12:55:30', 'Asia/Karachi'));
    config(['postloom.publishing.enabled' => true]);
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->account = SocialAccount::factory()->for($this->workspace)->create();
});

function publishNowPayload(): array
{
    return [
        'intent' => 'publish_now',
        'content' => 'Right now',
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => null,
        'targets' => [['social_account_id' => test()->account->id, 'settings' => ['visibility' => 'PUBLIC']]],
        'media_ids' => [],
    ];
}

it('publishes now: schedules for the next minute and queues it straight away', function () {
    $this->actingAs($this->user)
        ->post(route('posts.store'), publishNowPayload())
        ->assertSessionHas('inertia.flash_data.toast.message', 'Publishing now.');

    $post = Post::query()->sole();

    expect($post->status)->toBe(PostStatus::Publishing)
        ->and($post->scheduled_at->setTimezone('Asia/Karachi')->format('H:i'))->toBe('12:56');
    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

it('keeps a publish-now post scheduled while publishing is turned off', function () {
    config(['postloom.publishing.enabled' => false]);

    $this->actingAs($this->user)
        ->post(route('posts.store'), publishNowPayload())
        ->assertSessionHas('inertia.flash_data.toast.message', 'Scheduled for the next minute. Publishing is turned off, so it will wait.');

    expect(Post::query()->sole()->status)->toBe(PostStatus::Scheduled);
    Queue::assertNothingPushed();
});

it('retries only the failed accounts of a partly published post', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::PartlyPublished, 'scheduled_at' => now()->subHour()]);
    $published = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published]);
    $failed = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Failed, 'attempts' => 3, 'last_error' => 'LinkedIn returned 503']);

    $this->actingAs($this->user)->post(route('posts.retry', $post))->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failed->fresh()->status)->toBe(PostTargetStatus::Pending)
        ->and($failed->fresh()->last_error)->toBeNull()
        ->and($published->fresh()->status)->toBe(PostTargetStatus::Published);
    Queue::assertPushed(PublishPostTargetJob::class, fn (PublishPostTargetJob $job) => $job->postTargetId === $failed->id);
    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

it('only retries failed or partly published posts', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create();

    $this->actingAs($this->user)->post(route('posts.retry', $post))->assertSessionHasErrors('post');
});

it('blocks another owner from retrying', function () {
    $post = Post::factory()->create(['status' => PostStatus::Failed]);

    $this->actingAs($this->user)->post(route('posts.retry', $post))->assertForbidden();
});

it('shows delivery details in the editor', function () {
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Failed, 'scheduled_at' => now()->subHour()]);
    PostTarget::factory()->for($post)->for($this->account)->create([
        'status' => PostTargetStatus::Failed,
        'attempts' => 3,
        'last_error' => 'LinkedIn returned 503',
    ]);

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn ($page) => $page
            ->where('post.can_retry', true)
            ->where('post.targets.0.attempts', 3)
            ->where('post.targets.0.last_error', 'LinkedIn returned 503')
            ->where('publishingEnabled', true));
});
