<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\Rules\YouTubePostRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 08:00:00');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->channel = SocialAccount::factory()->for($this->workspace)->youTube()->create();
    $this->video = Media::factory()->video()->for($this->workspace)->create(['width' => 1920, 'height' => 1080, 'duration_seconds' => '600.00']);
    $this->rules = app(YouTubePostRules::class);
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<int, int>  $mediaIds
 */
function scheduleYouTube(array $settings, array $mediaIds): TestResponse
{
    return test()->actingAs(test()->user)->post(route('posts.store'), [
        'intent' => 'schedule',
        'title' => 'Internal name',
        'content' => 'Description',
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => '2026-11-11T09:30',
        'targets' => [['social_account_id' => test()->channel->id, 'content_override' => null, 'settings' => [...test()->rules->defaultSettings(), ...$settings]]],
        'media_ids' => $mediaIds,
    ]);
}

it('schedules a YouTube video with its settings', function () {
    scheduleYouTube(['title' => 'My video', 'tags' => 'laravel, php', 'made_for_kids' => false], [$this->video->id])
        ->assertSessionHasNoErrors();

    $post = Post::query()->sole();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->targets()->sole()->settings)->toMatchArray(['title' => 'My video', 'tags' => 'laravel, php', 'privacy' => 'public', 'made_for_kids' => false]);
});

it('needs a title', function () {
    scheduleYouTube(['title' => ''], [$this->video->id])
        ->assertSessionHasErrors("targets.{$this->channel->id}");

    expect(Post::query()->where('status', PostStatus::Scheduled)->count())->toBe(0);
});

it('needs exactly one video', function (string $kind, int $count, string $message) {
    $media = $kind === 'none'
        ? collect()
        : Media::factory()->count($count)->for($this->workspace)->create($kind === 'video' ? ['kind' => MediaKind::Video] : []);

    scheduleYouTube(['title' => 'x'], $media->pluck('id')->all())
        ->assertSessionHasErrors("targets.{$this->channel->id}");

    expect(session('errors')->first("targets.{$this->channel->id}"))->toContain($message);
})->with([
    'no media' => ['none', 0, 'needs one video'],
    'only an image' => ['image', 1, 'needs one video'],
    'two videos' => ['video', 2, 'exactly one video'],
]);

it('rejects a Short that is too long or landscape', function () {
    scheduleYouTube(['title' => 'x', 'format' => 'short'], [$this->video->id])->assertSessionHasErrors();

    expect(session('errors')->first("targets.{$this->channel->id}"))->toContain('at most 3 minutes');
});

it('rejects angle brackets in the title and too many tags', function () {
    scheduleYouTube(['title' => 'Use <b>bold</b>'], [$this->video->id])->assertSessionHasErrors();
    scheduleYouTube(['title' => 'Fine', 'tags' => implode(',', array_map(fn (int $number) => "longtag{$number}", range(1, 60)))], [$this->video->id])->assertSessionHasErrors();

    expect(session('errors')->first("targets.{$this->channel->id}"))->toContain('YouTube allows 500');
});

it('only accepts an image as the thumbnail', function () {
    $otherVideo = Media::factory()->video()->for($this->workspace)->create();

    scheduleYouTube(['title' => 'x', 'thumbnail_media_id' => $otherVideo->id], [$this->video->id])->assertSessionHasErrors();

    expect(session('errors')->first("targets.{$this->channel->id}"))->toContain('thumbnail');
});

it('counts tag length the way YouTube does', function () {
    expect(YouTubePostRules::parseTags(' laravel, #php ,, form requests, php '))->toBe(['laravel', 'php', 'form requests'])
        ->and(YouTubePostRules::tagsLength(['laravel', 'php', 'form requests']))->toBe(7 + 3 + 15 + 2);
});

it('shows the YouTube settings in the editor', function () {
    $this->actingAs($this->user)->get(route('posts.create'))
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.provider', 'youtube')
            ->where('accounts.0.rules.settings.title.type', 'text')
            ->where('accounts.0.rules.settings.privacy.options.unlisted', 'Unlisted')
            ->where('accounts.0.rules.settings.thumbnail_media_id.type', 'image')
            ->where('accounts.0.rules.settings.made_for_kids.default', false));
});
