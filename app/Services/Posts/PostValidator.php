<?php

namespace App\Services\Posts;

use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\Rules\PlatformRulesRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;

class PostValidator
{
    public function __construct(private PlatformRulesRegistry $platformRulesRegistry) {}

    /**
     * Everything that stops a post from being scheduled, keyed for the form.
     *
     * @return array<string, string>
     */
    public function scheduleErrors(Post $post): array
    {
        $post->loadMissing(['targets.socialAccount', 'media']);
        $errors = [];

        if ($post->targets->isEmpty()) {
            $errors['targets'] = 'Pick at least one account.';
        }

        if ($post->scheduled_at === null || $post->scheduled_at->isPast()) {
            $errors['scheduled_at'] = 'Pick a date and time in the future.';
        }

        return [...$errors, ...$this->contentAndTargetErrors($post, $post->targets)];
    }

    /**
     * Everything that stops the failed accounts of a post from being sent again. The time is not checked:
     * a retry goes out now.
     *
     * @return array<string, string>
     */
    public function retryErrors(Post $post): array
    {
        $post->loadMissing(['targets.socialAccount', 'media']);

        return $this->contentAndTargetErrors($post, $post->targets->where('status', PostTargetStatus::Failed));
    }

    /**
     * @param  Collection<int, PostTarget>  $targets
     * @return array<string, string>
     */
    private function contentAndTargetErrors(Post $post, Collection $targets): array
    {
        $errors = [];

        if (trim($post->content) === '' && $post->media->isEmpty() && $post->targets->every(fn (PostTarget $target): bool => blank($target->content_override))) {
            $errors['content'] = 'Write something or add media.';
        }

        foreach ($targets as $target) {
            $targetError = $this->targetError($post, $target);

            if ($targetError !== null) {
                $errors["targets.{$target->social_account_id}"] = $targetError;
            }
        }

        return $errors;
    }

    private function targetError(Post $post, PostTarget $target): ?string
    {
        $account = $target->socialAccount;
        $rules = $this->platformRulesRegistry->forProvider($account->provider);
        $prefix = "{$account->name} ({$account->provider->label()}):";

        if ($rules === null) {
            return "{$prefix} posting is not available yet.";
        }

        if ($account->status() === SocialAccountStatus::ReconnectNeeded) {
            return "{$prefix} reconnect this account first.";
        }

        $length = mb_strlen($target->contentFor($post));

        if ($length > $rules->maxTextLength()) {
            return "{$prefix} the text is ".Number::format($length).' characters; the limit is '.Number::format($rules->maxTextLength()).'.';
        }

        $mediaErrors = $rules->mediaErrors($post->media);

        if ($mediaErrors !== []) {
            return "{$prefix} {$mediaErrors[0]}";
        }

        $settingsValidator = Validator::make($target->settings ?? [], $rules->settingsRules());

        if ($settingsValidator->fails()) {
            return "{$prefix} ".$settingsValidator->errors()->first();
        }

        $targetErrors = $rules->targetErrors($target->contentFor($post), $post->media, $target->settings ?? []);

        return $targetErrors === [] ? null : "{$prefix} {$targetErrors[0]}";
    }
}
