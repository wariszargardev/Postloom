<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 10:00:00');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->linkedIn = SocialAccount::factory()->for($this->workspace)->create(['name' => 'Muhammad Waris']);
});

function postPayload(array $overrides = []): array
{
    return [
        'intent' => 'draft',
        'title' => null,
        'content' => 'Hello LinkedIn',
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => '2026-11-11T09:30',
        'targets' => [['social_account_id' => test()->linkedIn->id, 'content_override' => null, 'settings' => ['visibility' => 'PUBLIC']]],
        'media_ids' => [],
        ...$overrides,
    ];
}

it('saves a draft with accounts, ordered media and a custom text', function () {
    $first = Media::factory()->for($this->workspace)->create();
    $second = Media::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)->post(route('posts.store'), postPayload([
        'targets' => [['social_account_id' => $this->linkedIn->id, 'content_override' => 'Only for LinkedIn', 'settings' => ['visibility' => 'CONNECTIONS']]],
        'media_ids' => [$second->id, $first->id],
    ]))->assertRedirect();

    $post = Post::query()->sole();
    $target = $post->targets()->sole();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->user_id)->toBe($this->user->id)
        ->and($post->media()->pluck('media.id')->all())->toBe([$second->id, $first->id])
        ->and($target->content_override)->toBe('Only for LinkedIn')
        ->and($target->settings)->toBe(['visibility' => 'CONNECTIONS']);
});

it('stores the time in UTC and shows it back in the post timezone', function () {
    $this->actingAs($this->user)->post(route('posts.store'), postPayload());

    $post = Post::query()->sole();

    expect($post->scheduled_at->toDateTimeString())->toBe('2026-11-11 04:30:00');

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn ($page) => $page
            ->component('posts/Form')
            ->where('post.scheduled_at', '2026-11-11T09:30')
            ->where('post.timezone', 'Asia/Karachi'));
});

it('schedules a valid post', function () {
    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Scheduled for Wed 11 Nov 2026, 9:30 AM (Asia/Karachi).');

    expect(Post::query()->sole()->status)->toBe(PostStatus::Scheduled);
});

it('refuses to schedule and saves nothing when the post is not ready', function (array $overrides, string $errorKey) {
    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule', ...$overrides]))
        ->assertSessionHasErrors($errorKey);

    expect(Post::query()->count())->toBe(0);
})->with([
    'no account' => [['targets' => []], 'targets'],
    'time in the past' => [['scheduled_at' => '2026-11-09T09:00'], 'scheduled_at'],
    'no time' => [['scheduled_at' => null], 'scheduled_at'],
    'no text and no media' => [['content' => ''], 'content'],
]);

it('schedules later today down to the minute but not a minute ago', function (string $localTime, bool $accepted) {
    Carbon::setTestNow(Carbon::parse('2026-11-12 12:55:00', 'Asia/Karachi'));

    $response = $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule', 'scheduled_at' => "2026-11-12T{$localTime}"]));

    $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('scheduled_at');
    expect(Post::query()->where('status', PostStatus::Scheduled)->exists())->toBe($accepted);
})->with([
    'one minute later' => ['12:56', true],
    '20 minutes later' => ['13:15', true],
    '4 hours later' => ['16:55', true],
    'one minute ago' => ['12:54', false],
]);

it('names the account and the limit when the text is too long', function () {
    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule', 'content' => str_repeat('a', 3120)]))
        ->assertSessionHasErrors(["targets.{$this->linkedIn->id}" => 'Muhammad Waris (LinkedIn): the text is 3,120 characters; the limit is 3,000.']);
});

it('applies LinkedIn media rules when scheduling', function (string $kinds, string $message) {
    $media = collect(explode(',', $kinds))->map(fn (string $kind) => match ($kind) {
        'video' => Media::factory()->for($this->workspace)->video()->create(),
        'document' => Media::factory()->for($this->workspace)->document()->create(),
        default => Media::factory()->for($this->workspace)->create(),
    });

    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule', 'media_ids' => $media->pluck('id')->all()]))
        ->assertSessionHasErrors(["targets.{$this->linkedIn->id}" => "Muhammad Waris (LinkedIn): {$message}"]);
})->with([
    'image and video' => ['image,video', 'LinkedIn can\'t mix media types in one post. Use only images, or one video, one PDF or one audio file.'],
    'two videos' => ['video,video', 'LinkedIn allows one video per post.'],
    'two PDFs' => ['document,document', 'LinkedIn allows one PDF per post.'],
    '21 images' => [implode(',', array_fill(0, 21, 'image')), 'LinkedIn allows up to 20 images per post; this post has 21.'],
]);

it('allows 20 images on LinkedIn', function () {
    $images = Media::factory()->for($this->workspace)->count(20)->create();

    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload(['intent' => 'schedule', 'media_ids' => $images->pluck('id')->all()]))
        ->assertSessionHasNoErrors();

    expect(Post::query()->sole()->status)->toBe(PostStatus::Scheduled);
});

it('refuses accounts that need reconnecting or cannot post yet', function () {
    $expired = SocialAccount::factory()->for($this->workspace)->expired()->create(['name' => 'Old profile']);
    $whatsApp = SocialAccount::factory()->for($this->workspace)->create(['name' => 'My number', 'provider' => SocialProvider::WhatsApp]);

    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload([
            'intent' => 'schedule',
            'targets' => [
                ['social_account_id' => $expired->id, 'settings' => ['visibility' => 'PUBLIC']],
                ['social_account_id' => $whatsApp->id],
            ],
        ]))
        ->assertSessionHasErrors([
            "targets.{$expired->id}" => 'Old profile (LinkedIn): reconnect this account first.',
            "targets.{$whatsApp->id}" => 'My number (WhatsApp): posting is not available yet.',
        ]);
});

it('rejects accounts and media from another workspace', function () {
    $otherAccount = SocialAccount::factory()->create();
    $otherMedia = Media::factory()->create();

    $this->actingAs($this->user)
        ->post(route('posts.store'), postPayload([
            'targets' => [['social_account_id' => $otherAccount->id]],
            'media_ids' => [$otherMedia->id],
        ]))
        ->assertSessionHasErrors(['targets.0.social_account_id', 'media_ids.0']);
});

it('updates a post and drops removed accounts', function () {
    $post = Post::factory()->for($this->workspace)->create();
    PostTarget::factory()->for($post)->for($this->linkedIn)->create();
    $second = SocialAccount::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('posts.update', $post), postPayload([
            'content' => 'Changed',
            'targets' => [['social_account_id' => $second->id, 'settings' => ['visibility' => 'PUBLIC']]],
        ]))
        ->assertRedirect(route('posts.edit', $post));

    expect($post->fresh()->content)->toBe('Changed')
        ->and($post->targets()->pluck('social_account_id')->all())->toBe([$second->id]);
});

it('moves a scheduled post back to drafts', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create();

    $this->actingAs($this->user)->post(route('posts.unschedule', $post))->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('does not change a post while it is publishing', function () {
    $post = Post::factory()->for($this->workspace)->publishing()->create(['content' => 'Original']);

    $this->actingAs($this->user)
        ->put(route('posts.update', $post), postPayload(['content' => 'Changed']))
        ->assertSessionHasErrors('post');
    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertSessionHasErrors('post');

    expect($post->fresh()->content)->toBe('Original');
});

it('keeps a post that already went out unchanged, but lets you delete it', function (PostStatus $status) {
    $post = Post::factory()->for($this->workspace)->create([
        'status' => $status,
        'scheduled_at' => now()->subHour(),
        'content' => 'Original',
    ]);
    $target = PostTarget::factory()->for($post)->for($this->linkedIn)->create([
        'status' => PostTargetStatus::Published,
        'external_url' => 'https://www.linkedin.com/feed/update/urn:li:share:1',
    ]);

    $this->actingAs($this->user)
        ->put(route('posts.update', $post), postPayload(['intent' => 'schedule', 'content' => 'Changed']))
        ->assertSessionHasErrors(['post' => 'This post has already gone out, so it can\'t be changed. Duplicate it to post an edited copy.']);
    $this->actingAs($this->user)->post(route('posts.unschedule', $post))->assertSessionHasErrors('post');

    expect($post->fresh()->status)->toBe($status)
        ->and($post->fresh()->content)->toBe('Original')
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Published);

    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertRedirect(route('posts.index'));
    $this->assertSoftDeleted($post);
    $this->assertSoftDeleted($target);
})->with([
    'published' => [PostStatus::Published],
    'partly published' => [PostStatus::PartlyPublished],
]);

it('shows a post that already went out as read-only with Duplicate to edit', function () {
    $post = Post::factory()->for($this->workspace)->published()->create();

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertInertia(fn ($page) => $page
            ->where('post.is_editable', false)
            ->where('post.is_delivered', true)
            ->where('post.is_deletable', true));
});

it('still lets you edit and reschedule a failed post', function () {
    $post = Post::factory()->for($this->workspace)->failed()->create(['content' => 'Original']);
    PostTarget::factory()->for($post)->for($this->linkedIn)->create(['status' => PostTargetStatus::Failed, 'last_error' => 'Boom']);

    $this->actingAs($this->user)
        ->put(route('posts.update', $post), postPayload(['intent' => 'schedule', 'content' => 'Fixed']))
        ->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($post->fresh()->content)->toBe('Fixed')
        ->and($post->targets()->sole()->status)->toBe(PostTargetStatus::Pending);
});

it('duplicates a post as a draft without a time', function () {
    $post = Post::factory()->for($this->workspace)->scheduled()->create(['title' => 'Launch']);
    PostTarget::factory()->for($post)->for($this->linkedIn)->create(['content_override' => 'Custom']);
    $post->media()->attach(Media::factory()->for($this->workspace)->create(), ['sort_order' => 0]);

    $this->actingAs($this->user)->post(route('posts.duplicate', $post))->assertRedirect();

    $copy = Post::query()->whereKeyNot($post->id)->sole();

    expect($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->title)->toBe('Copy of Launch')
        ->and($copy->targets()->sole()->content_override)->toBe('Custom')
        ->and($copy->media()->count())->toBe(1);
});

it('deletes a post', function () {
    $post = Post::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertRedirect(route('posts.index'));

    $this->assertSoftDeleted($post);
});

it('blocks another owner from viewing or changing a post', function () {
    $post = Post::factory()->create();

    $this->actingAs($this->user)->get(route('posts.edit', $post))->assertForbidden();
    $this->actingAs($this->user)->put(route('posts.update', $post), postPayload())->assertForbidden();
    $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertForbidden();
});

it('lists posts of the current workspace and filters by status', function () {
    Post::factory()->for($this->workspace)->create();
    Post::factory()->for($this->workspace)->scheduled()->create();
    Post::factory()->create();

    $this->actingAs($this->user)->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page->component('posts/Index')->has('posts.data', 2));

    $this->actingAs($this->user)->get(route('posts.index', ['status' => 'scheduled']))
        ->assertInertia(fn ($page) => $page->has('posts.data', 1)->where('posts.data.0.status', 'scheduled'));
});

it('shows the editor with accounts, rules and the media library', function () {
    Media::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)->get(route('posts.create'))
        ->assertInertia(fn ($page) => $page
            ->component('posts/Form')
            ->where('post', null)
            ->where('accounts.0.rules.max_text_length', 3000)
            ->has('library', 1)
            ->where('upload.max_files', 10)
            ->where('upload.accept', fn (string $accept) => str_contains($accept, '.mp4') && str_contains($accept, '.png'))
            ->where('defaultTimezone', 'Asia/Karachi'));
});
