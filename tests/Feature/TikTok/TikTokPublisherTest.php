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
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use App\Services\TikTok\TikTokApiException;
use App\Services\TikTok\TikTokPublisher;
use App\Services\TikTok\TikTokUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const TTP_API = 'https://open.tiktokapis.com/v2';
const TTP_UPLOAD = 'https://open-upload.tiktokapis.com/video/?upload_id=777';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake(syncWithCarbon: true);
    config([
        'services.tiktok.client_key' => 'tiktok-client-key',
        'services.tiktok.client_secret' => 'tiktok-client-secret',
        'services.tiktok.status_wait_seconds' => 30,
        'services.tiktok.status_poll_seconds' => 10,
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->profile = SocialAccount::factory()->for($this->workspace)->tikTok()->create([
        'handle' => 'waris.zargar2',
        'access_token' => 'act.valid',
        'token_expires_at' => now()->addHours(2),
    ]);
    $this->post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Publishing, 'scheduled_at' => now(), 'content' => 'Hello TikTok #laravel']);
    $this->target = PostTarget::factory()->for($this->post)->for($this->profile)->create(['settings' => ['mode' => 'inbox']]);
    $this->video = Media::factory()->video()->for($this->workspace)->create(['size_bytes' => 10, 'duration_seconds' => '30.00']);
    Storage::disk('local')->put($this->video->path, 'video-byte');
    $this->post->media()->attach($this->video, ['sort_order' => 0]);
    $this->publisher = app(TikTokPublisher::class);
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function tikTokOk(array $data): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-ok']];
}

/**
 * @param  array<int, array<string, mixed>>  $statuses
 * @param  array<string, mixed>  $extra
 */
function fakeTikTokPosting(array $statuses, array $extra = []): void
{
    $statusSequence = Http::sequence();

    foreach ($statuses as $status) {
        $statusSequence->push(tikTokOk($status));
    }

    Http::fake([
        TTP_API.'/post/publish/creator_info/query/' => Http::response(tikTokOk([
            'creator_nickname' => 'Waris Zargar',
            'privacy_level_options' => ['SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => true,
            'max_video_post_duration_sec' => 600,
        ])),
        TTP_API.'/post/publish/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_pub_1', 'upload_url' => TTP_UPLOAD])),
        TTP_API.'/post/publish/inbox/video/init/' => Http::response(tikTokOk(['publish_id' => 'v_inbox_1', 'upload_url' => TTP_UPLOAD])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        TTP_API.'/post/publish/status/fetch/' => $statusSequence,
        ...$extra,
    ]);
}

it('sends a video to the TikTok inbox and keeps the caption for pasting', function () {
    fakeTikTokPosting([['status' => 'PROCESSING_UPLOAD'], ['status' => 'SEND_TO_USER_INBOX']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('v_inbox_1')
        ->and($result->externalUrl)->toBe('https://www.tiktok.com/@waris.zargar2')
        ->and($result->notice)->toContain('Sent to your TikTok inbox')
        ->and($result->notice)->toContain('Caption to paste: Hello TikTok #laravel');

    Http::assertSent(fn (Request $request) => $request->url() === TTP_API.'/post/publish/inbox/video/init/'
        && $request['source_info'] === ['source' => 'FILE_UPLOAD', 'video_size' => 10, 'chunk_size' => 10, 'total_chunk_count' => 1]
        && ! isset($request['post_info'])
        && $request->hasHeader('Authorization', 'Bearer act.valid'));
    Http::assertSent(fn (Request $request) => $request->url() === TTP_UPLOAD
        && $request->method() === 'PUT'
        && $request->hasHeader('Content-Range', 'bytes 0-9/10')
        && $request->hasHeader('Content-Type', 'video/mp4')
        && $request->body() === 'video-byte');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'creator_info'));
    Sleep::assertSleptTimes(1);
});

it('posts directly with the caption, privacy and interaction settings', function () {
    fakeTikTokPosting([['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]]);
    $this->target->update(['settings' => ['mode' => 'direct', 'privacy' => 'SELF_ONLY', 'allow_comments' => true, 'allow_duet' => false, 'allow_stitch' => true, 'ai_generated' => true, 'commercial' => 'none']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('v_pub_1')
        ->and($result->notice)->toContain('Published as "Only me"');

    Http::assertSent(fn (Request $request) => $request->url() === TTP_API.'/post/publish/video/init/'
        && $request['post_info']['title'] === 'Hello TikTok #laravel'
        && $request['post_info']['privacy_level'] === 'SELF_ONLY'
        && $request['post_info']['disable_comment'] === false
        && $request['post_info']['disable_duet'] === true
        && $request['post_info']['disable_stitch'] === true
        && $request['post_info']['is_aigc'] === true
        && $request['post_info']['brand_content_toggle'] === false);
});

it('links to the public video once TikTok returns its post id', function () {
    fakeTikTokPosting([['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7312345678901234567]]]);
    $this->target->update(['settings' => ['mode' => 'direct', 'privacy' => 'SELF_ONLY']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('7312345678901234567')
        ->and($result->externalUrl)->toBe('https://www.tiktok.com/@waris.zargar2/video/7312345678901234567')
        ->and($result->notice)->toBeNull();
});

it('refuses a privacy option TikTok does not allow for the account', function () {
    fakeTikTokPosting([]);
    $this->target->update(['settings' => ['mode' => 'direct', 'privacy' => 'PUBLIC_TO_EVERYONE']]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('Allowed: Only me'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/video/init/'));
});

it('fails for good when TikTok reports FAILED after the upload', function () {
    fakeTikTokPosting([['status' => 'FAILED', 'fail_reason' => 'file_format_check_failed']]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('file_format_check_failed'));
});

it('counts the post as sent when TikTok is still processing after the wait', function () {
    fakeTikTokPosting([['status' => 'PROCESSING_UPLOAD'], ['status' => 'PROCESSING_UPLOAD'], ['status' => 'PROCESSING_UPLOAD']]);
    $this->target->update(['settings' => ['mode' => 'direct', 'privacy' => 'SELF_ONLY']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->externalId)->toBe('v_pub_1')
        ->and($result->notice)->toContain('still processing');
});

it('explains the private-account rule for unaudited apps', function () {
    fakeTikTokPosting([], [TTP_API.'/post/publish/video/init/' => Http::response(['data' => [], 'error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts', 'message' => 'Please review our integration guidelines', 'log_id' => 'log-9']], 403)]);
    $this->target->update(['settings' => ['mode' => 'direct', 'privacy' => 'SELF_ONLY']]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (TikTokApiException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->logId)->toBe('log-9')
            ->and($exception->getMessage())->toContain('Set the TikTok account to private'));
});

it('retries rate limits and server errors', function (int $status, string $code) {
    fakeTikTokPosting([], [TTP_API.'/post/publish/inbox/video/init/' => Http::response(['data' => [], 'error' => ['code' => $code, 'message' => 'Busy']], $status)]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))
        ->toThrow(fn (TikTokApiException $exception) => expect($exception->isRetryable)->toBeTrue());
})->with([
    'rate limit' => [429, 'rate_limit_exceeded'],
    'server error' => [500, 'internal_error'],
]);

it('refreshes a rejected token once and repeats the call', function () {
    fakeTikTokPosting([['status' => 'SEND_TO_USER_INBOX']], [
        TTP_API.'/oauth/token/' => Http::response(['access_token' => 'act.fresh', 'refresh_token' => 'rft.fresh', 'expires_in' => 86400]),
        TTP_API.'/post/publish/inbox/video/init/' => Http::sequence()
            ->push(['data' => [], 'error' => ['code' => 'access_token_invalid', 'message' => 'expired']], 401)
            ->push(tikTokOk(['publish_id' => 'v_inbox_2', 'upload_url' => TTP_UPLOAD])),
    ]);

    expect($this->publisher->publish($this->target->fresh())->externalId)->toBe('v_inbox_2');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/inbox/video/init/') && $request->hasHeader('Authorization', 'Bearer act.fresh'));
    expect($this->profile->fresh()->refresh_token)->toBe('rft.fresh')
        ->and($this->profile->fresh()->needs_reconnect)->toBeFalse();
});

it('marks the account for reconnect when TikTok withdrew the permission', function () {
    fakeTikTokPosting([], [TTP_API.'/post/publish/inbox/video/init/' => Http::response(['data' => [], 'error' => ['code' => 'scope_not_authorized', 'message' => 'no scope']], 403)]);

    expect(fn () => $this->publisher->publish($this->target->fresh()))->toThrow(TikTokApiException::class);
    expect($this->profile->fresh()->needs_reconnect)->toBeTrue();
});

it('turns audio into a video before uploading', function () {
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'mp4-bytes');

        return Process::result();
    });
    fakeTikTokPosting([['status' => 'SEND_TO_USER_INBOX']]);
    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg', 'path' => 'media/test/episode.mp3']);
    Storage::disk('local')->put($audio->path, 'mp3');
    $this->post->media()->sync([$audio->id => ['sort_order' => 0]]);

    $this->publisher->publish($this->target->fresh());

    Http::assertSent(fn (Request $request) => $request->url() === TTP_UPLOAD && $request->body() === 'mp4-bytes' && $request->hasHeader('Content-Type', 'video/mp4'));
});

it('splits big files into chunks by TikTok\'s rules', function () {
    config(['services.tiktok.upload_chunk_bytes' => 10 * 1024 * 1024]);

    expect(TikTokUploader::chunkPlan(3 * 1024 * 1024))->toBe([3 * 1024 * 1024, 1])
        ->and(TikTokUploader::chunkPlan(64 * 1024 * 1024))->toBe([64 * 1024 * 1024, 1])
        ->and(TikTokUploader::chunkPlan(105 * 1024 * 1024))->toBe([10 * 1024 * 1024, 10]);

    config(['services.tiktok.upload_chunk_bytes' => 1024]);
    expect(TikTokUploader::chunkPlan(100 * 1024 * 1024)[0])->toBe(5 * 1024 * 1024);
});

it('publishes end to end through the job and keeps the inbox note', function () {
    fakeTikTokPosting([['status' => 'SEND_TO_USER_INBOX']]);

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    $target = $this->target->fresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->external_id)->toBe('v_inbox_1')
        ->and($target->last_error)->toContain('Sent to your TikTok inbox')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});

it('hands the post to you without calling TikTok when you post it yourself', function () {
    Http::fake();
    $this->target->update(['settings' => ['mode' => 'manual']]);

    $result = $this->publisher->publish($this->target->fresh());

    expect($result->needsManualPost)->toBeTrue()
        ->and($result->externalUrl)->toBeNull()
        ->and($result->notice)->toContain('To post');
    Http::assertNothingSent();
});

it('marks a hand-off as Ready for you and counts the post as delivered', function () {
    Http::fake();
    $this->target->update(['settings' => ['mode' => 'manual']]);

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    $target = $this->target->fresh();
    expect($target->status)->toBe(PostTargetStatus::ReadyToPost)
        ->and($target->published_at)->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});
