<?php

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Services\LogService;

class PostStatusResolver
{
    /**
     * Once every account of a publishing post is done, set the post to published, partly published or failed.
     */
    public function resolve(Post $post): PostStatus
    {
        $post->refresh();

        if ($post->status !== PostStatus::Publishing) {
            return $post->status;
        }

        $statuses = $post->targets()->pluck('status');

        if ($statuses->contains(fn (PostTargetStatus $status): bool => in_array($status, [PostTargetStatus::Pending, PostTargetStatus::Publishing], true))) {
            return $post->status;
        }

        $publishedCount = $statuses->filter(fn (PostTargetStatus $status): bool => $status === PostTargetStatus::Published)->count();

        $newStatus = match (true) {
            $statuses->isEmpty(), $publishedCount === 0 => PostStatus::Failed,
            $publishedCount === $statuses->count() => PostStatus::Published,
            default => PostStatus::PartlyPublished,
        };

        $post->update(['status' => $newStatus]);

        LogService::info('Post status resolved', [
            'post_id' => $post->id,
            'status' => $newStatus->value,
            'published' => $publishedCount,
            'accounts' => $statuses->count(),
        ]);

        return $newStatus;
    }
}
