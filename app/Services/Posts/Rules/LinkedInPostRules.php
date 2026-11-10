<?php

namespace App\Services\Posts\Rules;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class LinkedInPostRules implements PlatformPostRules
{
    public const MAX_TEXT_LENGTH = 3000;

    public const MAX_IMAGES = 20;

    /**
     * @var array<string, string>
     */
    public const VISIBILITY_OPTIONS = [
        'PUBLIC' => 'Anyone',
        'CONNECTIONS' => 'Connections only',
    ];

    public function provider(): SocialProvider
    {
        return SocialProvider::LinkedIn;
    }

    public function maxTextLength(): int
    {
        return self::MAX_TEXT_LENGTH;
    }

    public function defaultSettings(): array
    {
        return ['visibility' => 'PUBLIC'];
    }

    public function settingsRules(): array
    {
        return [
            'visibility' => ['required', Rule::in(array_keys(self::VISIBILITY_OPTIONS))],
        ];
    }

    public function mediaErrors(Collection $media): array
    {
        if ($media->isEmpty()) {
            return [];
        }

        $kinds = $media->map(fn (Media $item): MediaKind => $item->kind)->unique(fn (MediaKind $kind): string => $kind->value);

        if ($kinds->count() > 1) {
            return ['LinkedIn can\'t mix media types in one post. Use only images, or one video, one PDF or one audio file.'];
        }

        $kind = $kinds->first();
        $count = $media->count();

        return match (true) {
            $kind === MediaKind::Image && $count > self::MAX_IMAGES => ['LinkedIn allows up to '.self::MAX_IMAGES." images per post; this post has {$count}."],
            $kind === MediaKind::Video && $count > 1 => ['LinkedIn allows one video per post.'],
            $kind === MediaKind::Document && $count > 1 => ['LinkedIn allows one PDF per post.'],
            $kind === MediaKind::Audio && $count > 1 => ['LinkedIn allows one audio file per post (it is posted as a video).'],
            default => [],
        };
    }

    public function describe(): array
    {
        return [
            'max_text_length' => self::MAX_TEXT_LENGTH,
            'media_rules' => 'Up to '.self::MAX_IMAGES.' images, or 1 video, or 1 PDF, or 1 audio file. No mixing.',
            'settings' => [
                'visibility' => [
                    'label' => 'Who can see it',
                    'options' => self::VISIBILITY_OPTIONS,
                    'default' => 'PUBLIC',
                ],
            ],
        ];
    }
}
