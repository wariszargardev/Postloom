<?php

namespace App\Services\YouTube;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Services\LogService;
use App\Services\Media\AudioToVideoConverter;
use App\Services\Posts\Rules\YouTubePostRules;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Illuminate\Support\Facades\Storage;

class YouTubePublisher implements Publisher
{
    public const SHORTS_TAG = '#Shorts';

    public function __construct(
        private YouTubeUploader $uploader,
        private AudioToVideoConverter $audioToVideoConverter,
        private YouTubePostRules $rules,
    ) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::YouTube;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $target->loadMissing(['post.media', 'socialAccount']);
        $post = $target->post;
        $account = $target->socialAccount;
        $settings = [...$this->rules->defaultSettings(), ...($target->settings ?? [])];

        $mediaErrors = $this->rules->mediaErrors($post->media);

        if ($mediaErrors !== []) {
            throw PublishException::permanent($mediaErrors[0]);
        }

        /** @var Media $video */
        $video = $post->media->first();
        $title = trim((string) $settings['title']);

        if ($title === '') {
            throw PublishException::permanent('The YouTube title is empty. Add it in the post\'s YouTube settings.');
        }

        $isShort = YouTubePostRules::isShort($video, (string) $settings['format']);
        $description = $this->youTubeSafe($target->contentFor($post));

        if ($isShort && stripos($title.' '.$description, self::SHORTS_TAG) === false) {
            $description = trim($description."\n\n".self::SHORTS_TAG);
        }

        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $description,
                'tags' => YouTubePostRules::parseTags((string) ($settings['tags'] ?? '')),
                'categoryId' => (string) $settings['category'],
            ],
            'status' => [
                'privacyStatus' => (string) $settings['privacy'],
                'selfDeclaredMadeForKids' => (bool) $settings['made_for_kids'],
                'containsSyntheticMedia' => (bool) $settings['synthetic_media'],
            ],
        ];

        LogService::info('Sending video to YouTube', [
            'media_kind' => $video->kind->value,
            'media_id' => $video->id,
            'privacy' => $settings['privacy'],
            'format' => $isShort ? 'short' : 'regular',
        ]);

        $videoId = $video->kind === MediaKind::Audio
            ? $this->uploadAudio($target, $video, $metadata, (bool) $settings['notify_subscribers'])
            : $this->uploader->upload($account, Storage::disk($video->disk)->path($video->path), $video->mime_type, $metadata, (bool) $settings['notify_subscribers']);

        $notice = $this->setThumbnail($target, $videoId, $settings['thumbnail_media_id'] ?? null, $isShort);

        LogService::info('YouTube video created', ['external_id' => $videoId, 'short' => $isShort]);

        return new PublishResult(
            $videoId,
            $isShort ? "https://www.youtube.com/shorts/{$videoId}" : "https://www.youtube.com/watch?v={$videoId}",
            $notice === null ? null : "Published, but {$notice}",
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws PublishException
     */
    private function uploadAudio(PostTarget $target, Media $audio, array $metadata, bool $notifySubscribers): string
    {
        $videoPath = $this->audioToVideoConverter->convert(Storage::disk($audio->disk)->path($audio->path));

        try {
            return $this->uploader->upload($target->socialAccount, $videoPath, 'video/mp4', $metadata, $notifySubscribers);
        } finally {
            @unlink($videoPath);
        }
    }

    private function setThumbnail(PostTarget $target, string $videoId, mixed $thumbnailMediaId, bool $isShort): ?string
    {
        if ($thumbnailMediaId === null || $thumbnailMediaId === '') {
            return null;
        }

        $thumbnail = Media::query()
            ->whereKey($thumbnailMediaId)
            ->where('workspace_id', $target->post->workspace_id)
            ->where('kind', MediaKind::Image)
            ->first();

        $bytes = $thumbnail === null ? null : Storage::disk($thumbnail->disk)->get($thumbnail->path);

        if ($thumbnail === null || $bytes === null) {
            LogService::warning('YouTube thumbnail image is missing', ['thumbnail_media_id' => $thumbnailMediaId]);

            return 'the thumbnail image is missing from the media library.';
        }

        if ($isShort && ! $this->uploader->waitUntilProcessed($target->socialAccount, $videoId)) {
            return 'the Short cover wasn\'t set because YouTube was still processing the video. Set it in YouTube Studio.';
        }

        return $this->uploader->setThumbnail($target->socialAccount, $videoId, $bytes, $thumbnail->mime_type);
    }

    /**
     * YouTube refuses < and > in descriptions, so swap them for look-alike angle quotes.
     */
    private function youTubeSafe(string $text): string
    {
        return strtr($text, ['<' => '‹', '>' => '›']);
    }
}
