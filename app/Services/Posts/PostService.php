<?php

namespace App\Services\Posts;

use App\Enums\LogFeature;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\Posts\Rules\PlatformRulesRegistry;
use App\Services\Publishing\PostDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(
        private PostValidator $postValidator,
        private PlatformRulesRegistry $platformRulesRegistry,
        private PostDispatcher $postDispatcher,
    ) {}

    /**
     * Send a just-scheduled post to the publish queue now instead of waiting for its minute.
     * Returns false when publishing is turned off (the post then stays scheduled).
     */
    public function publishNow(Post $post): bool
    {
        if ($post->status !== PostStatus::Scheduled || ! PostDispatcher::isEnabled()) {
            return false;
        }

        LogService::startFeatureLogging(LogFeature::Publishing, ['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);
        LogService::info('Publish now requested', []);

        return $this->postDispatcher->dispatchPost($post);
    }

    /**
     * Send only the failed accounts of a failed or partly published post again.
     */
    public function retryFailed(Post $post): Post
    {
        if (! in_array($post->status, [PostStatus::Failed, PostStatus::PartlyPublished], true)) {
            throw ValidationException::withMessages(['post' => 'Only failed or partly published posts can be retried.']);
        }

        $retriedTargets = $post->targets()->where('status', PostTargetStatus::Failed)->update([
            'status' => PostTargetStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
        ]);

        $post->update([
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $post->scheduled_at !== null && $post->scheduled_at->isFuture() ? $post->scheduled_at : now(),
        ]);

        LogService::startFeatureLogging(LogFeature::Publishing, ['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);
        LogService::info('Retry of failed accounts requested', ['accounts' => $retriedTargets]);

        if (PostDispatcher::isEnabled()) {
            $this->postDispatcher->dispatchPost($post);
        }

        return $post->refresh();
    }

    /**
     * Create or update a post with its accounts and media. With $schedule the post is also scheduled;
     * if it can't be, nothing is saved and a ValidationException lists the reasons.
     *
     * @param  array{
     *     title?: string|null,
     *     content?: string|null,
     *     timezone: string,
     *     scheduled_at?: string|null,
     *     targets?: array<int, array{social_account_id: int, content_override?: string|null, settings?: array<string, mixed>|null}>,
     *     media_ids?: array<int, int>,
     * }  $data
     */
    public function save(Workspace $workspace, User $user, array $data, bool $schedule, ?Post $post = null): Post
    {
        if ($post !== null) {
            $this->ensureEditable($post);
        }

        LogService::startFeatureLogging(LogFeature::Posts, ['workspace_id' => $workspace->id, 'post_id' => $post?->id]);

        $post = DB::transaction(function () use ($workspace, $user, $data, $schedule, $post): Post {
            $post ??= new Post;
            $isNew = ! $post->exists;

            $post->fill([
                'title' => $data['title'] ?? null,
                'content' => $data['content'] ?? '',
                'timezone' => $data['timezone'],
                'scheduled_at' => $this->toUtc($data['scheduled_at'] ?? null, $data['timezone']),
                'status' => PostStatus::Draft,
            ]);
            $post->workspace()->associate($workspace);
            $post->user()->associate($post->user_id ?? $user->id);
            $post->save();

            $this->syncTargets($post, $data['targets'] ?? []);
            $this->syncMedia($post, $data['media_ids'] ?? []);

            LogService::info($isNew ? 'Post created' : 'Post updated', [
                'post_id' => $post->id,
                'targets' => count($data['targets'] ?? []),
                'media' => count($data['media_ids'] ?? []),
            ]);

            if ($schedule) {
                $this->markScheduled($post);
            }

            return $post;
        });

        return $post->refresh();
    }

    /**
     * Move a draft or scheduled post to a new moment (from the calendar). The status stays as it is.
     */
    public function reschedule(Post $post, Carbon $newTime): Post
    {
        LogService::startFeatureLogging(LogFeature::Calendar, ['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);

        $reason = match (true) {
            ! $post->status->isMovable() => "A {$post->status->label()} post can't be moved.",
            $newTime->isPast() => 'Pick a date and time in the future.',
            default => null,
        };

        if ($reason !== null) {
            LogService::warning('Reschedule refused', ['reason' => $reason, 'requested_at' => $newTime->toIso8601String()]);

            throw ValidationException::withMessages(['scheduled_at' => $reason]);
        }

        $oldTime = $post->scheduled_at?->toIso8601String();
        $post->update(['scheduled_at' => $newTime->copy()->utc()]);

        LogService::info('Post rescheduled', [
            'from' => $oldTime,
            'to' => $post->scheduled_at?->toIso8601String(),
            'status' => $post->status->value,
        ]);

        return $post;
    }

    public function unschedule(Post $post): Post
    {
        $this->ensureEditable($post);

        $post->update(['status' => PostStatus::Draft]);

        LogService::startFeatureLogging(LogFeature::Posts, ['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);
        LogService::info('Post moved back to draft', []);

        return $post;
    }

    public function duplicate(Post $post): Post
    {
        return DB::transaction(function () use ($post): Post {
            $post->loadMissing(['targets', 'media']);

            $copy = $post->replicate(['status', 'scheduled_at']);
            $copy->fill([
                'title' => 'Copy of '.($post->title ?: 'post #'.$post->id),
                'status' => PostStatus::Draft,
                'scheduled_at' => null,
            ]);
            $copy->save();

            foreach ($post->targets as $target) {
                $copy->targets()->create([
                    'social_account_id' => $target->social_account_id,
                    'content_override' => $target->content_override,
                    'settings' => $target->settings,
                    'status' => PostTargetStatus::Pending,
                ]);
            }

            $this->syncMedia($copy, $post->media->pluck('id')->all());

            LogService::startFeatureLogging(LogFeature::Posts, ['workspace_id' => $post->workspace_id, 'post_id' => $copy->id]);
            LogService::info('Post duplicated', ['source_post_id' => $post->id]);

            return $copy;
        });
    }

    public function delete(Post $post): void
    {
        $this->ensureEditable($post);

        $postId = $post->id;
        $post->delete();

        LogService::startFeatureLogging(LogFeature::Posts, ['workspace_id' => $post->workspace_id, 'post_id' => $postId]);
        LogService::info('Post deleted', []);
    }

    private function markScheduled(Post $post): void
    {
        $post->unsetRelation('targets')->unsetRelation('media');
        $errors = $this->postValidator->scheduleErrors($post);

        if ($errors !== []) {
            LogService::warning('Post could not be scheduled', ['post_id' => $post->id, 'reasons' => array_values($errors)]);

            throw ValidationException::withMessages($errors);
        }

        $post->update(['status' => PostStatus::Scheduled]);
        $post->targets()->update(['status' => PostTargetStatus::Pending, 'last_error' => null]);

        LogService::info('Post scheduled', [
            'post_id' => $post->id,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'timezone' => $post->timezone,
        ]);
    }

    /**
     * @param  array<int, array{social_account_id: int, content_override?: string|null, settings?: array<string, mixed>|null}>  $targets
     */
    private function syncTargets(Post $post, array $targets): void
    {
        $accountIds = collect($targets)->pluck('social_account_id')->map(fn ($id): int => (int) $id)->all();
        $post->targets()->whereNotIn('social_account_id', $accountIds)->delete();

        $accounts = SocialAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        foreach ($targets as $target) {
            $account = $accounts->get((int) $target['social_account_id']);

            if ($account === null) {
                continue;
            }

            $defaults = $this->platformRulesRegistry->forProvider($account->provider)?->defaultSettings() ?? [];

            $post->targets()->updateOrCreate(
                ['social_account_id' => $account->id],
                [
                    'content_override' => filled($target['content_override'] ?? null) ? $target['content_override'] : null,
                    'settings' => [...$defaults, ...($target['settings'] ?? [])],
                    'status' => PostTargetStatus::Pending,
                ],
            );
        }
    }

    /**
     * @param  array<int, int>  $mediaIds
     */
    private function syncMedia(Post $post, array $mediaIds): void
    {
        $post->media()->sync(
            collect($mediaIds)->values()->mapWithKeys(fn ($mediaId, int $index): array => [(int) $mediaId => ['sort_order' => $index]])->all(),
        );
    }

    private function toUtc(?string $localDateTime, string $timezone): ?Carbon
    {
        if (blank($localDateTime)) {
            return null;
        }

        return Carbon::parse($localDateTime, $timezone)->utc();
    }

    private function ensureEditable(Post $post): void
    {
        if (! $post->status->isEditable()) {
            throw ValidationException::withMessages([
                'post' => 'This post is being published right now and can\'t be changed.',
            ]);
        }
    }
}
