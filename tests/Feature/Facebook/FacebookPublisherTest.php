<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Facebook\FacebookApiException;
use App\Services\Facebook\FacebookPublisher;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const FBP_GRAPH = 'https://graph.facebook.com/v27.0';
const FBP_VIDEO = 'https://graph-video.facebook.com/v27.0';
const FBP_RUPLOAD = 'https://rupload.facebook.com/video-upload/v27.0';
const FBP_PAGE = '1111';

beforeEach(function () {
    Storage::fake('local');
    config(['services.facebook.graph_version' => 'v27.0']);

    $this->workspace = Workspace::factory()->create();
    $this->page = SocialAccount::factory()->for($this->workspace)->create([
        'provider' => SocialProvider::Facebook,
        'account_type' => SocialAccountType::Page,
        'provider_account_id' => FBP_PAGE,
        'access_token' => 'page-token',
        'token_expires_at' => null,
    ]);
    $this->post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now(), 'content' => 'Hello Facebook']);
    $this->target = PostTarget::factory()->for($this->post)->for($this->page)->create(['settings' => ['format' => 'post', 'video_title' => '']]);
    $this->publisher = app(FacebookPublisher::class);
});

/**
 * @param  array<string, mixed>  $extra
 */
function fakeFacebook(array $extra = []): void
{
    Http::fake([
        ...$extra,
        FBP_GRAPH.'/'.FBP_PAGE.'/feed' => Http::response(['id' => FBP_PAGE.'_9001']),
        FBP_GRAPH.'/'.FBP_PAGE.'_9001*' => Http::response(['permalink_url' => 'https://www.facebook.com/buildrealcode/posts/pfbid123', 'id' => FBP_PAGE.'_9001']),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function attachFacebookMedia(array $attributes = [], string $bytes = 'file-bytes'): Media
{
    $media = Media::factory()->for(test()->workspace)->create(['mime_type' => 'image/png', ...$attributes]);
    Storage::disk('local')->put($media->path, $bytes);
    test()->post->media()->attach($media, ['sort_order' => test()->post->media()->count()]);

    return $media;
}

it('publishes a text post to the Page feed with the Page token and saves its link', function () {
    fakeFacebook();

    $result = $this->publisher->publish($this->target);

    expect($result->externalId)->toBe(FBP_PAGE.'_9001')
        ->and($result->externalUrl)->toBe('https://www.facebook.com/buildrealcode/posts/pfbid123');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/feed'
        && $request['message'] === 'Hello Facebook'
        && $request['access_token'] === 'page-token');
});

it('posts one photo with the text as its caption', function () {
    fakeFacebook([FBP_GRAPH.'/'.FBP_PAGE.'/photos' => Http::response(['id' => '777', 'post_id' => FBP_PAGE.'_9001'])]);
    attachFacebookMedia(bytes: 'png-bytes');

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe(FBP_PAGE.'_9001');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/photos'
        && $request->isMultipart()
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'caption' && $part['contents'] === 'Hello Facebook')
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'source'));
});

it('uploads several photos unpublished and attaches them to one feed post', function () {
    fakeFacebook([FBP_GRAPH.'/'.FBP_PAGE.'/photos' => Http::sequence()->push(['id' => 'p1'])->push(['id' => 'p2'])->push(['id' => 'p3'])]);
    attachFacebookMedia();
    attachFacebookMedia();
    attachFacebookMedia();

    $this->publisher->publish($this->target->fresh());

    $photoUploads = Http::recorded(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/photos');
    expect($photoUploads)->toHaveCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/feed'
        && $request['attached_media[0]'] === '{"media_fbid":"p1"}'
        && $request['attached_media[2]'] === '{"media_fbid":"p3"}'
        && $request['message'] === 'Hello Facebook');
});

it('uploads a video to graph-video with description and title', function () {
    Http::fake([
        FBP_VIDEO.'/'.FBP_PAGE.'/videos' => Http::response(['id' => 'v55']),
        FBP_GRAPH.'/v55*' => Http::response(['permalink_url' => '/buildrealcode/videos/v55/']),
    ]);
    $this->target->update(['settings' => ['format' => 'post', 'video_title' => 'Form Requests']]);
    attachFacebookMedia(['kind' => MediaKind::Video, 'mime_type' => 'video/mp4', 'original_name' => 'clip.mp4']);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('v55')
        ->and($result->externalUrl)->toBe('https://www.facebook.com/buildrealcode/videos/v55/');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_VIDEO.'/'.FBP_PAGE.'/videos'
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'title' && $part['contents'] === 'Form Requests')
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'description' && $part['contents'] === 'Hello Facebook'));
});

it('publishes a Reel in three steps: start, binary upload, finish', function () {
    Http::fake([
        FBP_GRAPH.'/'.FBP_PAGE.'/video_reels' => Http::sequence()->push(['video_id' => 'r42', 'upload_url' => FBP_RUPLOAD.'/r42'])->push(['success' => true]),
        FBP_RUPLOAD.'/r42' => Http::response(['success' => true]),
    ]);
    $this->target->update(['settings' => ['format' => 'reel', 'video_title' => '']]);
    attachFacebookMedia(['kind' => MediaKind::Video, 'mime_type' => 'video/mp4', 'width' => 1080, 'height' => 1920, 'duration_seconds' => '30.00'], 'reel-bytes');

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('r42')
        ->and($result->externalUrl)->toBe('https://www.facebook.com/reel/r42');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/video_reels' && $request['upload_phase'] === 'start');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_RUPLOAD.'/r42'
        && $request->hasHeader('Authorization', 'OAuth page-token')
        && $request->hasHeader('offset', '0')
        && $request->hasHeader('file_size', '10')
        && $request->body() === 'reel-bytes');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_GRAPH.'/'.FBP_PAGE.'/video_reels'
        && $request['upload_phase'] === 'finish'
        && $request['video_state'] === 'PUBLISHED'
        && $request['description'] === 'Hello Facebook');
});

it('refuses a landscape or too long Reel before calling Facebook', function () {
    Http::fake();
    $this->target->update(['settings' => ['format' => 'reel', 'video_title' => '']]);
    attachFacebookMedia(['kind' => MediaKind::Video, 'mime_type' => 'video/mp4', 'width' => 1920, 'height' => 1080, 'duration_seconds' => '120.00']);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('3 to 90 seconds'));
    Http::assertNothingSent();
});

it('turns audio into a video and uploads it', function () {
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'mp4');

        return Process::result();
    });
    Http::fake([
        FBP_VIDEO.'/'.FBP_PAGE.'/videos' => Http::response(['id' => 'v77']),
        FBP_GRAPH.'/v77*' => Http::response([]),
    ]);
    attachFacebookMedia(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg', 'original_name' => 'episode.mp3', 'path' => 'media/test/episode.mp3']);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalUrl)->toBe('https://www.facebook.com/'.FBP_PAGE.'/videos/v77/');
    Http::assertSent(fn (Request $request) => $request->url() === FBP_VIDEO.'/'.FBP_PAGE.'/videos'
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'source' && $part['filename'] === 'episode.mp4'));
});

it('marks the Page for reconnect when Facebook rejects its token', function () {
    Http::fake([FBP_GRAPH.'/'.FBP_PAGE.'/feed' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190, 'error_subcode' => 463]], 400)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (FacebookApiException $exception) => expect($exception->isRetryable)->toBeFalse());

    expect($this->page->fresh()->needs_reconnect)->toBeTrue();
});

it('maps Facebook errors to retryable or permanent', function (int $status, int $code, bool $retryable) {
    Http::fake([FBP_GRAPH.'/'.FBP_PAGE.'/feed' => Http::response(['error' => ['message' => 'Nope', 'code' => $code]], $status)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (FacebookApiException $exception) => expect($exception->isRetryable)->toBe($retryable));

    expect($this->page->fresh()->needs_reconnect)->toBeFalse();
})->with([
    'rate limit 32' => [400, 32, true],
    'business rate limit 80001' => [400, 80001, true],
    'permission 200' => [403, 200, false],
    'blocked 368' => [400, 368, false],
    'server error' => [500, 2, true],
]);

it('falls back to a constructed link when the permalink lookup fails', function () {
    Http::fake([
        FBP_GRAPH.'/'.FBP_PAGE.'/feed' => Http::response(['id' => FBP_PAGE.'_9002']),
        FBP_GRAPH.'/'.FBP_PAGE.'_9002*' => Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400),
    ]);

    expect($this->publisher->publish($this->target)->externalUrl)->toBe('https://www.facebook.com/'.FBP_PAGE.'_9002');
});

it('publishes end to end through the job', function () {
    fakeFacebook();

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($this->target->fresh()->external_url)->toBe('https://www.facebook.com/buildrealcode/posts/pfbid123')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});
