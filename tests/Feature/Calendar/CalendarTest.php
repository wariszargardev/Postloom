<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 12:55:00', 'Asia/Karachi'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function eventsBetween(string $start, string $end): TestResponse
{
    return test()->actingAs(test()->user)->getJson(route('calendar.events', ['start' => $start, 'end' => $end]));
}

it('shows the calendar page with its timezone', function () {
    $this->actingAs($this->user)->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->component('calendar/Index')->where('timezone', 'Asia/Karachi'));
});

it('returns posts in the visible range as Karachi wall-clock times', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create([
        'title' => 'Launch post',
        'scheduled_at' => Carbon::parse('2026-11-13 09:30', 'Asia/Karachi'),
    ]);
    PostTarget::factory()->for($post)->for(SocialAccount::factory()->for($this->workspace)->create(['name' => 'Muhammad Waris']))->create();

    eventsBetween('2026-11-09T00:00:00Z', '2026-11-16T00:00:00Z')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', (string) $post->id)
        ->assertJsonPath('0.title', 'Launch post')
        ->assertJsonPath('0.start', '2026-11-13T09:30:00')
        ->assertJsonPath('0.editable', true)
        ->assertJsonPath('0.classNames.1', 'postloom-event--scheduled')
        ->assertJsonPath('0.extendedProps.accounts.0.name', 'Muhammad Waris')
        ->assertJsonPath('0.extendedProps.edit_url', route('posts.edit', $post));
});

it('shows a post saved in another timezone at the matching Karachi time', function () {
    Post::factory()->for($this->workspace)->scheduled()->create([
        'timezone' => 'Europe/London',
        'scheduled_at' => Carbon::parse('2026-11-13 09:00', 'Europe/London'),
    ]);

    eventsBetween('2026-11-09T00:00:00Z', '2026-11-16T00:00:00Z')->assertJsonPath('0.start', '2026-11-13T14:00:00');
});

it('leaves out posts outside the range, without a time, or from another workspace', function () {
    Post::factory()->for($this->workspace)->create(['scheduled_at' => Carbon::parse('2026-12-01 10:00', 'Asia/Karachi')]);
    Post::factory()->for($this->workspace)->create(['scheduled_at' => null]);
    Post::factory()->scheduled()->create(['scheduled_at' => Carbon::parse('2026-11-13 10:00', 'Asia/Karachi')]);

    eventsBetween('2026-11-09T00:00:00Z', '2026-11-16T00:00:00Z')->assertOk()->assertJsonCount(0);
});

it('marks published and publishing posts as not draggable', function () {
    Post::factory()->for($this->workspace)->create([
        'status' => PostStatus::Published,
        'scheduled_at' => Carbon::parse('2026-11-10 10:00', 'Asia/Karachi'),
    ]);

    eventsBetween('2026-11-09T00:00:00Z', '2026-11-16T00:00:00Z')
        ->assertJsonPath('0.editable', false)
        ->assertJsonPath('0.classNames.1', 'postloom-event--published');
});

it('moves a scheduled post and keeps it scheduled', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create();

    $this->actingAs($this->user)
        ->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-12T13:15:00'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Moved to Thu 12 Nov, 1:15 PM.');

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe('2026-11-12 08:15:00')
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

it('refuses to move a post into the past', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create();
    $original = $post->scheduled_at->toDateTimeString();

    $this->actingAs($this->user)
        ->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-12T12:54:00'])
        ->assertSessionHasErrors(['scheduled_at' => 'Pick a date and time in the future.']);

    expect($post->fresh()->scheduled_at->toDateTimeString())->toBe($original);
});

it('refuses to move a post that is publishing or published', function (string $state) {
    $post = $state === 'publishing'
        ? Post::factory()->for($this->workspace)->publishing()->create()
        : Post::factory()->for($this->workspace)->create(['status' => PostStatus::Published, 'scheduled_at' => now()->subHour()]);

    $this->actingAs($this->user)
        ->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-13T10:00:00'])
        ->assertSessionHasErrors('scheduled_at');
})->with(['publishing', 'published']);

it('blocks another owner from moving a post', function () {
    $post = Post::factory()->scheduled()->create();

    $this->actingAs($this->user)
        ->patch(route('posts.reschedule', $post), ['scheduled_at' => '2026-11-13T10:00:00'])
        ->assertForbidden();
});

it('prefills the editor time from a calendar click', function () {
    $this->actingAs($this->user)->get(route('posts.create', ['scheduled_at' => '2026-11-13T09:00']))
        ->assertInertia(fn ($page) => $page->component('posts/Form')->where('prefillScheduledAt', '2026-11-13T09:00'));

    $this->actingAs($this->user)->get(route('posts.create', ['scheduled_at' => 'not-a-date']))
        ->assertInertia(fn ($page) => $page->where('prefillScheduledAt', null));
});

it('always stores scheduled_at in UTC, whatever timezone the date is in', function () {
    $post = Post::factory()->for($this->workspace)->create([
        'scheduled_at' => Carbon::parse('2026-11-13 09:30', 'Asia/Karachi'),
    ]);

    expect($post->fresh()->getRawOriginal('scheduled_at'))->toBe('2026-11-13 04:30:00');
});

it('sends the owner to the calendar after logging in', function () {
    $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect(route('calendar.index', absolute: false));
});
