<?php

namespace App\Services\Facebook;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\LogService;
use App\Services\Media\AudioToVideoConverter;
use App\Services\Posts\Rules\FacebookPostRules;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class FacebookPublisher implements Publisher
{
    public function __construct(
        private FacebookGraphClient $graph,
        private AudioToVideoConverter $audioToVideoConverter,
        private FacebookPostRules $rules,
    ) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::Facebook;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $target->loadMissing(['post.media', 'socialAccount']);
        $post = $target->post;
        $page = $target->socialAccount;
        $media = $post->media;
        $settings = [...$this->rules->defaultSettings(), ...($target->settings ?? [])];
        $text = $target->contentFor($post);

        $mediaErrors = [...$this->rules->mediaErrors($media), ...$this->rules->targetErrors($text, $media, $settings)];

        if ($mediaErrors !== []) {
            throw PublishException::permanent(ucfirst($mediaErrors[0]));
        }

        $first = $media->first();
        $isReel = $settings['format'] === 'reel';

        LogService::info('Sending post to Facebook', [
            'page_id' => $page->provider_account_id,
            'media_kind' => $first?->kind->value,
            'media_count' => $media->count(),
            'format' => $isReel ? 'reel' : 'post',
        ]);

        try {
            $result = match (true) {
                $first === null => $this->publishText($page, $text),
                $first->kind === MediaKind::Image => $this->publishPhotos($page, $media, $text),
                default => $this->publishVideo($page, $first, $text, (string) $settings['video_title'], $isReel),
            };
        } catch (FacebookApiException $exception) {
            if ($exception->isInvalidToken()) {
                $page->update(['needs_reconnect' => true]);
            }

            throw $exception;
        }

        LogService::info('Facebook post created', ['external_id' => $result->externalId]);

        return $result;
    }

    private function publishText(SocialAccount $page, string $text): PublishResult
    {
        $postId = (string) $this->finalCall(fn () => $this->graph->post("{$page->provider_account_id}/feed", ['message' => $text], $page->access_token, 'create the post'))->json('id');

        return new PublishResult($postId, $this->permalink($page, $postId));
    }

    /**
     * @param  Collection<int, Media>  $photos
     */
    private function publishPhotos(SocialAccount $page, Collection $photos, string $text): PublishResult
    {
        $photosUrl = $this->graph->url("{$page->provider_account_id}/photos");

        if ($photos->count() === 1) {
            /** @var Media $photo */
            $photo = $photos->first();
            $response = $this->finalCall(fn () => $this->graph->upload($photosUrl, ['caption' => $text, 'published' => 'true'], $this->path($photo), $photo->original_name, $page->access_token, 'upload the photo'));
            $postId = (string) ($response->json('post_id') ?? $response->json('id'));

            return new PublishResult($postId, $this->permalink($page, $postId));
        }

        $attached = [];

        foreach ($photos->values() as $index => $photo) {
            $photoId = (string) $this->graph->upload($photosUrl, ['published' => 'false'], $this->path($photo), $photo->original_name, $page->access_token, 'upload photo '.($index + 1))->json('id');
            $attached["attached_media[{$index}]"] = json_encode(['media_fbid' => $photoId]);
        }

        LogService::info('Facebook photos uploaded', ['count' => count($attached)]);

        $postId = (string) $this->finalCall(fn () => $this->graph->post("{$page->provider_account_id}/feed", ['message' => $text, ...$attached], $page->access_token, 'create the photo post'))->json('id');

        return new PublishResult($postId, $this->permalink($page, $postId));
    }

    private function publishVideo(SocialAccount $page, Media $video, string $text, string $title, bool $isReel): PublishResult
    {
        $isAudio = $video->kind === MediaKind::Audio;
        $path = $isAudio ? $this->audioToVideoConverter->convert($this->path($video)) : $this->path($video);
        $fileName = $isAudio ? pathinfo($video->original_name, PATHINFO_FILENAME).'.mp4' : $video->original_name;

        try {
            return $isReel
                ? $this->publishReel($page, $path, $text, $title)
                : $this->publishPageVideo($page, $path, $fileName, $text, $title);
        } finally {
            if ($isAudio) {
                @unlink($path);
            }
        }
    }

    private function publishPageVideo(SocialAccount $page, string $path, string $fileName, string $text, string $title): PublishResult
    {
        $url = $this->graph->url("{$page->provider_account_id}/videos", 'graph_video_base_url');
        $data = array_filter(['description' => $text, 'title' => $title], fn (string $value): bool => $value !== '');
        $videoId = (string) $this->finalCall(fn () => $this->graph->upload($url, $data, $path, $fileName, $page->access_token, 'upload the video'))->json('id');

        return new PublishResult($videoId, $this->permalink($page, $videoId, "https://www.facebook.com/{$page->provider_account_id}/videos/{$videoId}/"));
    }

    private function publishReel(SocialAccount $page, string $path, string $text, string $title): PublishResult
    {
        $reelsPath = "{$page->provider_account_id}/video_reels";
        $videoId = (string) $this->graph->post($reelsPath, ['upload_phase' => 'start'], $page->access_token, 'start the Reel upload')->json('video_id');

        if ($videoId === '') {
            throw new FacebookApiException('Facebook did not return a Reel upload id.', true);
        }

        $uploadUrl = rtrim((string) config('services.facebook.rupload_base_url'), '/').'/'.config('services.facebook.graph_version').'/'.$videoId;
        $this->graph->uploadBinary($uploadUrl, $path, $page->access_token, 'upload the Reel');

        LogService::info('Facebook Reel uploaded', ['video_id' => $videoId]);

        $this->finalCall(fn () => $this->graph->post($reelsPath, array_filter([
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $text,
            'title' => $title,
        ], fn (string $value): bool => $value !== ''), $page->access_token, 'publish the Reel'));

        return new PublishResult($videoId, "https://www.facebook.com/reel/{$videoId}");
    }

    /**
     * Run the request that makes the post public. A dropped connection or a Facebook server error here may come
     * after Facebook already posted it, so it is not retried; the owner checks the Page first.
     *
     * @param  callable(): Response  $call
     *
     * @throws PublishException
     */
    private function finalCall(callable $call): Response
    {
        try {
            return $call();
        } catch (FacebookApiException $exception) {
            if ($exception->statusCode !== null && $exception->statusCode < 500) {
                throw $exception;
            }

            LogService::warning('Final publish call failed, may already be posted', ['status' => $exception->statusCode], $exception);

            $reason = $exception->statusCode === null ? 'The connection to Facebook dropped' : 'Facebook had a server error';

            throw PublishException::permanent("{$reason} while publishing, so it may already be posted. Check the Page before you retry.", $exception);
        }
    }

    /**
     * The post's public link; falls back to a constructed link when Facebook doesn't return one.
     */
    private function permalink(SocialAccount $page, string $objectId, ?string $fallback = null): string
    {
        $fallback ??= "https://www.facebook.com/{$objectId}";

        try {
            $permalink = (string) $this->graph->get($objectId, ['fields' => 'permalink_url'], $page->access_token, 'read the post link')->json('permalink_url');
        } catch (FacebookApiException) {
            return $fallback;
        }

        return match (true) {
            $permalink === '' => $fallback,
            str_starts_with($permalink, '/') => "https://www.facebook.com{$permalink}",
            default => $permalink,
        };
    }

    private function path(Media $media): string
    {
        $path = Storage::disk($media->disk)->path($media->path);

        if (! is_file($path)) {
            throw PublishException::permanent("The file {$media->original_name} is missing from the media library.");
        }

        return $path;
    }
}
