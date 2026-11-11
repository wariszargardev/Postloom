<?php

namespace App\Services\Publishing;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Enums\PostTargetStatus;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\LogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ManualHandoffService
{
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

    public function video(PostTarget $target): ?Media
    {
        return $target->post->media->first(fn (Media $media): bool => $media->kind === MediaKind::Video);
    }

    /**
     * A clean file name for the download, from the post's title or first words.
     */
    public function downloadName(PostTarget $target, Media $video): string
    {
        $base = Str::slug(Str::limit($target->post->title ?: $target->contentFor($target->post), 60, '')) ?: 'postloom-video';

        return $base.'.'.(pathinfo($video->original_name, PATHINFO_EXTENSION) ?: 'mp4');
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
