<?php

namespace App\Services\Posts\Rules;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TikTokPostRules implements PlatformPostRules
{
    public const MAX_TEXT_LENGTH = 2200;

    public const MIN_VIDEO_SECONDS = 3;

    public const MAX_VIDEO_SECONDS = 600;

    public const MAX_VIDEO_BYTES = 4 * 1024 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    /**
     * @var array<string, string>
     */
    public const MODE_OPTIONS = [
        'manual' => 'I\'ll post it myself (public)',
        'direct' => 'Post directly (Only me until TikTok reviews the app)',
        'inbox' => 'Send to TikTok inbox (doesn\'t arrive in sandbox)',
    ];

    /**
     * @var array<string, string>
     */
    public const PRIVACY_OPTIONS = [
        'SELF_ONLY' => 'Only me',
        'MUTUAL_FOLLOW_FRIENDS' => 'Friends',
        'FOLLOWER_OF_CREATOR' => 'Followers',
        'PUBLIC_TO_EVERYONE' => 'Everyone',
    ];

    /**
     * @var array<string, string>
     */
    public const COMMERCIAL_OPTIONS = [
        'none' => 'None',
        'your_brand' => 'Your brand (Promotional content)',
        'branded' => 'Branded content (Paid partnership)',
    ];

    public function provider(): SocialProvider
    {
        return SocialProvider::TikTok;
    }

    public function maxTextLength(): int
    {
        return self::MAX_TEXT_LENGTH;
    }

    public function defaultSettings(): array
    {
        return [
            'mode' => 'manual',
            'privacy' => 'SELF_ONLY',
            'allow_comments' => false,
            'allow_duet' => false,
            'allow_stitch' => false,
            'ai_generated' => false,
            'commercial' => 'none',
        ];
    }

    public function settingsRules(): array
    {
        return [
            'mode' => ['required', Rule::in(array_keys(self::MODE_OPTIONS))],
            'privacy' => ['required', Rule::in(array_keys(self::PRIVACY_OPTIONS))],
            'allow_comments' => ['boolean'],
            'allow_duet' => ['boolean'],
            'allow_stitch' => ['boolean'],
            'ai_generated' => ['boolean'],
            'commercial' => ['required', Rule::in(array_keys(self::COMMERCIAL_OPTIONS))],
        ];
    }

    public function mediaErrors(Collection $media): array
    {
        if ($media->isEmpty()) {
            return ['TikTok needs one video (or an audio file, posted as a video). Text-only posts aren\'t possible on TikTok.'];
        }

        if ($media->contains(fn (Media $item): bool => $item->kind === MediaKind::Image)) {
            return ['TikTok photo posts aren\'t supported yet (TikTok only takes photos from a public website). Use one video.'];
        }

        if ($media->contains(fn (Media $item): bool => $item->kind === MediaKind::Document)) {
            return ['TikTok can\'t post PDFs. Use one video.'];
        }

        if ($media->count() > 1) {
            return ['TikTok takes one video per post; this post has '.$media->count().' files.'];
        }

        /** @var Media $video */
        $video = $media->first();

        if ($video->kind === MediaKind::Audio) {
            return [];
        }

        if (! in_array($video->mime_type, self::VIDEO_MIME_TYPES, true)) {
            return ["TikTok doesn't take {$video->original_name}. Use MP4, MOV or WebM."];
        }

        if ($video->size_bytes > self::MAX_VIDEO_BYTES) {
            return ["{$video->original_name} is over TikTok's 4 GB limit."];
        }

        $duration = $video->duration_seconds === null ? null : (float) $video->duration_seconds;

        if ($duration !== null && ($duration < self::MIN_VIDEO_SECONDS || $duration > self::MAX_VIDEO_SECONDS)) {
            return ['TikTok videos must be '.self::MIN_VIDEO_SECONDS.' seconds to 10 minutes; this one is '.round($duration).' s.'];
        }

        return [];
    }

    public function targetErrors(string $text, Collection $media, array $settings): array
    {
        if (($settings['mode'] ?? 'manual') === 'manual' && $media->first()?->kind === MediaKind::Audio) {
            return ['"I\'ll post it myself" needs a video file you can upload to TikTok; use a video instead of audio.'];
        }

        if (($settings['mode'] ?? 'manual') === 'direct'
            && ($settings['commercial'] ?? 'none') === 'branded'
            && ($settings['privacy'] ?? 'SELF_ONLY') === 'SELF_ONLY') {
            return ['branded content can\'t be "Only me" on TikTok. Pick Friends, Followers or Everyone.'];
        }

        return [];
    }

    public function describe(): array
    {
        return [
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'media_rules' => 'Exactly 1 video (MP4, MOV or WebM, 3 s to 10 min, up to 4 GB) or 1 audio file (posted as a video). No photos, PDFs or text-only posts.',
            'settings' => [
                'mode' => [
                    'type' => 'select',
                    'label' => 'How to post',
                    'options' => self::MODE_OPTIONS,
                    'default' => 'manual',
                    'help' => '"Post it myself": at the time, the video and caption wait under To post for you to upload in TikTok Studio.',
                ],
                'privacy' => [
                    'type' => 'select',
                    'label' => 'Who can see it (direct posts)',
                    'options' => self::PRIVACY_OPTIONS,
                    'default' => 'SELF_ONLY',
                    'help' => 'Direct posts: only "Only me" works for now, and the account must be private.',
                ],
                'allow_comments' => ['type' => 'boolean', 'label' => 'Allow comments', 'default' => false],
                'allow_duet' => ['type' => 'boolean', 'label' => 'Allow Duet', 'default' => false],
                'allow_stitch' => ['type' => 'boolean', 'label' => 'Allow Stitch', 'default' => false],
                'ai_generated' => [
                    'type' => 'boolean',
                    'label' => 'AI-generated content',
                    'default' => false,
                    'help' => 'Adds TikTok\'s "AI-generated" label to the video.',
                ],
                'commercial' => [
                    'type' => 'select',
                    'label' => 'Commercial content',
                    'options' => self::COMMERCIAL_OPTIONS,
                    'default' => 'none',
                    'help' => 'Direct posts agree to TikTok\'s Music Usage Confirmation.',
                ],
            ],
        ];
    }
}
