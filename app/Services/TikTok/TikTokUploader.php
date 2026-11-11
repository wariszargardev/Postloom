<?php

namespace App\Services\TikTok;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class TikTokUploader
{
    public const SINGLE_CHUNK_MAX_BYTES = 64 * 1024 * 1024;

    public const MIN_CHUNK_BYTES = 5 * 1024 * 1024;

    public const CHUNK_RETRIES = 2;

    public const DIRECT_INIT_PATH = 'post/publish/video/init/';

    public const INBOX_INIT_PATH = 'post/publish/inbox/video/init/';

    public const STATUS_PATH = 'post/publish/status/fetch/';

    public const CREATOR_INFO_PATH = 'post/publish/creator_info/query/';

    public function __construct(private TikTokApiClient $api) {}

    /**
     * What TikTok allows this account to post right now (privacy options, max length, disabled interactions).
     *
     * @return array<string, mixed>
     *
     * @throws TikTokApiException|TikTokTokenException
     */
    public function creatorInfo(SocialAccount $account): array
    {
        return $this->api->post($account, self::CREATOR_INFO_PATH, [], 'check what this account can post');
    }

    /**
     * Start a FILE_UPLOAD (direct post when $postInfo is given, inbox draft otherwise) and send the file in chunks.
     *
     * @param  array<string, mixed>|null  $postInfo
     * @return string TikTok's publish_id.
     *
     * @throws TikTokApiException|TikTokTokenException
     */
    public function upload(SocialAccount $account, string $path, string $mimeType, ?array $postInfo): string
    {
        $size = (int) filesize($path);

        if ($size < 1) {
            throw new TikTokApiException('The video file is empty.', false);
        }
        [$chunkSize, $chunkCount] = self::chunkPlan($size);

        $body = ['source_info' => [
            'source' => 'FILE_UPLOAD',
            'video_size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunk_count' => $chunkCount,
        ]];

        if ($postInfo !== null) {
            $body['post_info'] = $postInfo;
        }

        $data = $this->api->post($account, $postInfo === null ? self::INBOX_INIT_PATH : self::DIRECT_INIT_PATH, $body, 'start the upload');
        $publishId = (string) ($data['publish_id'] ?? '');
        $uploadUrl = (string) ($data['upload_url'] ?? '');

        if ($publishId === '' || $uploadUrl === '') {
            throw new TikTokApiException('TikTok did not return an upload address. Will retry.', true);
        }

        LogService::info('TikTok upload started', [
            'publish_id' => $publishId,
            'size_bytes' => $size,
            'chunks' => $chunkCount,
            'mode' => $postInfo === null ? 'inbox' : 'direct',
        ]);

        $this->sendChunks($uploadUrl, $path, $mimeType, $size, $chunkSize, $chunkCount);

        LogService::info('TikTok upload finished', ['publish_id' => $publishId]);

        return $publishId;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TikTokApiException|TikTokTokenException
     */
    public function status(SocialAccount $account, string $publishId): array
    {
        return $this->api->post($account, self::STATUS_PATH, ['publish_id' => $publishId], 'check the post status');
    }

    /**
     * Poll the status until TikTok finishes (published, sent to inbox or failed) or the wait runs out.
     * Polling errors are not thrown: the video is already with TikTok, so a retry would post it twice.
     *
     * @return array<string, mixed>|null The last status, or null when TikTok never answered.
     */
    public function waitForResult(SocialAccount $account, string $publishId): ?array
    {
        $waitSeconds = (int) config('services.tiktok.status_wait_seconds', 180);
        $pollSeconds = max(1, (int) config('services.tiktok.status_poll_seconds', 10));
        $deadline = now()->addSeconds($waitSeconds);
        $last = null;

        do {
            try {
                $last = $this->status($account, $publishId);
            } catch (TikTokApiException|TikTokTokenException $exception) {
                LogService::warning('TikTok status check failed', ['publish_id' => $publishId], $exception);
            }

            $status = (string) ($last['status'] ?? '');

            LogService::info('TikTok post status', ['publish_id' => $publishId, 'status' => $status]);

            if (in_array($status, ['PUBLISH_COMPLETE', 'SEND_TO_USER_INBOX', 'FAILED'], true)) {
                return $last;
            }

            if (now()->addSeconds($pollSeconds)->isAfter($deadline)) {
                return $last;
            }

            Sleep::for($pollSeconds)->seconds();
        } while (true);
    }

    /**
     * Chunk size and count by TikTok's rules: up to 64 MB goes as one chunk; bigger files use
     * TIKTOK_UPLOAD_CHUNK_BYTES chunks (5–64 MB) and the last chunk takes the remainder.
     *
     * @return array{0: int, 1: int}
     */
    public static function chunkPlan(int $size): array
    {
        if ($size <= self::SINGLE_CHUNK_MAX_BYTES) {
            return [$size, 1];
        }

        $chunkSize = min(self::SINGLE_CHUNK_MAX_BYTES, max(self::MIN_CHUNK_BYTES, (int) config('services.tiktok.upload_chunk_bytes', 10 * 1024 * 1024)));

        return [$chunkSize, intdiv($size, $chunkSize)];
    }

    /**
     * @throws TikTokApiException
     */
    private function sendChunks(string $uploadUrl, string $path, string $mimeType, int $size, int $chunkSize, int $chunkCount): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new TikTokApiException('The video file could not be read.', false);
        }

        try {
            for ($index = 0; $index < $chunkCount; $index++) {
                $start = $index * $chunkSize;
                $end = $index === $chunkCount - 1 ? $size - 1 : $start + $chunkSize - 1;

                $length = $end - $start + 1;

                if ($length < 1) {
                    throw new TikTokApiException("Upload chunk {$index} has no bytes.", false);
                }

                fseek($handle, $start);
                $bytes = (string) fread($handle, $length);

                $this->sendChunk($uploadUrl, $bytes, $mimeType, $start, $end, $size, $index + 1);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws TikTokApiException
     */
    private function sendChunk(string $uploadUrl, string $bytes, string $mimeType, int $start, int $end, int $size, int $number): void
    {
        for ($try = 0; $try <= self::CHUNK_RETRIES; $try++) {
            try {
                $response = Http::withHeaders([
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                    'Content-Length' => (string) strlen($bytes),
                ])->withBody($bytes, $mimeType)->timeout(300)->put($uploadUrl);
            } catch (ConnectionException $exception) {
                LogService::warning('TikTok upload chunk lost', ['chunk' => $number], $exception);
                $response = null;
            }

            if ($response !== null && in_array($response->status(), [200, 201, 206], true)) {
                return;
            }

            if ($response !== null && $response->status() < 500) {
                LogService::warning('TikTok refused an upload chunk', ['chunk' => $number, 'status' => $response->status()]);

                throw new TikTokApiException(
                    $response->status() === 403
                        ? 'The TikTok upload address expired. Will retry from the start.'
                        : "TikTok refused upload chunk {$number} ({$response->status()}). Will retry from the start.",
                    true,
                    $response->status(),
                );
            }

            if ($try < self::CHUNK_RETRIES) {
                Sleep::for(2 ** ($try + 1))->seconds();
            }
        }

        throw new TikTokApiException("TikTok upload chunk {$number} kept failing. Will retry from the start.", true);
    }
}
