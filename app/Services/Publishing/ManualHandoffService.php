<?php

namespace App\Services\Publishing;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Enums\PostTargetStatus;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\LogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class ManualHandoffService
{
    /**
     * Where the owner posts by hand, and which links "Mark as posted" accepts.
     *
     * @var array<string, array{label: string, url: string, link_pattern: string, link_example: string}>
     */
    public const DESTINATIONS = [
        'tiktok' => [
            'label' => 'TikTok Studio',
            'url' => 'https://www.tiktok.com/tiktokstudio/upload',
            'link_pattern' => '#^https://(www\.|m\.|vm\.|vt\.)?tiktok\.com/#i',
            'link_example' => 'https://www.tiktok.com/...',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'url' => 'https://www.instagram.com/',
            'link_pattern' => '#^https://(www\.)?instagram\.com/#i',
            'link_example' => 'https://www.instagram.com/p/...',
        ],
    ];

    public function __construct(private PostStatusResolver $postStatusResolver) {}

    /**
     * Accounts whose post waits for the owner to upload it by hand, oldest first.
     *
     * @return Collection<int, PostTarget>
     */
    public function waiting(Workspace $workspace): Collection
    {
        return PostTarget::query()
            ->where('status', PostTargetStatus::ReadyToPost)
            ->whereHas('post', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['post.media', 'socialAccount'])
            ->orderBy('updated_at')
            ->get();
    }

    public function waitingCount(Workspace $workspace): int
    {
        return PostTarget::query()
            ->where('status', PostTargetStatus::ReadyToPost)
            ->whereHas('post', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->count();
    }

    /**
     * The files the owner downloads: the video for TikTok, every photo and video for Instagram.
     *
     * @return SupportCollection<int, Media>
     */
    public function files(PostTarget $target): SupportCollection
    {
        $kinds = $target->socialAccount->provider === SocialProvider::TikTok
            ? [MediaKind::Video]
            : [MediaKind::Image, MediaKind::Video];

        return $target->post->media->filter(fn (Media $media): bool => in_array($media->kind, $kinds, true))->values();
    }

    /**
     * @return array{label: string, url: string, link_pattern: string, link_example: string}
     */
    public function destination(SocialProvider $provider): array
    {
        return self::DESTINATIONS[$provider->value] ?? [
            'label' => $provider->label(),
            'url' => '',
            'link_pattern' => '#^https://#i',
            'link_example' => 'https://...',
        ];
    }

    /**
     * A clean file name for the download, from the post's title or first words (numbered when there are several).
     */
    public function downloadName(PostTarget $target, Media $media): string
    {
        $files = $this->files($target);
        $base = Str::slug(Str::limit($target->post->title ?: $target->contentFor($target->post), 60, '')) ?: 'postloom-media';
        $position = $files->search(fn (Media $file): bool => $file->is($media));
        $suffix = $files->count() > 1 && $position !== false ? '-'.($position + 1) : '';
        $extension = pathinfo($media->original_name, PATHINFO_EXTENSION) ?: ($media->kind === MediaKind::Image ? 'jpg' : 'mp4');

        return "{$base}{$suffix}.{$extension}";
    }

    public function markPosted(PostTarget $target, ?string $url): void
    {
        LogService::startFeatureLogging(LogFeature::Publishing, [
            'workspace_id' => $target->post->workspace_id,
            'post_id' => $target->post_id,
            'target_id' => $target->id,
        ]);

        $target->update([
            'status' => PostTargetStatus::Published,
            'external_url' => $url,
            'published_at' => now(),
            'last_error' => null,
        ]);

        LogService::info('Hand-off marked as posted', ['account_id' => $target->social_account_id, 'has_link' => $url !== null]);

        $this->postStatusResolver->resolve($target->post);
    }
}
