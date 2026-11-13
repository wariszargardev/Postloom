<?php

namespace App\Services\Posts\Rules;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class InstagramPostRules implements PlatformPostRules
{
    public const MAX_TEXT_LENGTH = 2200;

    public const MAX_HASHTAGS = 30;

    public const MAX_MENTIONS = 20;

    public const MIN_CAROUSEL_ITEMS = 2;

    public const MAX_CAROUSEL_ITEMS = 10;

    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public const MIN_FEED_RATIO = 0.8;

    public const MAX_FEED_RATIO = 1.91;

    public const MIN_VIDEO_SECONDS = 3;

    public const MAX_REEL_SECONDS = 900;

    public const MAX_REEL_BYTES = 300 * 1024 * 1024;

    public const MAX_STORY_VIDEO_SECONDS = 60;

    public const MAX_STORY_VIDEO_BYTES = 100 * 1024 * 1024;

    /**
     * PNG is turned into JPEG when Instagram fetches it.
     *
     * @var array<int, string>
     */
    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];

    /**
     * @var array<int, string>
     */
    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime'];

    /**
     * @var array<string, string>
     */
    public const FORMAT_OPTIONS = [
        'post' => 'Post (1 photo, or a carousel of 2–10)',
        'reel' => 'Reel (1 video)',
        'story' => 'Story (1 photo or video)',
    ];

    public function provider(): SocialProvider
    {
        return SocialProvider::Instagram;
    }

    public function maxTextLength(): int
    {
        return self::MAX_TEXT_LENGTH;
    }

    public function defaultSettings(): array
    {
        return ['format' => 'post', 'share_to_feed' => true];
    }

    public function settingsRules(): array
    {
        return [
            'format' => ['required', Rule::in(array_keys(self::FORMAT_OPTIONS))],
            'share_to_feed' => ['boolean'],
        ];
    }

    public function mediaErrors(Collection $media): array
    {
        if ($media->isEmpty()) {
            return ['Instagram needs a photo or a video. Text-only posts aren\'t possible on Instagram.'];
        }

        if ($media->contains(fn (Media $item): bool => $item->kind === MediaKind::Document)) {
            return ['Instagram can\'t post PDFs. Use photos or a video.'];
        }

        if ($media->contains(fn (Media $item): bool => $item->kind === MediaKind::Audio) && $media->count() > 1) {
            return ['Instagram posts an audio file as a video on its own; don\'t mix it with other files.'];
        }

        if ($media->count() > self::MAX_CAROUSEL_ITEMS) {
            return ['Instagram allows up to '.self::MAX_CAROUSEL_ITEMS.' photos or videos per post; this post has '.$media->count().'.'];
        }

        foreach ($media as $item) {
            $error = match ($item->kind) {
                MediaKind::Image => $this->imageError($item),
                MediaKind::Video => $this->videoTypeError($item),
                default => null,
            };

            if ($error !== null) {
                return [$error];
            }
        }

        return [];
    }

    public function targetErrors(string $text, Collection $media, array $settings): array
    {
        $errors = $this->captionErrors($text);

        if ($media->isEmpty()) {
            return $errors;
        }

        $formatErrors = match ($settings['format'] ?? 'post') {
            'reel' => $this->reelErrors($media),
            'story' => $this->storyErrors($media),
            default => $this->feedErrors($media),
        };

        return [...$errors, ...$formatErrors];
    }

    public function describe(): array
    {
        return [
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'media_rules' => 'Post: 1 photo or a carousel of 2–'.self::MAX_CAROUSEL_ITEMS.' photos/videos. Reel: 1 video. Story: 1 photo or video. Photos JPG or PNG up to 8 MB. Up to '.self::MAX_HASHTAGS.' hashtags.',
            'settings' => [
                'format' => [
                    'type' => 'select',
                    'label' => 'Format',
                    'options' => self::FORMAT_OPTIONS,
                    'default' => 'post',
                    'help' => 'Photos need the Cloudflare tunnel tab running; if it is off, the post waits under To post. Videos upload directly.',
                ],
                'share_to_feed' => [
                    'type' => 'boolean',
                    'label' => 'Also show the Reel in the feed',
                    'default' => true,
                ],
            ],
        ];
    }

    /**
     * True when publishing these media needs a public link (photos, or videos when INSTAGRAM_VIDEO_UPLOAD=url).
     *
     * @param  Collection<int, Media>  $media
     */
    public function needsPublicLink(Collection $media): bool
    {
        return $media->contains(fn (Media $item): bool => $item->kind === MediaKind::Image
            || (config('services.instagram.video_upload') === 'url' && $item->kind !== MediaKind::Document));
    }

    /**
     * @return array<int, string>
     */
    private function captionErrors(string $text): array
    {
        $errors = [];
        $hashtags = preg_match_all('/(?<![\p{L}\p{N}_&])#[\p{L}\p{N}_]+/u', $text);
        $mentions = preg_match_all('/(?<![\p{L}\p{N}_])@[\p{L}\p{N}_.]+/u', $text);

        if ($hashtags > self::MAX_HASHTAGS) {
            $errors[] = 'Instagram allows up to '.self::MAX_HASHTAGS." hashtags; this caption has {$hashtags}.";
        }

        if ($mentions > self::MAX_MENTIONS) {
            $errors[] = 'Instagram allows up to '.self::MAX_MENTIONS." @mentions; this caption has {$mentions}.";
        }

        return $errors;
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    private function feedErrors(Collection $media): array
    {
        /** @var Media $first */
        $first = $media->first();

        if ($media->count() === 1 && $first->kind !== MediaKind::Image) {
            return ['a single video goes to Instagram as a Reel; choose the Reel format.'];
        }

        foreach ($media as $item) {
            if ($item->kind === MediaKind::Image && $item->width !== null && $item->height !== null && $item->height > 0) {
                $ratio = $item->width / $item->height;

                if ($ratio < self::MIN_FEED_RATIO - 0.01 || $ratio > self::MAX_FEED_RATIO + 0.01) {
                    return ["{$item->original_name} is {$item->width}×{$item->height}; feed photos must be between 4:5 (portrait) and 1.91:1 (landscape). Crop it or post it as a Story."];
                }
            }

            if ($item->kind === MediaKind::Video && ($error = $this->durationError($item, self::MAX_REEL_SECONDS, 'a carousel video')) !== null) {
                return [$error];
            }
        }

        return [];
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    private function reelErrors(Collection $media): array
    {
        /** @var Media $video */
        $video = $media->first();

        if ($media->count() > 1 || ! in_array($video->kind, [MediaKind::Video, MediaKind::Audio], true)) {
            return ['a Reel needs exactly one video (or an audio file, posted as a video).'];
        }

        if ($video->kind === MediaKind::Video && $video->size_bytes > self::MAX_REEL_BYTES) {
            return ["{$video->original_name} is over Instagram's 300 MB Reel limit."];
        }

        $error = $this->durationError($video, self::MAX_REEL_SECONDS, 'a Reel');

        return $error === null ? [] : [$error];
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    private function storyErrors(Collection $media): array
    {
        /** @var Media $item */
        $item = $media->first();

        if ($media->count() > 1) {
            return ['a Story takes exactly one photo or video.'];
        }

        if ($item->kind === MediaKind::Image) {
            return [];
        }

        if ($item->kind === MediaKind::Video && $item->size_bytes > self::MAX_STORY_VIDEO_BYTES) {
            return ["{$item->original_name} is over Instagram's 100 MB Story video limit."];
        }

        $error = $this->durationError($item, self::MAX_STORY_VIDEO_SECONDS, 'a Story video');

        return $error === null ? [] : [$error];
    }

    private function durationError(Media $item, int $maxSeconds, string $label): ?string
    {
        if ($item->duration_seconds === null) {
            return null;
        }

        $duration = (float) $item->duration_seconds;

        if ($duration < self::MIN_VIDEO_SECONDS || $duration > $maxSeconds) {
            $limit = $maxSeconds >= 60 && $maxSeconds % 60 === 0 ? ($maxSeconds / 60).' min' : "{$maxSeconds} s";

            return "{$label} must be ".self::MIN_VIDEO_SECONDS." s to {$limit}; {$item->original_name} is ".round($duration).' s.';
        }

        return null;
    }

    private function imageError(Media $item): ?string
    {
        if (! in_array($item->mime_type, self::IMAGE_MIME_TYPES, true)) {
            return "Instagram doesn't take {$item->original_name} (".strtoupper(pathinfo($item->original_name, PATHINFO_EXTENSION)).'). Use JPG or PNG.';
        }

        if ($item->size_bytes > self::MAX_IMAGE_BYTES) {
            return "{$item->original_name} is over Instagram's 8 MB photo limit.";
        }

        return null;
    }

    private function videoTypeError(Media $item): ?string
    {
        return in_array($item->mime_type, self::VIDEO_MIME_TYPES, true)
            ? null
            : "Instagram doesn't take {$item->original_name}. Use MP4 or MOV.";
    }
}
