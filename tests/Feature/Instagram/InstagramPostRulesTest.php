<?php

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\Rules\InstagramPostRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-13 08:00:00');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->account = SocialAccount::factory()->for($this->workspace)->instagram()->create();
    $this->rules = app(InstagramPostRules::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function igPhoto(array $attributes = []): Media
{
    return Media::factory()->for(test()->workspace)->create(['width' => 1080, 'height' => 1350, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function igVideo(array $attributes = []): Media
{
    return Media::factory()->video()->for(test()->workspace)->create(['width' => 1080, 'height' => 1920, 'size_bytes' => 20_000_000, ...$attributes]);
}

/**
 * @param  array<int, Media>  $media
 * @param  array<string, mixed>  $settings
 * @return array<int, string>
 */
function igErrors(array $media, array $settings = [], string $text = 'Hello'): array
{
    $collection = new Collection($media);
    $rules = test()->rules;

    return [...$rules->mediaErrors($collection), ...$rules->targetErrors($text, $collection, [...$rules->defaultSettings(), ...$settings])];
}

it('accepts a photo post, a carousel, a Reel and Stories', function () {
    expect(igErrors([igPhoto()]))->toBe([])
        ->and(igErrors([igPhoto(), igVideo(), igPhoto(['mime_type' => 'image/png', 'original_name' => 'a.png'])]))->toBe([])
        ->and(igErrors([igVideo()], ['format' => 'reel']))->toBe([])
        ->and(igErrors([igPhoto(['width' => 1080, 'height' => 1920])], ['format' => 'story']))->toBe([])
        ->and(igErrors([igVideo(['duration_seconds' => '20'])], ['format' => 'story']))->toBe([]);
});

it('refuses text-only posts, PDFs, WebP, big photos and more than 10 items', function () {
    expect(igErrors([])[0])->toContain('Text-only posts aren\'t possible')
        ->and(igErrors([Media::factory()->document()->for($this->workspace)->create()])[0])->toContain('PDFs')
        ->and(igErrors([igPhoto(['mime_type' => 'image/webp', 'original_name' => 'a.webp'])])[0])->toContain('Use JPG or PNG')
        ->and(igErrors([igPhoto(['size_bytes' => 9 * 1024 * 1024])])[0])->toContain('8 MB')
        ->and(igErrors(array_map(fn () => igPhoto(), range(1, 11)))[0])->toContain('up to 10');
});

it('needs the Reel format for a single video and checks feed photo ratios', function () {
    expect(igErrors([igVideo()])[0])->toContain('choose the Reel format')
        ->and(igErrors([igPhoto(['width' => 1080, 'height' => 1920, 'original_name' => 'tall.jpg'])])[0])->toContain('between 4:5')
        ->and(igErrors([igPhoto(['width' => 1080, 'height' => 1920])], ['format' => 'story']))->toBe([]);
});

it('checks Reel and Story video limits', function () {
    expect(igErrors([igPhoto()], ['format' => 'reel'])[0])->toContain('exactly one video')
        ->and(igErrors([igVideo(['duration_seconds' => '2'])], ['format' => 'reel'])[0])->toContain('3 s to 15 min')
        ->and(igErrors([igVideo(['duration_seconds' => '901'])], ['format' => 'reel'])[0])->toContain('3 s to 15 min')
        ->and(igErrors([igVideo(['size_bytes' => 301 * 1024 * 1024])], ['format' => 'reel'])[0])->toContain('300 MB')
        ->and(igErrors([igVideo(['duration_seconds' => '61'])], ['format' => 'story'])[0])->toContain('3 s to 1 min')
        ->and(igErrors([igPhoto(), igPhoto()], ['format' => 'story'])[0])->toContain('exactly one');
});

it('accepts an audio file as a Reel but not mixed with other files', function () {
    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg', 'original_name' => 'talk.mp3', 'width' => null, 'height' => null, 'duration_seconds' => '30']);

    expect(igErrors([$audio], ['format' => 'reel']))->toBe([])
        ->and(igErrors([$audio, igPhoto()])[0])->toContain('audio file');
});

it('limits hashtags to 30 and mentions to 20', function () {
    $tags = implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, 31)));
    $mentions = implode(' ', array_map(fn ($i) => "@user{$i}", range(1, 21)));

    expect(igErrors([igPhoto()], [], $tags)[0])->toContain('31')
        ->and(igErrors([igPhoto()], [], $mentions)[0])->toContain('20 @mentions')
        ->and(igErrors([igPhoto()], [], 'mail me at me@example.com #one #two'))->toBe([]);
});

it('says which posts need the public link', function () {
    expect($this->rules->needsPublicLink(new Collection([igVideo()])))->toBeFalse()
        ->and($this->rules->needsPublicLink(new Collection([igPhoto()])))->toBeTrue();

    config(['services.instagram.video_upload' => 'url']);

    expect($this->rules->needsPublicLink(new Collection([igVideo()])))->toBeTrue();
});

it('schedules an Instagram Reel from the editor and refuses a text-only one', function () {
    $video = igVideo();
    $payload = fn (array $mediaIds) => [
        'intent' => 'schedule',
        'title' => null,
        'content' => 'Reel caption #shorts',
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => '2026-11-14T09:30',
        'targets' => [['social_account_id' => $this->account->id, 'content_override' => null, 'settings' => ['format' => 'reel', 'share_to_feed' => false]]],
        'media_ids' => $mediaIds,
    ];

    $this->actingAs($this->user)->post(route('posts.store'), $payload([$video->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('posts.store'), $payload([]))
        ->assertSessionHasErrors('targets.'.$this->account->id);
});

it('describes the Instagram settings for the editor', function () {
    $described = $this->rules->describe();

    expect($described['max_text_length'])->toBe(2200)
        ->and(array_keys($described['settings']))->toBe(['format', 'share_to_feed'])
        ->and(array_keys($described['settings']['format']['options']))->toBe(['post', 'reel', 'story']);
});
