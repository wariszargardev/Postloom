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
use App\Services\Posts\Rules\YouTubePostRules;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubePublisher;
use App\Services\YouTube\YouTubeUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const YT_UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos*';
const YT_SESSION = 'https://uploads.test/session-1';
const YT_THUMBNAIL = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set*';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.upload_chunk_bytes' => YouTubeUploader::CHUNK_UNIT_BYTES,
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->channel = SocialAccount::factory()->for($this->workspace)->youTube()->create([
        'name' => 'Build Real Code',
        'access_token' => 'yt-token',
        'token_expires_at' => now()->addHour(),
    ]);
    $this->post = Post::factory()->for($this->workspace)->create([
        'status' => PostStatus::Publishing,
        'scheduled_at' => now(),
        'content' => 'How Form Requests keep controllers thin <3',
    ]);
    $this->video = Media::factory()->video()->for($this->workspace)->create(['width' => 1920, 'height' => 1080, 'duration_seconds' => '95.00']);
    $this->post->media()->attach($this->video, ['sort_order' => 0]);
    Storage::disk('local')->put($this->video->path, str_repeat('v', 10));
    $this->target = PostTarget::factory()->for($this->post)->for($this->channel)->create(['settings' => [
        ...app(YouTubePostRules::class)->defaultSettings(),
        'title' => 'Form Requests in Laravel',
        'tags' => 'laravel, #php, form requests',
        'category' => '28',
        'privacy' => 'unlisted',
    ]]);
    $this->publisher = app(YouTubePublisher::class);
});

/**
 * @param  array<string, mixed>  $extra
 */
function fakeYouTube(array $extra = [], mixed $session = null): void
{
    Http::fake([
        ...$extra,
        YT_UPLOAD => Http::response(['kind' => 'youtube#video'], 200, ['Location' => YT_SESSION]),
        YT_SESSION => $session ?? Http::response(['id' => 'vid123', 'status' => ['uploadStatus' => 'uploaded']], 201),
    ]);
}

function sessionRequest(): Request
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos'))->first()[0];
}

it('starts a resumable upload with the title, description, tags and status, then uploads the video', function () {
    fakeYouTube();

    $result = $this->publisher->publish($this->target);

    expect($result->externalId)->toBe('vid123')
        ->and($result->externalUrl)->toBe('https://www.youtube.com/watch?v=vid123')
        ->and($result->notice)->toBeNull();

    $start = sessionRequest();
    expect($start->url())->toContain('uploadType=resumable')
        ->and($start->url())->toContain('part=snippet%2Cstatus')
        ->and($start->url())->toContain('notifySubscribers=true')
        ->and($start->hasHeader('Authorization', 'Bearer yt-token'))->toBeTrue()
        ->and($start->hasHeader('X-Upload-Content-Length', '10'))->toBeTrue()
        ->and($start->hasHeader('X-Upload-Content-Type', 'video/mp4'))->toBeTrue()
        ->and($start->data())->toBe([
            'snippet' => [
                'title' => 'Form Requests in Laravel',
                'description' => 'How Form Requests keep controllers thin ‹3',
                'tags' => ['laravel', 'php', 'form requests'],
                'categoryId' => '28',
            ],
            'status' => [
                'privacyStatus' => 'unlisted',
                'selfDeclaredMadeForKids' => false,
                'containsSyntheticMedia' => false,
            ],
        ]);

    Http::assertSent(fn (Request $request) => $request->url() === YT_SESSION
        && $request->hasHeader('Content-Range', 'bytes 0-9/10')
        && $request->body() === str_repeat('v', 10));
});

it('uploads a big video in 256 KB chunks and follows the Range YouTube reports', function () {
    $size = YouTubeUploader::CHUNK_UNIT_BYTES * 2 + 100;
    Storage::disk('local')->put($this->video->path, str_repeat('x', $size));
    fakeYouTube(session: Http::sequence()
        ->push('', 308, ['Range' => 'bytes=0-262143'])
        ->push('', 308, ['Range' => 'bytes=0-524287'])
        ->push(['id' => 'big1'], 200));

    expect($this->publisher->publish($this->target)->externalId)->toBe('big1');

    $ranges = Http::recorded(fn (Request $request) => $request->url() === YT_SESSION)
        ->values()->map(fn (array $pair) => $pair[0]->header('Content-Range')[0])
        ->all();

    expect($ranges)->toBe([
        'bytes 0-262143/'.$size,
        'bytes 262144-524287/'.$size,
        'bytes 524288-'.($size - 1).'/'.$size,
    ]);
});

it('asks YouTube what arrived after a failed chunk and resumes from there', function () {
    fakeYouTube(session: Http::sequence()
        ->push('', 503)
        ->push('', 308, ['Range' => 'bytes=0-3'])
        ->push(['id' => 'resumed1'], 201));

    expect($this->publisher->publish($this->target)->externalId)->toBe('resumed1');

    $sessionCalls = Http::recorded(fn (Request $request) => $request->url() === YT_SESSION)->values()->map(fn (array $pair) => $pair[0]->header('Content-Range')[0])->all();

    expect($sessionCalls)->toBe(['bytes 0-9/10', 'bytes */10', 'bytes 4-9/10']);
    Sleep::assertSleptTimes(1);
});

it('posts a Short with #Shorts and a shorts link', function () {
    fakeYouTube();
    $this->target->update(['settings' => [...$this->target->settings, 'format' => 'short']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalUrl)->toBe('https://www.youtube.com/shorts/vid123')
        ->and(sessionRequest()['snippet']['description'])->toEndWith("\n\n#Shorts");
});

it('treats a vertical video under 3 minutes as a Short on Auto', function () {
    fakeYouTube();
    $this->video->update(['width' => 1080, 'height' => 1920, 'duration_seconds' => '45.00']);

    expect($this->publisher->publish($this->target->fresh())->externalUrl)->toBe('https://www.youtube.com/shorts/vid123');
});

it('sets the chosen thumbnail after the upload', function () {
    $thumbnail = Media::factory()->for($this->workspace)->create(['mime_type' => 'image/png']);
    Storage::disk('local')->put($thumbnail->path, 'png-bytes');
    $this->target->update(['settings' => [...$this->target->settings, 'thumbnail_media_id' => $thumbnail->id]]);
    fakeYouTube([YT_THUMBNAIL => Http::response(['items' => []])]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->notice)->toBeNull();
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=vid123')
        && $request->body() === 'png-bytes'
        && $request->hasHeader('Content-Type', 'image/png'));
});

it('still publishes when YouTube refuses the custom thumbnail, with a notice', function () {
    $thumbnail = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($thumbnail->path, 'jpg');
    $this->target->update(['settings' => [...$this->target->settings, 'thumbnail_media_id' => $thumbnail->id]]);
    fakeYouTube([YT_THUMBNAIL => Http::response(['error' => ['message' => 'forbidden', 'errors' => [['reason' => 'forbidden']]]], 403)]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('vid123')
        ->and($result->notice)->toContain('Verify the channel with a phone number');
});

it('waits until YouTube has processed a Short before setting its cover', function () {
    $cover = Media::factory()->for($this->workspace)->create(['mime_type' => 'image/png']);
    Storage::disk('local')->put($cover->path, 'cover');
    $this->target->update(['settings' => [...$this->target->settings, 'format' => 'short', 'thumbnail_media_id' => $cover->id]]);
    fakeYouTube([
        'https://www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->push(['items' => [['processingDetails' => ['processingStatus' => 'processing']]]])
            ->push(['items' => [['processingDetails' => ['processingStatus' => 'succeeded']]]]),
        YT_THUMBNAIL => Http::response(['items' => []]),
    ]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->notice)->toBeNull();
    Sleep::assertSleptTimes(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/videos?part=processingDetails&id=vid123'));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=vid123'));
});

it('publishes a Short without its cover when YouTube is still processing, with a notice', function () {
    config(['services.google.processing_wait_seconds' => 20, 'services.google.processing_poll_seconds' => 10]);
    $cover = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($cover->path, 'cover');
    $this->target->update(['settings' => [...$this->target->settings, 'format' => 'short', 'thumbnail_media_id' => $cover->id]]);
    fakeYouTube(['https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['processingDetails' => ['processingStatus' => 'processing']]]])]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalUrl)->toBe('https://www.youtube.com/shorts/vid123')
        ->and($result->notice)->toContain('Short cover wasn\'t set');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'thumbnails/set'));
});

it('turns audio into a video and uploads that', function () {
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'mp4');

        return Process::result();
    });
    fakeYouTube();
    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'path' => 'media/test/ep.mp3', 'original_name' => 'ep.mp3', 'mime_type' => 'audio/mpeg']);
    Storage::disk('local')->put($audio->path, 'mp3');
    $this->post->media()->sync([$audio->id => ['sort_order' => 0]]);

    $this->publisher->publish($this->target->fresh());

    expect(sessionRequest()->hasHeader('X-Upload-Content-Type', 'video/mp4'))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === YT_SESSION && $request->body() === 'mp4');
});

it('refreshes an expired token before uploading', function () {
    $this->channel->update(['token_expires_at' => now()->subMinute()]);
    fakeYouTube(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3599])]);

    $this->publisher->publish($this->target->fresh());

    expect(sessionRequest()->hasHeader('Authorization', 'Bearer fresh-token'))->toBeTrue();
});

it('refreshes once and retries when YouTube says 401, then marks reconnect if it happens again', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3599]),
        YT_UPLOAD => Http::response(['error' => ['message' => 'Invalid Credentials', 'errors' => [['reason' => 'authError']]]], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (YouTubeApiException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('Reconnect'));

    expect($this->channel->fresh()->needs_reconnect)->toBeTrue();
    Http::assertSentCount(3);
});

it('maps YouTube errors to retryable or permanent', function (int $status, ?string $reason, bool $retryable, string $message) {
    Http::fake([YT_UPLOAD => Http::response(['error' => ['message' => 'Nope', 'errors' => [['reason' => $reason]]]], $status)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (YouTubeApiException $exception) => expect($exception->isRetryable)->toBe($retryable)
            ->and($exception->getMessage())->toContain($message));
})->with([
    'daily quota' => [403, 'quotaExceeded', false, 'Retry tomorrow'],
    'upload limit' => [400, 'uploadLimitExceeded', false, 'Retry tomorrow'],
    'bad title' => [400, 'invalidTitle', false, 'invalidTitle'],
    'forbidden' => [403, 'forbidden', false, 'tick both YouTube permissions'],
    'server error' => [500, 'backendError', true, '(500)'],
    'rate limited' => [429, 'rateLimitExceeded', false, 'Retry tomorrow'],
]);

it('fails clearly when the YouTube title is empty', function () {
    $this->target->update(['settings' => [...$this->target->settings, 'title' => '  ']]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))->toThrow(Exception::class, 'YouTube title is empty');
});

it('publishes end to end through the job and keeps the thumbnail notice', function () {
    $thumbnail = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($thumbnail->path, 'jpg');
    $this->target->update(['settings' => [...$this->target->settings, 'thumbnail_media_id' => $thumbnail->id]]);
    fakeYouTube([YT_THUMBNAIL => Http::response([], 500)]);

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    $target = $this->target->fresh();

    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->external_url)->toBe('https://www.youtube.com/watch?v=vid123')
        ->and($target->last_error)->toStartWith('Published, but the thumbnail was refused (500)')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});
