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
use App\Services\LinkedIn\LinkedInApiException;
use App\Services\LinkedIn\LinkedInPublisher;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\PublisherRegistry;
use App\Services\Publishing\PublishException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const LINKEDIN_API = 'https://api.linkedin.com/rest';

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config(['services.linkedin-openid.api_version' => '202610']);

    $this->workspace = Workspace::factory()->create();
    $this->account = SocialAccount::factory()->for($this->workspace)->create([
        'provider_account_id' => 'abc123',
        'access_token' => 'test-token',
        'name' => 'Waris',
    ]);
    $this->post = Post::factory()->for($this->workspace)->create([
        'status' => PostStatus::Publishing,
        'scheduled_at' => now(),
        'content' => 'Hello LinkedIn',
    ]);
    $this->target = PostTarget::factory()->for($this->post)->for($this->account)->create(['settings' => ['visibility' => 'PUBLIC']]);
    $this->publisher = app(LinkedInPublisher::class);
});

function fakeLinkedIn(array $extra = []): void
{
    Http::fake([
        ...$extra,
        LINKEDIN_API.'/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7001']),
    ]);
}

function attachMedia(Media ...$items): void
{
    foreach ($items as $index => $media) {
        Storage::disk('local')->put($media->path, "bytes-of-{$media->id}");
        test()->post->media()->attach($media, ['sort_order' => $index]);
    }
}

/**
 * @return array<string, mixed>
 */
function sentPostBody(): array
{
    $request = Http::recorded(fn (Request $request) => $request->url() === LINKEDIN_API.'/posts')->sole()[0];

    return $request->data();
}

it('creates a text post with the right headers and body', function () {
    fakeLinkedIn();

    $result = $this->publisher->publish($this->target);

    expect($result->externalId)->toBe('urn:li:share:7001')
        ->and($result->externalUrl)->toBe('https://www.linkedin.com/feed/update/urn:li:share:7001/');

    Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/posts'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Linkedin-Version', '202610')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));

    expect(sentPostBody())->toBe([
        'author' => 'urn:li:person:abc123',
        'commentary' => 'Hello LinkedIn',
        'visibility' => 'PUBLIC',
        'distribution' => [
            'feedDistribution' => 'MAIN_FEED',
            'targetEntities' => [],
            'thirdPartyDistributionChannels' => [],
        ],
        'lifecycleState' => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    ]);
});

it('escapes reserved characters, keeps hashtags and uses the account text and visibility', function () {
    fakeLinkedIn();
    $this->target->update(['content_override' => 'Laravel (13) is out! #Laravel @team', 'settings' => ['visibility' => 'CONNECTIONS']]);

    $this->publisher->publish($this->target->fresh());

    expect(sentPostBody()['commentary'])->toBe('Laravel \(13\) is out! {hashtag|\#|Laravel} \@team')
        ->and(sentPostBody()['visibility'])->toBe('CONNECTIONS');
});

it('uploads one image and posts it with its alt text', function () {
    fakeLinkedIn([
        LINKEDIN_API.'/images*' => Http::response(['value' => ['uploadUrl' => 'https://uploads.test/image-1', 'image' => 'urn:li:image:I1']]),
        'https://uploads.test/*' => Http::response('', 201),
    ]);
    attachMedia(Media::factory()->for($this->workspace)->create(['alt_text' => 'A card']));

    $this->publisher->publish($this->target);

    Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/images?action=initializeUpload'
        && $request['initializeUploadRequest'] === ['owner' => 'urn:li:person:abc123']);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://uploads.test/image-1'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && str_starts_with($request->body(), 'bytes-of-'));

    expect(sentPostBody()['content'])->toBe(['media' => ['id' => 'urn:li:image:I1', 'altText' => 'A card']]);
});

it('posts several images as a multi-image post in order', function () {
    fakeLinkedIn([
        LINKEDIN_API.'/images*' => Http::sequence()
            ->push(['value' => ['uploadUrl' => 'https://uploads.test/1', 'image' => 'urn:li:image:A']])
            ->push(['value' => ['uploadUrl' => 'https://uploads.test/2', 'image' => 'urn:li:image:B']])
            ->push(['value' => ['uploadUrl' => 'https://uploads.test/3', 'image' => 'urn:li:image:C']]),
        'https://uploads.test/*' => Http::response('', 201),
    ]);
    attachMedia(...Media::factory()->count(3)->for($this->workspace)->sequence(['alt_text' => 'First'], ['alt_text' => null], ['alt_text' => 'Third'])->create());

    $this->publisher->publish($this->target);

    expect(sentPostBody()['content'])->toBe(['multiImage' => ['images' => [
        ['id' => 'urn:li:image:A', 'altText' => 'First'],
        ['id' => 'urn:li:image:B'],
        ['id' => 'urn:li:image:C', 'altText' => 'Third'],
    ]]]);
});

it('uploads a video in parts, finalizes it and waits until it is available', function () {
    fakeLinkedIn([
        LINKEDIN_API.'/videos?action=initializeUpload' => Http::response(['value' => [
            'video' => 'urn:li:video:V1',
            'uploadToken' => 'token-1',
            'uploadInstructions' => [
                ['uploadUrl' => 'https://uploads.test/part-1', 'firstByte' => 0, 'lastByte' => 4],
                ['uploadUrl' => 'https://uploads.test/part-2', 'firstByte' => 5, 'lastByte' => 9],
            ],
        ]]),
        'https://uploads.test/part-1' => Http::response('', 200, ['ETag' => '"etag-1"']),
        'https://uploads.test/part-2' => Http::response('', 200, ['ETag' => '"etag-2"']),
        LINKEDIN_API.'/videos?action=finalizeUpload' => Http::response('', 200),
        LINKEDIN_API.'/videos/*' => Http::sequence()->push(['status' => 'PROCESSING'])->push(['status' => 'AVAILABLE']),
    ]);
    $video = Media::factory()->video()->for($this->workspace)->create(['original_name' => 'Demo clip.mp4']);
    $this->post->media()->attach($video, ['sort_order' => 0]);
    Storage::disk('local')->put($video->path, '0123456789');

    $this->publisher->publish($this->target);

    Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/videos?action=initializeUpload'
        && $request['initializeUploadRequest']['fileSizeBytes'] === 10
        && $request['initializeUploadRequest']['owner'] === 'urn:li:person:abc123');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://uploads.test/part-1' && $request->body() === '01234');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://uploads.test/part-2' && $request->body() === '56789');
    Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/videos?action=finalizeUpload'
        && $request['finalizeUploadRequest'] === ['video' => 'urn:li:video:V1', 'uploadToken' => 'token-1', 'uploadedPartIds' => ['etag-1', 'etag-2']]);
    Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/videos/urn%3Ali%3Avideo%3AV1');
    Sleep::assertSleptTimes(1);

    expect(sentPostBody()['content'])->toBe(['media' => ['id' => 'urn:li:video:V1', 'title' => 'Demo clip']]);
});

it('fails for good when LinkedIn cannot process the video', function () {
    Http::fake([
        LINKEDIN_API.'/videos?action=initializeUpload' => Http::response(['value' => [
            'video' => 'urn:li:video:V1',
            'uploadToken' => '',
            'uploadInstructions' => [['uploadUrl' => 'https://uploads.test/part-1', 'firstByte' => 0, 'lastByte' => 9]],
        ]]),
        'https://uploads.test/*' => Http::response('', 200, ['ETag' => 'etag-1']),
        LINKEDIN_API.'/videos?action=finalizeUpload' => Http::response('', 200),
        LINKEDIN_API.'/videos/*' => Http::response(['status' => 'PROCESSING_FAILED']),
    ]);
    $video = Media::factory()->video()->for($this->workspace)->create();
    $this->post->media()->attach($video, ['sort_order' => 0]);
    Storage::disk('local')->put($video->path, '0123456789');

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (PublishException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('could not process the video'));

    Http::assertNotSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/posts');
});

it('uploads a PDF as a document post titled with the file name', function () {
    fakeLinkedIn([
        LINKEDIN_API.'/documents*' => Http::response(['value' => ['uploadUrl' => 'https://uploads.test/doc', 'document' => 'urn:li:document:D1']]),
        'https://uploads.test/*' => Http::response('', 201),
    ]);
    attachMedia(Media::factory()->document()->for($this->workspace)->create(['original_name' => 'Laravel tips.pdf']));

    $this->publisher->publish($this->target);

    expect(sentPostBody()['content'])->toBe(['media' => ['id' => 'urn:li:document:D1', 'title' => 'Laravel tips']]);
});

it('turns audio into a video with ffmpeg, posts it and deletes the temporary file', function () {
    $convertedPath = null;
    Process::fake(function ($process) use (&$convertedPath) {
        $convertedPath = end($process->command);
        file_put_contents($convertedPath, 'mp4-bytes');

        return Process::result();
    });
    fakeLinkedIn([
        LINKEDIN_API.'/videos?action=initializeUpload' => Http::response(['value' => [
            'video' => 'urn:li:video:A1',
            'uploadToken' => '',
            'uploadInstructions' => [['uploadUrl' => 'https://uploads.test/part-1', 'firstByte' => 0, 'lastByte' => 8]],
        ]]),
        'https://uploads.test/*' => Http::response('', 200, ['ETag' => 'etag-1']),
        LINKEDIN_API.'/videos?action=finalizeUpload' => Http::response('', 200),
        LINKEDIN_API.'/videos/*' => Http::response(['status' => 'AVAILABLE']),
    ]);
    $audio = Media::factory()->for($this->workspace)->create([
        'kind' => MediaKind::Audio,
        'path' => 'media/test/episode.mp3',
        'original_name' => 'Episode 1.mp3',
        'mime_type' => 'audio/mpeg',
    ]);
    attachMedia($audio);

    $this->publisher->publish($this->target);

    Process::assertRan(fn ($process) => in_array(Storage::disk('local')->path('media/test/episode.mp3'), $process->command, true)
        && in_array('libx264', $process->command, true));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://uploads.test/part-1' && $request->body() === 'mp4-bytes');

    expect(sentPostBody()['content'])->toBe(['media' => ['id' => 'urn:li:video:A1', 'title' => 'Episode 1']])
        ->and(file_exists($convertedPath))->toBeFalse();
});

it('marks the account for reconnecting when LinkedIn rejects the token', function () {
    Http::fake([LINKEDIN_API.'/posts' => Http::response(['message' => 'Invalid access token', 'serviceErrorCode' => 65600], 401)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (LinkedInApiException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->statusCode)->toBe(401)
            ->and($exception->getMessage())->toContain('Reconnect Waris'));

    expect($this->account->fresh()->needs_reconnect)->toBeTrue();
});

it('fails for good on 403 and 422 with LinkedIn\'s message', function (int $status, string $expected) {
    Http::fake([LINKEDIN_API.'/posts' => Http::response(['message' => 'Not allowed here'], $status)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (LinkedInApiException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain($expected)
            ->and($exception->getMessage())->toContain('Not allowed here'));

    expect($this->account->fresh()->needs_reconnect)->toBeFalse();
})->with([
    'forbidden' => [403, 'Share on LinkedIn'],
    'invalid content' => [422, '(422)'],
]);

it('retries on rate limits', function () {
    Http::fake([LINKEDIN_API.'/posts' => Http::response(['message' => 'Try later'], 429)]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (LinkedInApiException $exception) => expect($exception->isRetryable)->toBeTrue());
});

it('does not retry when creating the post fails on LinkedIn\'s side, because it may already be posted', function (Closure $fake) {
    Http::fake([LINKEDIN_API.'/posts' => $fake()]);

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (PublishException $exception) => expect($exception)->not->toBeInstanceOf(LinkedInApiException::class)
            ->and($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('may already be posted'));
})->with([
    'server error 500' => [fn () => Http::response(['message' => 'Oops'], 500)],
    'server error 503' => [fn () => Http::response(['message' => 'Oops'], 503)],
    'dropped connection' => [fn () => Http::failedConnection()],
]);

it('still retries when an image upload drops before the post is created', function () {
    Http::fake([LINKEDIN_API.'/images*' => Http::failedConnection()]);
    attachMedia(Media::factory()->for($this->workspace)->create());

    expect(fn () => $this->publisher->publish($this->target))
        ->toThrow(fn (LinkedInApiException $exception) => expect($exception->isRetryable)->toBeTrue());

    Http::assertNotSent(fn (Request $request) => $request->url() === LINKEDIN_API.'/posts');
});

it('publishes end to end through the job and marks the post published', function () {
    fakeLinkedIn();

    $job = (new PublishPostTargetJob($this->target->id))->withFakeQueueInteractions();
    $job->handle(app(PublisherRegistry::class), app(PostStatusResolver::class));

    $target = $this->target->fresh();

    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->external_id)->toBe('urn:li:share:7001')
        ->and($target->external_url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:7001/')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});
