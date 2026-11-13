<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Facebook\FacebookApiException;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const IGP_GRAPH = 'https://graph.facebook.com/v27.0';
const IGP_RUPLOAD = 'https://rupload.facebook.com/ig-api-upload/v27.0';
const IGP_TUNNEL = 'https://quiet-river.trycloudflare.com';
const IGP_USER = '17841400000000001';
const IGP_MEDIA_ID = '18000000000000009';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake(syncWithCarbon: true);
    config([
        'services.facebook.graph_version' => 'v27.0',
        'services.instagram.public_base_url' => IGP_TUNNEL,
        'services.instagram.video_upload' => 'resumable',
        'services.instagram.status_poll_seconds' => 15,
        'services.instagram.status_wait_seconds' => 300,
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->account = SocialAccount::factory()->for($this->workspace)->instagram()->create([
        'provider_account_id' => IGP_USER,
        'handle' => 'buildrealcode',
        'access_token' => 'page-token',
    ]);
    $this->post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now(), 'content' => 'Hello Instagram #buildrealcode']);
    $this->target = PostTarget::factory()->for($this->post)->for($this->account)->create(['settings' => ['format' => 'post', 'share_to_feed' => true]]);
    $this->publisher = app(InstagramPublisher::class);
});

/**
 * Routes every Graph call; $statuses are returned in order for container status checks (then FINISHED).
 *
 * @param  array<int, string>  $statuses
 * @param  array<string, Closure(Request): mixed>  $overrides  url fragment => response
 */
function fakeInstagramApi(array $statuses = [], array $overrides = [], bool $tunnelUp = true): void
{
    $containers = 0;

    Http::fake(function (Request $request) use (&$containers, &$statuses, $overrides, $tunnelUp) {
        $url = $request->url();

        foreach ($overrides as $fragment => $response) {
            if (str_contains($url, $fragment)) {
                return $response($request);
            }
        }

        return match (true) {
            str_starts_with($url, IGP_TUNNEL.'/up') => $tunnelUp ? Http::response('ok') : Http::response('down', 502),
            str_contains($url, '/content_publishing_limit') => Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]]),
            str_contains($url, '/media_publish') => Http::response(['id' => IGP_MEDIA_ID]),
            str_ends_with($url, IGP_USER.'/media') => Http::response(['id' => 'c'.(++$containers)]),
            str_starts_with($url, 'https://rupload.facebook.com/') => Http::response(['success' => true]),
            str_starts_with($url, IGP_GRAPH.'/c') => Http::response(['status_code' => array_shift($statuses) ?? 'FINISHED', 'status' => 'Finished: Media has been uploaded']),
            str_starts_with($url, IGP_GRAPH.'/'.IGP_MEDIA_ID) => Http::response(['permalink' => 'https://www.instagram.com/p/DAbc123/']),
            default => Http::response(['error' => ['message' => "Unexpected call {$url}", 'code' => 100]], 400),
        };
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function attachInstagramMedia(array $attributes = []): Media
{
    $media = Media::factory()->for(test()->workspace)->create(['width' => 1080, 'height' => 1080, ...$attributes]);
    Storage::disk('local')->put($media->path, 'file-bytes');
    test()->post->media()->attach($media, ['sort_order' => test()->post->media()->count()]);

    return $media;
}

function attachInstagramVideo(array $attributes = []): Media
{
    return attachInstagramMedia(['kind' => MediaKind::Video, 'mime_type' => 'video/mp4', 'original_name' => 'clip.mp4', 'path' => 'media/test/clip-'.uniqid().'.mp4', 'width' => 1080, 'height' => 1920, 'duration_seconds' => '20', ...$attributes]);
}

/**
 * @return array<int, Request>
 */
function containerCreates(): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), IGP_USER.'/media'))
        ->map(fn (array $pair) => $pair[0])->values()->all();
}

it('publishes one photo from a signed public link and saves the permalink', function () {
    fakeInstagramApi();
    $photo = attachInstagramMedia(['alt_text' => 'A desk with a laptop']);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe(IGP_MEDIA_ID)
        ->and($result->externalUrl)->toBe('https://www.instagram.com/p/DAbc123/');

    $create = containerCreates()[0];
    expect($create['image_url'])->toStartWith(IGP_TUNNEL."/public-media/{$photo->id}?expires=")
        ->and($create['image_url'])->toContain('signature=')
        ->and($create['caption'])->toBe('Hello Instagram #buildrealcode')
        ->and($create['alt_text'])->toBe('A desk with a laptop')
        ->and($create['access_token'])->toBe('page-token');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/media_publish') && $request['creation_id'] === 'c1');
});

it('builds a carousel from photos and a resumable video, then publishes it', function () {
    fakeInstagramApi(overrides: [
        IGP_USER.'/media' => function (Request $request) {
            static $count = 0;
            $count++;

            return Http::response(array_filter(['id' => "c{$count}", 'uri' => ($request->data()['upload_type'] ?? null) === 'resumable' ? IGP_RUPLOAD."/c{$count}" : null]));
        },
    ]);
    attachInstagramMedia();
    attachInstagramVideo();
    attachInstagramMedia();

    $this->publisher->publish($this->target->fresh());

    $creates = containerCreates();
    expect($creates)->toHaveCount(4)
        ->and($creates[0]['is_carousel_item'])->toBe('true')
        ->and($creates[1]['media_type'])->toBe('VIDEO')
        ->and($creates[1]['upload_type'])->toBe('resumable')
        ->and($creates[3]['media_type'])->toBe('CAROUSEL')
        ->and($creates[3]['children'])->toBe('c1,c2,c3')
        ->and($creates[3]['caption'])->toBe('Hello Instagram #buildrealcode');
    Http::assertSent(fn (Request $request) => $request->url() === IGP_RUPLOAD.'/c2' && $request->hasHeader('Authorization', 'OAuth page-token'));
});

it('uploads a Reel straight from this PC and waits until Instagram has processed it', function () {
    fakeInstagramApi(statuses: ['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED']);
    attachInstagramVideo();
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => false]]);

    $this->publisher->publish($this->target->fresh());

    $create = containerCreates()[0];
    expect($create['media_type'])->toBe('REELS')
        ->and($create['upload_type'])->toBe('resumable')
        ->and($create['share_to_feed'])->toBe('false')
        ->and($create->data())->not->toHaveKey('video_url');
    Http::assertSent(fn (Request $request) => $request->url() === IGP_RUPLOAD.'/c1'
        && $request->hasHeader('offset', '0')
        && $request->hasHeader('file_size', '10')
        && $request->body() === 'file-bytes');
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), IGP_TUNNEL));
    Sleep::assertSleptTimes(2);
});

it('posts a photo Story', function () {
    fakeInstagramApi();
    attachInstagramMedia(['width' => 1080, 'height' => 1920]);
    $this->target->update(['settings' => ['format' => 'story', 'share_to_feed' => true]]);

    $this->publisher->publish($this->target->fresh());

    $create = containerCreates()[0];
    expect($create['media_type'])->toBe('STORIES')
        ->and($create['image_url'])->toStartWith(IGP_TUNNEL.'/public-media/')
        ->and($create->data())->not->toHaveKey('caption');
});

it('hands a photo post to you when the tunnel is not running', function () {
    fakeInstagramApi(tunnelUp: false);
    attachInstagramMedia();

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->needsManualPost)->toBeTrue()
        ->and($result->notice)->toContain('tunnel was not running')
        ->and(containerCreates())->toBe([]);
});

it('hands a photo post to you when no public address is set at all', function () {
    config(['services.instagram.public_base_url' => null, 'services.instagram.tunnel_url_file' => storage_path('framework/testing/missing-tunnel.txt')]);
    fakeInstagramApi();
    attachInstagramMedia();

    expect($this->publisher->publish($this->target->fresh())->needsManualPost)->toBeTrue();
});

it('still publishes a Reel when the tunnel is down', function () {
    fakeInstagramApi(tunnelUp: false);
    attachInstagramVideo();
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => true]]);

    expect($this->publisher->publish($this->target->fresh())->externalId)->toBe(IGP_MEDIA_ID);
});

it('sends the video as a public link when resumable upload is switched off', function () {
    config(['services.instagram.video_upload' => 'url']);
    fakeInstagramApi();
    $video = attachInstagramVideo();
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => true]]);

    $this->publisher->publish($this->target->fresh());

    expect(containerCreates()[0]['video_url'])->toStartWith(IGP_TUNNEL."/public-media/{$video->id}?");
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://rupload.facebook.com/'));
});

it('turns audio into a video Reel', function () {
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'mp4');

        return Process::result();
    });
    fakeInstagramApi();
    attachInstagramMedia(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg', 'original_name' => 'talk.mp3', 'path' => 'media/test/talk.mp3', 'width' => null, 'height' => null, 'duration_seconds' => '30']);
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => true]]);

    $this->publisher->publish($this->target->fresh());

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), IGP_RUPLOAD) && $request->body() === 'mp4');
});

it('fails for good when Instagram cannot process the media', function () {
    fakeInstagramApi(statuses: ['ERROR']);
    attachInstagramVideo();
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => true]]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('could not process the media (ERROR'));
});

it('retries later when processing takes longer than the wait', function () {
    config(['services.instagram.status_wait_seconds' => 20]);
    fakeInstagramApi(statuses: array_fill(0, 10, 'IN_PROGRESS'));
    attachInstagramVideo();
    $this->target->update(['settings' => ['format' => 'reel', 'share_to_feed' => true]]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeTrue());
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

it('waits and publishes again when Instagram says the media is not ready yet', function () {
    fakeInstagramApi(overrides: [
        '/media_publish' => function () {
            static $tries = 0;

            return ++$tries === 1
                ? Http::response(['error' => ['message' => 'Media ID is not available', 'code' => 9007, 'error_subcode' => 2207027]], 400)
                : Http::response(['id' => IGP_MEDIA_ID]);
        },
    ]);
    attachInstagramMedia();

    expect($this->publisher->publish($this->target->fresh())->externalId)->toBe(IGP_MEDIA_ID);
});

it('stops before creating anything when the 24-hour publishing limit is used up', function () {
    fakeInstagramApi(overrides: [
        '/content_publishing_limit' => fn () => Http::response(['data' => [['quota_usage' => 50, 'config' => ['quota_total' => 50]]]]),
    ]);
    attachInstagramMedia();

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('limit of 50 API posts'));
    expect(containerCreates())->toBe([]);
});

it('marks the account for reconnect when the Page token is rejected', function () {
    fakeInstagramApi(overrides: [
        '/content_publishing_limit' => fn () => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190, 'error_subcode' => 460]], 400),
    ]);
    attachInstagramMedia();

    expect(fn () => $this->publisher->publish($this->target->fresh()))->toThrow(FacebookApiException::class);
    expect($this->account->fresh()->needs_reconnect)->toBeTrue();
});

it('does not retry blindly when the connection drops while publishing', function () {
    fakeInstagramApi(overrides: [
        '/media_publish' => fn () => throw new ConnectionException('Connection reset'),
    ]);
    attachInstagramMedia();

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('may already be posted'));
});

it('refuses a post that breaks the Instagram rules before calling Instagram', function () {
    fakeInstagramApi();
    attachInstagramVideo();

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(PublishException::class, 'A single video goes to Instagram as a Reel');
    Http::assertNothingSent();
});

it('publishes end to end through the job', function () {
    fakeInstagramApi();
    attachInstagramMedia();

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($this->target->fresh()->external_url)->toBe('https://www.instagram.com/p/DAbc123/')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});

it('leaves a photo post Ready for you through the job when the tunnel is down', function () {
    fakeInstagramApi(tunnelUp: false);
    attachInstagramMedia();

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::ReadyToPost)
        ->and($this->target->fresh()->published_at)->toBeNull();
});
