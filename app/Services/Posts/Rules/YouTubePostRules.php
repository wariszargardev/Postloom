<?php

namespace App\Services\Posts\Rules;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class YouTubePostRules implements PlatformPostRules
{
    public const MAX_TITLE_LENGTH = 100;

    public const MAX_DESCRIPTION_BYTES = 5000;

    public const MAX_TAGS_LENGTH = 500;

    public const MAX_SHORT_SECONDS = 180;

    /**
     * @var array<string, string>
     */
    public const PRIVACY_OPTIONS = [
        'public' => 'Public',
        'unlisted' => 'Unlisted',
        'private' => 'Private',
    ];

    /**
     * @var array<string, string>
     */
    public const FORMAT_OPTIONS = [
        'auto' => 'Auto (YouTube decides)',
        'short' => 'Short (vertical, up to 3 min)',
        'long' => 'Regular video',
    ];

    /**
     * YouTube's assignable categories (videoCategories.list), keyed by category id; 22 is the default.
     *
     * @var array<int, string>
     */
    public const CATEGORY_OPTIONS = [
        '1' => 'Film & Animation',
        '2' => 'Autos & Vehicles',
        '10' => 'Music',
        '15' => 'Pets & Animals',
        '17' => 'Sports',
        '19' => 'Travel & Events',
        '20' => 'Gaming',
        '22' => 'People & Blogs',
        '23' => 'Comedy',
        '24' => 'Entertainment',
        '25' => 'News & Politics',
        '26' => 'Howto & Style',
        '27' => 'Education',
        '28' => 'Science & Technology',
        '29' => 'Nonprofits & Activism',
    ];

    public function provider(): SocialProvider
    {
        return SocialProvider::YouTube;
    }

    public function maxTextLength(): int
    {
        return self::MAX_DESCRIPTION_BYTES;
    }

    public function defaultSettings(): array
    {
        return [
            'title' => '',
            'privacy' => 'public',
            'format' => 'auto',
            'category' => '22',
            'tags' => '',
            'made_for_kids' => false,
            'synthetic_media' => false,
            'notify_subscribers' => true,
            'thumbnail_media_id' => null,
        ];
    }

    public function settingsRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH, 'not_regex:/[<>]/'],
            'privacy' => ['required', Rule::in(array_keys(self::PRIVACY_OPTIONS))],
            'format' => ['required', Rule::in(array_keys(self::FORMAT_OPTIONS))],
            'category' => ['required', Rule::in(array_keys(self::CATEGORY_OPTIONS))],
            'tags' => ['nullable', 'string', 'not_regex:/[<>]/'],
            'made_for_kids' => ['required', 'boolean'],
            'synthetic_media' => ['nullable', 'boolean'],
            'notify_subscribers' => ['nullable', 'boolean'],
            'thumbnail_media_id' => ['nullable', 'integer'],
        ];
    }

    public function mediaErrors(Collection $media): array
    {
        $videos = $media->filter(fn (Media $item): bool => in_array($item->kind, [MediaKind::Video, MediaKind::Audio], true));

        return match (true) {
            $videos->isEmpty() => ['YouTube needs one video (or an audio file, posted as a video).'],
            $media->count() > 1 => ['YouTube takes exactly one video per post. Pick the thumbnail in the YouTube settings instead of adding images.'],
            default => [],
        };
    }

    public function targetErrors(string $text, Collection $media, array $settings): array
    {
        $errors = [];

        if (strlen($text) > self::MAX_DESCRIPTION_BYTES) {
            $errors[] = 'the description is '.number_format(strlen($text)).' bytes; YouTube allows '.number_format(self::MAX_DESCRIPTION_BYTES).'.';
        }

        $tagsLength = self::tagsLength(self::parseTags((string) ($settings['tags'] ?? '')));

        if ($tagsLength > self::MAX_TAGS_LENGTH) {
            $errors[] = "the tags are {$tagsLength} characters long; YouTube allows ".self::MAX_TAGS_LENGTH.'.';
        }

        $thumbnailId = $settings['thumbnail_media_id'] ?? null;

        if ($thumbnailId !== null && Media::query()->whereKey($thumbnailId)->where('kind', MediaKind::Image)->doesntExist()) {
            $errors[] = 'pick an image from the media library as the thumbnail.';
        }

        $video = $media->first();

        if (($settings['format'] ?? 'auto') === 'short' && $video instanceof Media) {
            $duration = $video->duration_seconds === null ? null : (float) $video->duration_seconds;

            if ($duration !== null && $duration > self::MAX_SHORT_SECONDS) {
                $errors[] = 'a Short can be at most 3 minutes; this video is '.gmdate('i:s', (int) $duration).'.';
            }

            if ($video->kind === MediaKind::Audio || ($video->width !== null && $video->height !== null && $video->width > $video->height)) {
                $errors[] = 'a Short needs a vertical or square video.';
            }
        }

        return $errors;
    }

    public function describe(): array
    {
        return [
            'max_text_length' => self::MAX_DESCRIPTION_BYTES,
            'media_rules' => 'Exactly 1 video (or 1 audio file, posted as a video). Pick an image as the thumbnail in the YouTube settings.',
            'settings' => [
                'title' => ['type' => 'text', 'label' => 'Video title', 'max_length' => self::MAX_TITLE_LENGTH, 'required' => true, 'default' => ''],
                'format' => ['type' => 'select', 'label' => 'Format', 'options' => self::FORMAT_OPTIONS, 'default' => 'auto'],
                'privacy' => ['type' => 'select', 'label' => 'Privacy', 'options' => self::PRIVACY_OPTIONS, 'default' => 'public'],
                'category' => ['type' => 'select', 'label' => 'Category', 'options' => self::CATEGORY_OPTIONS, 'default' => '22'],
                'tags' => ['type' => 'tags', 'label' => 'Tags (comma separated)', 'max_length' => self::MAX_TAGS_LENGTH, 'default' => ''],
                'thumbnail_media_id' => ['type' => 'image', 'label' => 'Thumbnail (optional)', 'default' => null],
                'made_for_kids' => ['type' => 'boolean', 'label' => 'Made for kids', 'default' => false],
                'synthetic_media' => ['type' => 'boolean', 'label' => 'Contains realistic AI or altered content', 'default' => false],
                'notify_subscribers' => ['type' => 'boolean', 'label' => 'Notify subscribers', 'default' => true],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function parseTags(string $tags): array
    {
        return collect(explode(',', $tags))
            ->map(fn (string $tag): string => trim(ltrim(trim($tag), '#')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * YouTube counts the commas between tags, and a tag with a space as if it had quotes around it.
     *
     * @param  array<int, string>  $tags
     */
    public static function tagsLength(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $length = collect($tags)->sum(fn (string $tag): int => mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0));

        return $length + count($tags) - 1;
    }

    public static function isShort(Media $video, string $format): bool
    {
        if ($format !== 'auto') {
            return $format === 'short';
        }

        $duration = $video->duration_seconds === null ? null : (float) $video->duration_seconds;

        return $video->kind === MediaKind::Video
            && $duration !== null && $duration <= self::MAX_SHORT_SECONDS
            && $video->width !== null && $video->height !== null && $video->height >= $video->width;
    }
}
