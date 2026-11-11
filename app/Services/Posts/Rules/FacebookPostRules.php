<?php

namespace App\Services\Posts\Rules;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class FacebookPostRules implements PlatformPostRules
{
    public const MAX_TEXT_LENGTH = 63206;

    public const MAX_PHOTOS = 10;

    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    public const MAX_VIDEO_TITLE_LENGTH = 255;

    public const MIN_REEL_SECONDS = 3;

    public const MAX_REEL_SECONDS = 90;

    /**
     * Photo types the Graph API accepts for Page photos.
     *
     * @var array<int, string>
     */
    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff'];

    /**
     * @var array<string, string>
     */
    public const FORMAT_OPTIONS = [
        'post' => 'Post',
        'reel' => 'Reel (vertical video, 3–90 s)',
    ];

    public function provider(): SocialProvider
    {
        return SocialProvider::Facebook;
    }

    public function maxTextLength(): int
    {
        return self::MAX_TEXT_LENGTH;
    }

    public function defaultSettings(): array
    {
        return ['format' => 'post', 'video_title' => ''];
    }

    public function settingsRules(): array
    {
        return [
            'format' => ['required', Rule::in(array_keys(self::FORMAT_OPTIONS))],
            'video_title' => ['nullable', 'string', 'max:'.self::MAX_VIDEO_TITLE_LENGTH],
        ];
    }

    public function mediaErrors(Collection $media): array
    {
        if ($media->isEmpty()) {
            return [];
        }

        $kinds = $media->map(fn (Media $item): MediaKind => $item->kind)->unique(fn (MediaKind $kind): string => $kind->value);

        if ($kinds->count() > 1) {
            return ['Facebook can\'t mix media types in one post. Use only photos, or one video, or one audio file.'];
        }

        $kind = $kinds->first();
        $count = $media->count();

        return match (true) {
            $kind === MediaKind::Document => ['Facebook Pages can\'t post PDFs. Share a link to the file in the text instead.'],
            $kind === MediaKind::Image && $count > self::MAX_PHOTOS => ['Facebook allows up to '.self::MAX_PHOTOS." photos per post; this post has {$count}."],
            $kind === MediaKind::Video && $count > 1 => ['Facebook allows one video per post.'],
            $kind === MediaKind::Audio && $count > 1 => ['Facebook allows one audio file per post (it is posted as a video).'],
            default => $this->photoErrors($media),
        };
    }

    public function targetErrors(string $text, Collection $media, array $settings): array
    {
        if (($settings['format'] ?? 'post') !== 'reel') {
            return [];
        }

        $video = $media->first();

        if (! $video instanceof Media || ! in_array($video->kind, [MediaKind::Video, MediaKind::Audio], true)) {
            return ['a Reel needs one video (or an audio file, posted as a video).'];
        }

        $errors = [];
        $duration = $video->duration_seconds === null ? null : (float) $video->duration_seconds;

        if ($duration !== null && ($duration < self::MIN_REEL_SECONDS || $duration > self::MAX_REEL_SECONDS)) {
            $errors[] = 'a Reel must be '.self::MIN_REEL_SECONDS.' to '.self::MAX_REEL_SECONDS.' seconds; this video is '.round($duration).' s.';
        }

        if ($video->kind === MediaKind::Audio || ($video->width !== null && $video->height !== null && $video->height <= $video->width)) {
            $errors[] = 'a Reel needs a vertical 9:16 video.';
        }

        return $errors;
    }

    public function describe(): array
    {
        return [
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'media_rules' => 'Text only, up to '.self::MAX_PHOTOS.' photos (JPG, PNG, GIF; 10 MB each), or 1 video, or 1 audio file. No PDFs, no mixing.',
            'settings' => [
                'format' => [
                    'type' => 'select',
                    'label' => 'Format',
                    'options' => self::FORMAT_OPTIONS,
                    'default' => 'post',
                    'help' => 'Reels need a vertical 9:16 video of 3–90 seconds; a Page can publish up to 30 Reels a day.',
                ],
                'video_title' => [
                    'type' => 'text',
                    'label' => 'Video title (optional)',
                    'max_length' => self::MAX_VIDEO_TITLE_LENGTH,
                    'default' => '',
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    private function photoErrors(Collection $media): array
    {
        foreach ($media as $item) {
            if ($item->kind !== MediaKind::Image) {
                continue;
            }

            if (! in_array($item->mime_type, self::PHOTO_MIME_TYPES, true)) {
                return ["Facebook doesn't take {$item->original_name} (".strtoupper(pathinfo($item->original_name, PATHINFO_EXTENSION)).'). Use JPG, PNG or GIF.'];
            }

            if ($item->size_bytes > self::MAX_PHOTO_BYTES) {
                return ["{$item->original_name} is over Facebook's 10 MB photo limit."];
            }
        }

        return [];
    }
}
