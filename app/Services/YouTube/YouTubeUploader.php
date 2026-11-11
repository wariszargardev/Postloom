<?php

namespace App\Services\YouTube;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class YouTubeUploader
{
    public const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public const THUMBNAIL_URL = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    /**
     * YouTube wants every chunk but the last to be a multiple of 256 KB.
     */
    public const CHUNK_UNIT_BYTES = 256 * 1024;

    public const MAX_CHUNK_RETRIES = 3;

    /**
     * YouTube error reasons that mean "no more uploads for now"; a retry within minutes won't help.
     *
     * @var array<int, string>
     */
    public const LIMIT_REASONS = ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'];

    public function __construct(private YouTubeTokenService $tokens) {}

    /**
     * Upload a video with the resumable protocol and return its id.
     *
     * @param  array<string, mixed>  $metadata  the videos resource (snippet and status)
     *
     * @throws YouTubeApiException
     * @throws YouTubeTokenException
     */
    public function upload(SocialAccount $account, string $absolutePath, string $mimeType, array $metadata, bool $notifySubscribers): string
    {
        $sizeBytes = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;

        if ($sizeBytes === 0) {
            throw new YouTubeApiException('The video file is missing or empty.', false);
        }

        $sessionUrl = $this->startSession($account, $sizeBytes, $mimeType, $metadata, $notifySubscribers);

        LogService::info('YouTube upload started', ['size_bytes' => $sizeBytes, 'chunks' => (int) ceil($sizeBytes / $this->chunkBytes())]);

        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new YouTubeApiException('The video file could not be opened.', false);
        }

        try {
            $videoId = $this->sendChunks($account, $sessionUrl, $stream, $sizeBytes, $mimeType);
        } finally {
            fclose($stream);
        }

        LogService::info('YouTube upload finished', ['video_id' => $videoId]);

        return $videoId;
    }

    /**
     * Poll videos.list until YouTube has processed the video (a Short's cover can only be set after that).
     * Returns false when processing failed or didn't finish in time; never throws.
     */
    public function waitUntilProcessed(SocialAccount $account, string $videoId): bool
    {
        $waitSeconds = (int) config('services.google.processing_wait_seconds');
        $pollSeconds = max(1, (int) config('services.google.processing_poll_seconds'));
        $checks = max(1, intdiv($waitSeconds, $pollSeconds));

        for ($check = 1; $check <= $checks; $check++) {
            try {
                $response = $this->authorized($account)
                    ->timeout(30)
                    ->get(config('services.google.youtube_api_base_url').'/videos', ['part' => 'processingDetails', 'id' => $videoId]);
            } catch (ConnectionException|YouTubeTokenException $exception) {
                LogService::warning('YouTube processing check failed', ['video_id' => $videoId], $exception);

                return false;
            }

            $status = (string) $response->json('items.0.processingDetails.processingStatus');

            if ($status === 'succeeded') {
                LogService::info('YouTube finished processing the video', ['video_id' => $videoId, 'checks' => $check]);

                return true;
            }

            if (in_array($status, ['failed', 'terminated'], true)) {
                LogService::warning('YouTube could not process the video', ['video_id' => $videoId, 'status' => $status]);

                return false;
            }

            if ($check < $checks) {
                Sleep::for($pollSeconds)->seconds();
            }
        }

        LogService::warning('YouTube is still processing the video, cover not set', ['video_id' => $videoId, 'waited_seconds' => $waitSeconds]);

        return false;
    }

    /**
     * Set a custom thumbnail. Returns null when it worked, otherwise a short reason; a failed thumbnail never fails the post.
     */
    public function setThumbnail(SocialAccount $account, string $videoId, string $bytes, string $mimeType): ?string
    {
        try {
            $response = $this->authorized($account)
                ->withBody($bytes, $mimeType)
                ->timeout(120)
                ->post(self::THUMBNAIL_URL.'?videoId='.rawurlencode($videoId));
        } catch (ConnectionException|YouTubeTokenException $exception) {
            LogService::warning('YouTube thumbnail could not be set', ['video_id' => $videoId], $exception);

            return 'the thumbnail could not be uploaded (connection problem). Set it in YouTube Studio.';
        }

        if ($response->successful()) {
            LogService::info('YouTube thumbnail set', ['video_id' => $videoId]);

            return null;
        }

        $reason = (string) ($response->json('error.errors.0.reason') ?? '');
        LogService::warning('YouTube thumbnail could not be set', [
            'video_id' => $videoId,
            'status' => $response->status(),
            'reason' => $reason,
            'youtube_message' => mb_substr((string) $response->json('error.message'), 0, 300),
        ]);

        return $response->status() === 403
            ? 'the custom thumbnail was refused. Verify the channel with a phone number (youtube.com/verify) to use custom thumbnails, then set it in YouTube Studio.'
            : "the thumbnail was refused ({$response->status()}). Set it in YouTube Studio.";
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws YouTubeApiException
     * @throws YouTubeTokenException
     */
    private function startSession(SocialAccount $account, int $sizeBytes, string $mimeType, array $metadata, bool $notifySubscribers): string
    {
        $url = self::UPLOAD_URL.'?'.http_build_query([
            'uploadType' => 'resumable',
            'part' => 'snippet,status',
            'notifySubscribers' => $notifySubscribers ? 'true' : 'false',
        ]);

        $send = fn (): Response => $this->authorized($account)
            ->withHeaders(['X-Upload-Content-Length' => (string) $sizeBytes, 'X-Upload-Content-Type' => $mimeType])
            ->asJson()
            ->timeout(60)
            ->post($url, $metadata);

        $response = $this->call($send, 'start the upload');

        if ($response->status() === 401) {
            $this->tokens->refresh($account);
            $response = $this->call($send, 'start the upload');
        }

        if (! $response->successful()) {
            throw $this->errorFor($account, $response, 'start the upload');
        }

        $sessionUrl = (string) $response->header('Location');

        if ($sessionUrl === '') {
            throw new YouTubeApiException('YouTube did not return an upload address.', true);
        }

        return $sessionUrl;
    }

    /**
     * @param  resource  $stream
     *
     * @throws YouTubeApiException
     */
    private function sendChunks(SocialAccount $account, string $sessionUrl, $stream, int $sizeBytes, string $mimeType): string
    {
        $offset = 0;
        $failures = 0;

        while (true) {
            $length = min($this->chunkBytes(), $sizeBytes - $offset);
            $bytes = (string) stream_get_contents($stream, $length, $offset);
            $lastByte = $offset + $length - 1;

            try {
                $response = Http::withoutRedirecting()
                    ->timeout(300)
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$lastByte}/{$sizeBytes}"])
                    ->withBody($bytes, $mimeType)
                    ->put($sessionUrl);
            } catch (ConnectionException $exception) {
                $response = null;
                LogService::warning('YouTube upload chunk lost, checking what arrived', ['offset' => $offset], $exception);
            }

            if ($response !== null && in_array($response->status(), [200, 201], true)) {
                $videoId = (string) $response->json('id');

                if ($videoId === '') {
                    throw new YouTubeApiException('YouTube finished the upload but did not return the video id.', false);
                }

                return $videoId;
            }

            if ($response !== null && $response->status() === 308) {
                $nextOffset = $this->nextOffset($response);

                if ($nextOffset > $offset) {
                    $offset = $nextOffset;
                    $failures = 0;

                    continue;
                }
            }

            if ($response !== null && $response->status() === 404) {
                throw new YouTubeApiException('The YouTube upload session expired; the next try starts again.', true, 404);
            }

            if ($response !== null && $response->status() !== 308 && ! $response->serverError()) {
                throw $this->errorFor($account, $response, 'upload the video');
            }

            $failures++;

            if ($failures > self::MAX_CHUNK_RETRIES) {
                throw new YouTubeApiException('The YouTube upload kept failing; it will be tried again later.', true, $response?->status());
            }

            Sleep::for(2 ** $failures)->seconds();
            $offset = $this->askOffset($sessionUrl, $sizeBytes);
        }
    }

    /**
     * After a failed chunk, ask YouTube how many bytes it has.
     *
     * @throws YouTubeApiException
     */
    private function askOffset(string $sessionUrl, int $sizeBytes): int
    {
        try {
            $response = Http::withoutRedirecting()
                ->timeout(60)
                ->withHeaders(['Content-Range' => "bytes */{$sizeBytes}"])
                ->withBody('', 'application/octet-stream')
                ->put($sessionUrl);
        } catch (ConnectionException $exception) {
            throw new YouTubeApiException('YouTube could not be reached during the upload.', true, previous: $exception);
        }

        return match (true) {
            $response->status() === 308 => $this->nextOffset($response),
            $response->status() === 404 => throw new YouTubeApiException('The YouTube upload session expired; the next try starts again.', true, 404),
            default => 0,
        };
    }

    private function chunkBytes(): int
    {
        $configured = (int) config('services.google.upload_chunk_bytes');

        return max(1, intdiv($configured, self::CHUNK_UNIT_BYTES)) * self::CHUNK_UNIT_BYTES;
    }

    private function nextOffset(Response $response): int
    {
        $range = (string) $response->header('Range');

        return preg_match('/bytes=0-(\d+)/', $range, $matches) === 1 ? (int) $matches[1] + 1 : 0;
    }

    /**
     * @param  callable(): Response  $send
     *
     * @throws YouTubeApiException
     */
    private function call(callable $send, string $action): Response
    {
        try {
            return $send();
        } catch (ConnectionException $exception) {
            LogService::warning('YouTube could not be reached', ['action' => $action], $exception);

            throw new YouTubeApiException("YouTube could not be reached while trying to {$action}.", true, previous: $exception);
        }
    }

    /**
     * @throws YouTubeTokenException
     */
    private function authorized(SocialAccount $account): PendingRequest
    {
        return Http::withToken($this->tokens->accessTokenFor($account))->acceptJson()->connectTimeout(10);
    }

    private function errorFor(SocialAccount $account, Response $response, string $action): YouTubeApiException
    {
        $status = $response->status();
        $reason = $response->json('error.errors.0.reason');
        $reason = $reason === null ? null : (string) $reason;
        $youTubeMessage = (string) ($response->json('error.message') ?? '');
        $detail = $youTubeMessage !== '' ? ": {$youTubeMessage}" : '.';

        LogService::warning('YouTube returned an error', [
            'action' => $action,
            'status' => $status,
            'reason' => $reason,
            'youtube_message' => mb_substr($youTubeMessage, 0, 500),
        ]);

        if ($status === 401) {
            $account->update(['needs_reconnect' => true]);

            return new YouTubeApiException("YouTube rejected the login for {$account->name}. Reconnect it on the Accounts page, then retry.", false, $status, $reason);
        }

        if (in_array($reason, self::LIMIT_REASONS, true)) {
            return new YouTubeApiException("YouTube's upload limit is reached ({$reason}). Retry tomorrow; the limit resets at midnight Pacific time.", false, $status, $reason);
        }

        return match (true) {
            $status === 403 => new YouTubeApiException("YouTube refused to {$action} (403{$this->reasonText($reason)}){$detail} Reconnect the channel and tick both YouTube permissions.", false, $status, $reason),
            $status === 429 => new YouTubeApiException('YouTube is rate limiting uploads (429).', true, $status, $reason),
            $status >= 500 => new YouTubeApiException("YouTube had a problem while trying to {$action} ({$status}){$detail}", true, $status, $reason),
            default => new YouTubeApiException("YouTube rejected the video ({$status}{$this->reasonText($reason)}){$detail}", false, $status, $reason),
        };
    }

    private function reasonText(?string $reason): string
    {
        return $reason === null ? '' : ", {$reason}";
    }
}
