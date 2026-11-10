<?php

namespace App\Services\LinkedIn;

use App\Models\Media;
use App\Models\SocialAccount;
use App\Services\LogService;
use App\Services\Publishing\PublishException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class LinkedInMediaUploader
{
    public function __construct(private LinkedInApiClient $client) {}

    /**
     * @throws PublishException
     */
    public function uploadImage(SocialAccount $account, Media $media): string
    {
        return $this->uploadSingleFile($account, $media, '/images', 'image');
    }

    /**
     * @throws PublishException
     */
    public function uploadDocument(SocialAccount $account, Media $media): string
    {
        return $this->uploadSingleFile($account, $media, '/documents', 'document');
    }

    /**
     * Upload a video in the parts LinkedIn asks for, finalize it, then wait until LinkedIn has processed it.
     *
     * @throws PublishException
     */
    public function uploadVideo(SocialAccount $account, string $absolutePath): string
    {
        $sizeBytes = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;

        if ($sizeBytes === 0) {
            throw PublishException::permanent('The video file is missing or empty.');
        }

        LogService::info('LinkedIn video upload started', ['size_bytes' => $sizeBytes]);

        $initialized = $this->client->post($account, '/videos?action=initializeUpload', [
            'initializeUploadRequest' => [
                'owner' => $this->authorUrn($account),
                'fileSizeBytes' => $sizeBytes,
                'uploadCaptions' => false,
                'uploadThumbnail' => false,
            ],
        ], 'start a video upload');

        $videoUrn = (string) $initialized->json('value.video');
        $uploadToken = (string) ($initialized->json('value.uploadToken') ?? '');
        /** @var array<int, array{uploadUrl: string, firstByte: int, lastByte: int}> $instructions */
        $instructions = $initialized->json('value.uploadInstructions') ?? [];

        if ($videoUrn === '' || $instructions === []) {
            throw PublishException::retryable('LinkedIn did not return upload instructions for the video.');
        }

        $partIds = [];
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw PublishException::permanent('The video file could not be opened.');
        }

        try {
            foreach ($instructions as $index => $instruction) {
                $firstByte = (int) $instruction['firstByte'];
                $length = (int) $instruction['lastByte'] - $firstByte + 1;
                $bytes = (string) stream_get_contents($stream, $length, $firstByte);
                $partNumber = $index + 1;

                $response = $this->client->upload($account, $instruction['uploadUrl'], $bytes, 'application/octet-stream', false, "upload video part {$partNumber}");
                $partIds[] = trim((string) $response->header('ETag'), '"');
            }
        } finally {
            fclose($stream);
        }

        $this->client->post($account, '/videos?action=finalizeUpload', [
            'finalizeUploadRequest' => [
                'video' => $videoUrn,
                'uploadToken' => $uploadToken,
                'uploadedPartIds' => $partIds,
            ],
        ], 'finish the video upload');

        LogService::info('LinkedIn video uploaded, waiting for processing', ['media_urn' => $videoUrn, 'parts' => count($partIds)]);

        $this->waitUntilVideoIsAvailable($account, $videoUrn);

        return $videoUrn;
    }

    /**
     * @throws PublishException
     */
    private function waitUntilVideoIsAvailable(SocialAccount $account, string $videoUrn): void
    {
        $waitSeconds = (int) config('services.linkedin-openid.video_wait_seconds');
        $pollSeconds = max(1, (int) config('services.linkedin-openid.video_poll_seconds'));
        $checks = max(1, intdiv($waitSeconds, $pollSeconds));

        for ($check = 1; $check <= $checks; $check++) {
            $status = (string) $this->client
                ->get($account, '/videos/'.LinkedInApiClient::encodeUrn($videoUrn), 'check the video status')
                ->json('status');

            if ($status === 'AVAILABLE') {
                LogService::info('LinkedIn video is ready', ['media_urn' => $videoUrn, 'checks' => $check]);

                return;
            }

            if ($status === 'PROCESSING_FAILED') {
                throw PublishException::permanent('LinkedIn could not process the video. Check its length (3 seconds to 30 minutes) and format (MP4).');
            }

            if ($check < $checks) {
                Sleep::for($pollSeconds)->seconds();
            }
        }

        throw PublishException::retryable("LinkedIn is still processing the video after {$waitSeconds} seconds.");
    }

    /**
     * @throws PublishException
     */
    private function uploadSingleFile(SocialAccount $account, Media $media, string $endpoint, string $label): string
    {
        $bytes = Storage::disk($media->disk)->get($media->path);

        if ($bytes === null || $bytes === '') {
            throw PublishException::permanent("The {$label} file \"{$media->original_name}\" is missing.");
        }

        $initialized = $this->client->post($account, "{$endpoint}?action=initializeUpload", [
            'initializeUploadRequest' => ['owner' => $this->authorUrn($account)],
        ], "start the {$label} upload");

        $uploadUrl = (string) $initialized->json('value.uploadUrl');
        $mediaUrn = (string) $initialized->json("value.{$label}");

        if ($uploadUrl === '' || $mediaUrn === '') {
            throw PublishException::retryable("LinkedIn did not return an upload URL for the {$label}.");
        }

        $this->client->upload($account, $uploadUrl, $bytes, $media->mime_type, true, "upload the {$label}");

        LogService::info('LinkedIn media uploaded', ['kind' => $label, 'media_id' => $media->id, 'media_urn' => $mediaUrn]);

        return $mediaUrn;
    }

    public function authorUrn(SocialAccount $account): string
    {
        return "urn:li:person:{$account->provider_account_id}";
    }
}
