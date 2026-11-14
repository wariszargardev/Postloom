<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletes a row together with the rows that belong to it. The database cascades only on a real delete,
 * so without this a deleted account would leave posts pointing at it. Rows deleted together share one deleted_at.
 */
class SoftDeleteCascade
{
    public function workspace(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            $postIds = $workspace->posts()->pluck('id')->all();
            $accountIds = $workspace->socialAccounts()->pluck('id')->all();

            $this->targets(PostTarget::query()->whereIn('post_id', $postIds)->orWhereIn('social_account_id', $accountIds)->pluck('id')->all());
            Post::query()->whereKey($postIds)->delete();
            SocialAccount::query()->whereKey($accountIds)->delete();
            Media::query()->where('workspace_id', $workspace->id)->delete();
            $workspace->delete();
        });
    }

    public function socialAccount(SocialAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $this->targets($account->postTargets()->pluck('id')->all());
            $account->delete();
        });
    }

    public function post(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $this->targets($post->targets()->pluck('id')->all());
            $post->delete();
        });
    }

    /**
     * @param  array<int, int>  $targetIds
     */
    public function targets(array $targetIds): void
    {
        if ($targetIds === []) {
            return;
        }

        PublishAttempt::query()->whereIn('post_target_id', $targetIds)->delete();
        PostTarget::query()->whereKey($targetIds)->delete();
    }
}
