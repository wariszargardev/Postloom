<?php

namespace App\Services\Publishing;

use App\Enums\LogFeature;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\LogService;

class PostDispatcher
{
    public const MISSED_MESSAGE = 'Missed: Postloom was off at the scheduled time. Reschedule or retry it.';

    public const TIMED_OUT_MESSAGE = 'Timed out: no result from the publisher. Retry it.';

    public function __construct(private PostStatusResolver $postStatusResolver) {}

    public static function isEnabled(): bool
    {
        return (bool) config('postloom.publishing.enabled');
    }

    /**
     * @return array{enabled: bool, dispatched: int, missed: int, timed_out: int}
     */
    public function dispatchDue(): array
    {
        LogService::startFeatureLogging(LogFeature::Scheduler, ['command' => 'posts:dispatch-due']);

        if (! self::isEnabled()) {
            LogService::info('Publishing is turned off, due posts are left scheduled', []);

            return ['enabled' => false, 'dispatched' => 0, 'missed' => 0, 'timed_out' => 0];
        }

        $timedOut = $this->recoverStuckTargets();
        $missedBefore = now()->subHours((int) config('postloom.publishing.catch_up_hours'));
        $dispatched = 0;
        $missed = 0;

        Post::query()
            ->where('status', PostStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (Post $post) use ($missedBefore, &$dispatched, &$missed): void {
                if ($post->scheduled_at?->lt($missedBefore)) {
                    $missed += (int) $this->markMissed($post);

                    return;
                }

                $dispatched += (int) $this->dispatchPost($post);
            });

        $summary = ['enabled' => true, 'dispatched' => $dispatched, 'missed' => $missed, 'timed_out' => $timedOut];
        LogService::info('Due posts checked', $summary);

        return $summary;
    }

    /**
     * Lock a scheduled post and queue one job per pending account. Returns false if another run already took it.
     */
    public function dispatchPost(Post $post): bool
    {
        $locked = Post::query()->whereKey($post->id)->where('status', PostStatus::Scheduled)->update(['status' => PostStatus::Publishing]);

        if ($locked === 0) {
            return false;
        }

        $targets = $post->targets()->where('status', PostTargetStatus::Pending)->get();

        $targets->each(fn (PostTarget $target) => PublishPostTargetJob::dispatch($target->id));

        LogService::info('Post sent to the publish queue', [
            'post_id' => $post->id,
            'accounts' => $targets->count(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        ]);

        if ($targets->isEmpty()) {
            $this->postStatusResolver->resolve($post);
        }

        return true;
    }

    private function markMissed(Post $post): bool
    {
        $locked = Post::query()->whereKey($post->id)->where('status', PostStatus::Scheduled)->update(['status' => PostStatus::Failed]);

        if ($locked === 0) {
            return false;
        }

        $post->targets()->whereIn('status', [PostTargetStatus::Pending, PostTargetStatus::Publishing])->update([
            'status' => PostTargetStatus::Failed,
            'last_error' => self::MISSED_MESSAGE,
        ]);

        LogService::warning('Post missed its time and was not sent', [
            'post_id' => $post->id,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        ]);

        return true;
    }

    private function recoverStuckTargets(): int
    {
        $stuckBefore = now()->subMinutes((int) config('postloom.publishing.stuck_minutes'));

        $stuckTargets = PostTarget::query()
            ->with('post')
            ->where('status', PostTargetStatus::Publishing)
            ->where('updated_at', '<', $stuckBefore)
            ->get();

        foreach ($stuckTargets as $target) {
            $target->update(['status' => PostTargetStatus::Failed, 'last_error' => self::TIMED_OUT_MESSAGE]);

            LogService::warning('Publishing timed out for an account', ['post_id' => $target->post_id, 'target_id' => $target->id]);

            $this->postStatusResolver->resolve($target->post);
        }

        return $stuckTargets->count();
    }
}
