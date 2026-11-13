<?php

namespace App\Services\Instagram;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Facebook\FacebookApiException;
use App\Services\Facebook\FacebookGraphClient;
use App\Services\LogService;
use App\Services\Media\AudioToVideoConverter;
use App\Services\Posts\Rules\InstagramPostRules;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class InstagramPublisher implements Publisher
{
    public const NOT_READY_CODE = 9007;

    public const DAILY_LIMIT_SUBCODE = 2207042;

    public const PUBLISH_TRIES = 3;

    /**
     * Temporary MP4 files made from audio, deleted after publishing.
     *
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    private ?CarbonInterface $deadline = null;

    public function __construct(
        private FacebookGraphClient $graph,
        private AudioToVideoConverter $audioToVideoConverter,
        private InstagramPostRules $rules,
        private PublicMediaUrl $publicMediaUrl,
    ) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::Instagram;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $target->loadMissing(['post.media', 'socialAccount']);
        $post = $target->post;
        $account = $target->socialAccount;
        $media = $post->media;
        $settings = [...$this->rules->defaultSettings(), ...($target->settings ?? [])];
        $text = $target->contentFor($post);
        $format = (string) $settings['format'];

        $errors = [...$this->rules->mediaErrors($media), ...$this->rules->targetErrors($text, $media, $settings)];

        if ($errors !== []) {
            throw PublishException::permanent(ucfirst($errors[0]));
        }

        LogService::info('Sending post to Instagram', [
            'instagram_id' => $account->provider_account_id,
            'format' => $format,
            'media_count' => $media->count(),
            'media_kinds' => $media->map(fn (Media $item): string => $item->kind->value)->unique()->values()->all(),
            'video_upload' => config('services.instagram.video_upload'),
        ]);

        if ($this->rules->needsPublicLink($media) && ! $this->publicMediaUrl->isReachable()) {
            LogService::warning('Instagram post handed off to you because the public link is not running', ['format' => $format]);

            return new PublishResult(
                "manual-{$target->id}",
                null,
                'Ready for you: the Cloudflare tunnel was not running, so Instagram could not fetch the photo. Download it under To post, post it in Instagram, then click Mark as posted (or start the tunnel and use Retry).',
                needsManualPost: true,
            );
        }

        $this->deadline = now()->addSeconds((int) config('services.instagram.status_wait_seconds'));

        try {
            $this->checkPublishingLimit($account);

            $containerId = match ($format) {
                'reel' => $this->reelContainer($account, $media->first(), $text, (bool) $settings['share_to_feed']),
                'story' => $this->storyContainer($account, $media->first()),
                default => $media->count() > 1
                    ? $this->carouselContainer($account, $media, $text)
                    : $this->imageContainer($account, $media->first(), ['caption' => $text, 'alt_text' => $media->first()?->alt_text]),
            };

            $this->waitUntilFinished($account, $containerId);
            $mediaId = $this->publishContainer($account, $containerId);
        } catch (FacebookApiException $exception) {
            if ($exception->isInvalidToken()) {
                $account->update(['needs_reconnect' => true]);
            }

            throw $exception;
        } finally {
            foreach ($this->temporaryFiles as $file) {
                @unlink($file);
            }

            $this->temporaryFiles = [];
        }

        LogService::info('Instagram post published', ['external_id' => $mediaId, 'format' => $format]);

        return new PublishResult($mediaId, $this->permalink($account, $mediaId));
    }

    private function checkPublishingLimit(SocialAccount $account): void
    {
        try {
            $usage = $this->graph->get("{$account->provider_account_id}/content_publishing_limit", ['fields' => 'quota_usage,config'], $account->access_token, 'read the publishing limit')->json('data.0');
        } catch (FacebookApiException $exception) {
            if ($exception->isInvalidToken()) {
                throw $exception;
            }

            LogService::warning('Instagram publishing limit could not be read, posting anyway', [], $exception);

            return;
        }

        $used = (int) ($usage['quota_usage'] ?? 0);
        $total = (int) ($usage['config']['quota_total'] ?? 0);

        LogService::info('Instagram publishing limit read', ['quota_usage' => $used, 'quota_total' => $total]);

        if ($total > 0 && $used >= $total) {
            throw PublishException::permanent("Instagram's limit of {$total} API posts in 24 hours is reached for this account. Reschedule it for later.");
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function imageContainer(SocialAccount $account, ?Media $image, array $extra): string
    {
        if (! $image instanceof Media) {
            throw PublishException::permanent('Instagram needs a photo.');
        }

        $this->ensureFileExists($image);

        $response = $this->graph->post("{$account->provider_account_id}/media", $this->withoutEmpty([
            'image_url' => $this->publicMediaUrl->for($image),
            ...$extra,
        ]), $account->access_token, 'create the photo container');

        return $this->containerId($response->json('id'), 'photo');
    }

    private function reelContainer(SocialAccount $account, ?Media $video, string $text, bool $shareToFeed): string
    {
        return $this->videoContainer($account, $video, 'REELS', [
            'caption' => $text,
            'share_to_feed' => $shareToFeed ? 'true' : 'false',
        ]);
    }

    private function storyContainer(SocialAccount $account, ?Media $item): string
    {
        if ($item instanceof Media && $item->kind === MediaKind::Image) {
            return $this->imageContainer($account, $item, ['media_type' => 'STORIES']);
        }

        return $this->videoContainer($account, $item, 'STORIES', []);
    }

    /**
     * @param  Collection<int, Media>  $media
     */
    private function carouselContainer(SocialAccount $account, Collection $media, string $text): string
    {
        $children = [];

        foreach ($media->values() as $index => $item) {
            $children[] = $item->kind === MediaKind::Image
                ? $this->imageContainer($account, $item, ['is_carousel_item' => 'true', 'alt_text' => $item->alt_text])
                : $this->videoContainer($account, $item, 'VIDEO', ['is_carousel_item' => 'true']);

            LogService::info('Instagram carousel item created', ['position' => $index + 1, 'kind' => $item->kind->value]);
        }

        foreach ($children as $childId) {
            $this->waitUntilFinished($account, $childId);
        }

        $response = $this->graph->post("{$account->provider_account_id}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $text,
        ], $account->access_token, 'create the carousel container');

        return $this->containerId($response->json('id'), 'carousel');
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function videoContainer(SocialAccount $account, ?Media $video, string $mediaType, array $extra): string
    {
        if (! $video instanceof Media || ! in_array($video->kind, [MediaKind::Video, MediaKind::Audio], true)) {
            throw PublishException::permanent('Instagram needs a video here.');
        }

        $mediaPath = "{$account->provider_account_id}/media";

        if (config('services.instagram.video_upload') === 'url') {
            if ($video->kind === MediaKind::Audio) {
                throw PublishException::permanent('Audio files can only go to Instagram with INSTAGRAM_VIDEO_UPLOAD=resumable.');
            }

            $this->ensureFileExists($video);
            $response = $this->graph->post($mediaPath, $this->withoutEmpty([
                'media_type' => $mediaType,
                'video_url' => $this->publicMediaUrl->for($video),
                ...$extra,
            ]), $account->access_token, 'create the video container');

            return $this->containerId($response->json('id'), 'video');
        }

        $path = $this->localVideoPath($video);
        $response = $this->graph->post($mediaPath, $this->withoutEmpty([
            'media_type' => $mediaType,
            'upload_type' => 'resumable',
            ...$extra,
        ]), $account->access_token, 'create the video container');

        $containerId = $this->containerId($response->json('id'), 'video');
        $uploadUrl = (string) ($response->json('uri') ?? '');

        if ($uploadUrl === '') {
            $uploadUrl = rtrim((string) config('services.instagram.rupload_base_url'), '/').'/'.config('services.facebook.graph_version').'/'.$containerId;
        }

        $this->graph->uploadBinary($uploadUrl, $path, $account->access_token, 'upload the video to Instagram');

        LogService::info('Instagram video uploaded', ['container_id' => $containerId, 'media_type' => $mediaType, 'size_bytes' => filesize($path)]);

        return $containerId;
    }

    /**
     * Poll the container until Instagram has processed it (images are usually ready at once).
     */
    private function waitUntilFinished(SocialAccount $account, string $containerId): void
    {
        $pollSeconds = max(1, (int) config('services.instagram.status_poll_seconds'));
        $polls = 0;

        while (true) {
            $response = $this->graph->get($containerId, ['fields' => 'status_code,status'], $account->access_token, 'check the media status');
            $statusCode = (string) $response->json('status_code');
            $polls++;

            if (in_array($statusCode, ['FINISHED', 'PUBLISHED'], true)) {
                LogService::info('Instagram container ready', ['container_id' => $containerId, 'polls' => $polls]);

                return;
            }

            if (in_array($statusCode, ['ERROR', 'EXPIRED'], true)) {
                $detail = (string) $response->json('status');
                LogService::warning('Instagram could not process the media', ['container_id' => $containerId, 'status_code' => $statusCode, 'status' => mb_substr($detail, 0, 300)]);

                throw PublishException::permanent('Instagram could not process the media ('.$statusCode.($detail !== '' ? ": {$detail}" : '').'). Check the format, length and size, then retry.');
            }

            if ($this->deadline === null || now()->addSeconds($pollSeconds)->greaterThan($this->deadline)) {
                LogService::warning('Instagram is still processing the media', ['container_id' => $containerId, 'status_code' => $statusCode, 'polls' => $polls]);

                throw PublishException::retryable('Instagram is still processing the media. It will be tried again.');
            }

            Sleep::for($pollSeconds)->seconds();
        }
    }

    private function publishContainer(SocialAccount $account, string $containerId): string
    {
        $pollSeconds = max(1, (int) config('services.instagram.status_poll_seconds'));

        for ($try = 1; ; $try++) {
            try {
                $response = $this->graph->post("{$account->provider_account_id}/media_publish", ['creation_id' => $containerId], $account->access_token, 'publish the post');

                $mediaId = (string) $response->json('id');

                if ($mediaId === '') {
                    throw PublishException::permanent('Instagram accepted the post but returned no id. Check the profile before you retry.');
                }

                return $mediaId;
            } catch (FacebookApiException $exception) {
                if ($exception->errorSubcode === self::DAILY_LIMIT_SUBCODE) {
                    throw PublishException::permanent("Instagram's daily posting limit is reached for this account. Reschedule it for later.", $exception);
                }

                if ($exception->statusCode === null) {
                    throw PublishException::permanent('The connection to Instagram dropped while publishing, so it may already be posted. Check the profile before you retry.', $exception);
                }

                if ($exception->errorCode !== self::NOT_READY_CODE || $try >= self::PUBLISH_TRIES) {
                    throw $exception;
                }

                LogService::info('Instagram media not ready to publish yet, waiting', ['container_id' => $containerId, 'try' => $try]);
                Sleep::for($pollSeconds)->seconds();
            }
        }
    }

    private function permalink(SocialAccount $account, string $mediaId): string
    {
        $fallback = $account->handle !== null ? "https://www.instagram.com/{$account->handle}/" : 'https://www.instagram.com/';

        try {
            $permalink = (string) $this->graph->get($mediaId, ['fields' => 'permalink'], $account->access_token, 'read the post link')->json('permalink');
        } catch (FacebookApiException) {
            return $fallback;
        }

        return $permalink !== '' ? $permalink : $fallback;
    }

    private function localVideoPath(Media $video): string
    {
        $path = $this->ensureFileExists($video);

        if ($video->kind !== MediaKind::Audio) {
            return $path;
        }

        $converted = $this->audioToVideoConverter->convert($path);
        $this->temporaryFiles[] = $converted;

        return $converted;
    }

    private function ensureFileExists(Media $media): string
    {
        $path = Storage::disk($media->disk)->path($media->path);

        if (! is_file($path)) {
            throw PublishException::permanent("The file {$media->original_name} is missing from the media library.");
        }

        return $path;
    }

    private function containerId(mixed $id, string $what): string
    {
        $id = (string) $id;

        if ($id === '') {
            throw PublishException::retryable("Instagram did not return an id for the {$what}.");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
