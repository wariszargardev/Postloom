<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\Rules\TikTokPostRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 08:00:00');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->profile = SocialAccount::factory()->for($this->workspace)->tikTok()->create();
    $this->rules = app(TikTokPostRules::class);
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<int, int>  $mediaIds
 */
function scheduleTikTok(array $settings, array $mediaIds, string $content = 'Hello TikTok'): TestResponse
{
    return test()->actingAs(test()->user)->post(route('posts.store'), [
        'intent' => 'schedule',
        'title' => null,
        'content' => $content,
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => '2026-11-12T09:30',
        'targets' => [['social_account_id' => test()->profile->id, 'content_override' => null, 'settings' => [...test()->rules->defaultSettings(), ...$settings]]],
        'media_ids' => $mediaIds,
    ]);
}

function tikTokError(): string
{
    return (string) session('errors')?->first('targets.'.test()->profile->id);
}

it('schedules one video and one audio file', function () {
    $video = Media::factory()->video()->for($this->workspace)->create();
    scheduleTikTok([], [$video->id])->assertSessionHasNoErrors();

    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg']);
    scheduleTikTok(['mode' => 'direct'], [$audio->id])->assertSessionHasNoErrors();

    expect(Post::query()->where('status', PostStatus::Scheduled)->count())->toBe(2);
});

it('refuses text only, photos, PDFs, two videos, wrong format and bad length', function (string $case, string $message) {
    $ids = match ($case) {
        'text' => [],
        'photo' => [Media::factory()->for($this->workspace)->create()->id],
        'pdf' => [Media::factory()->document()->for($this->workspace)->create()->id],
        'two' => Media::factory()->video()->count(2)->for($this->workspace)->create()->pluck('id')->all(),
        'avi' => [Media::factory()->video()->for($this->workspace)->create(['mime_type' => 'video/x-msvideo', 'original_name' => 'clip.avi'])->id],
        'short' => [Media::factory()->video()->for($this->workspace)->create(['duration_seconds' => '2.00'])->id],
        'long' => [Media::factory()->video()->for($this->workspace)->create(['duration_seconds' => '700.00'])->id],
    };

    scheduleTikTok([], $ids)->assertSessionHasErrors("targets.{$this->profile->id}");

    expect(tikTokError())->toContain($message);
})->with([
    'text only' => ['text', 'Text-only posts'],
    'photo' => ['photo', 'photo posts aren\'t supported'],
    'pdf' => ['pdf', 'can\'t post PDFs'],
    'two videos' => ['two', 'one video per post'],
    'avi' => ['avi', 'Use MP4, MOV or WebM'],
    'too short' => ['short', '3 seconds to 10 minutes'],
    'too long' => ['long', '3 seconds to 10 minutes'],
]);

it('refuses branded content posted as Only me', function () {
    $video = Media::factory()->video()->for($this->workspace)->create();

    scheduleTikTok(['mode' => 'direct', 'commercial' => 'branded', 'privacy' => 'SELF_ONLY'], [$video->id])->assertSessionHasErrors();
    expect(tikTokError())->toContain('branded content can\'t be "Only me"');

    scheduleTikTok(['mode' => 'direct', 'commercial' => 'branded', 'privacy' => 'PUBLIC_TO_EVERYONE'], [$video->id])->assertSessionHasNoErrors();
});

it('refuses a caption over 2,200 characters', function () {
    $video = Media::factory()->video()->for($this->workspace)->create();

    scheduleTikTok([], [$video->id], str_repeat('a', 2201))->assertSessionHasErrors();
});

it('shows the TikTok settings in the editor', function () {
    $this->actingAs($this->user)->get(route('posts.create'))
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.provider', 'tiktok')
            ->where('accounts.0.rules.max_text_length', 2200)
            ->where('accounts.0.rules.settings.mode.default', 'manual')
            ->where('accounts.0.rules.settings.privacy.options.SELF_ONLY', 'Only me')
            ->where('accounts.0.rules.settings.ai_generated.type', 'boolean'));
});

it('refuses audio when you post it yourself', function () {
    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg']);

    scheduleTikTok(['mode' => 'manual'], [$audio->id])->assertSessionHasErrors();

    expect(tikTokError())->toContain('use a video instead of audio');
});
