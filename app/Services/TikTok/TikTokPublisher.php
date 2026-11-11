<?php

namespace App\Services\TikTok;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\LogService;
use App\Services\Media\AudioToVideoConverter;
use App\Services\Posts\Rules\TikTokPostRules;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Illuminate\Support\Facades\Storage;

class TikTokPublisher implements Publisher
{
    public function __construct(
        private TikTokUploader $uploader,
        private AudioToVideoConverter $audioToVideoConverter,
        private TikTokPostRules $rules,
    ) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::TikTok;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $target->loadMissing(['post.media', 'socialAccount']);
        $account = $target->socialAccount;
        $media = $target->post->media;
        $settings = [...$this->rules->defaultSettings(), ...($target->settings ?? [])];
        $caption = $target->contentFor($target->post);

        $errors = [...$this->rules->mediaErrors($media), ...$this->rules->targetErrors($caption, $media, $settings)];

        if ($errors !== []) {
            throw PublishException::permanent(ucfirst($errors[0]));
        }

        /** @var Media $video */
        $video = $media->first();
        $isDirect = $settings['mode'] === 'direct';
        $postInfo = $isDirect ? $this->postInfo($account, $video, $caption, $settings) : null;

        LogService::info('Sending video to TikTok', [
            'media_kind' => $video->kind->value,
            'media_id' => $video->id,
            'mode' => $isDirect ? 'direct' : 'inbox',
            'privacy' => $isDirect ? $settings['privacy'] : null,
        ]);

        $publishId = $this->upload($account, $video, $postInfo);
        $result = $this->uploader->waitForResult($account, $publishId);

        return $this->toResult($account, $publishId, $result, $isDirect, (string) $settings['privacy'], $caption);
    }

    /**
     * Check with TikTok what the account may post now, then build post_info.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function postInfo(SocialAccount $account, Media $video, string $caption, array $settings): array
    {
        $creator = $this->uploader->creatorInfo($account);
        $allowedPrivacy = array_map('strval', (array) ($creator['privacy_level_options'] ?? []));
        $privacy = (string) $settings['privacy'];

        LogService::info('TikTok creator info checked', [
            'privacy_level_options' => $allowedPrivacy,
            'max_video_post_duration_sec' => $creator['max_video_post_duration_sec'] ?? null,
        ]);

        if ($allowedPrivacy !== [] && ! in_array($privacy, $allowedPrivacy, true)) {
            $allowed = implode(', ', array_map(fn (string $option): string => TikTokPostRules::PRIVACY_OPTIONS[$option] ?? $option, $allowedPrivacy));

            throw PublishException::permanent("TikTok doesn't allow \"".(TikTokPostRules::PRIVACY_OPTIONS[$privacy] ?? $privacy)."\" for this account right now. Allowed: {$allowed}. Change \"Who can see it\" and retry.");
        }

        $maxSeconds = (int) ($creator['max_video_post_duration_sec'] ?? 0);

        if ($maxSeconds > 0 && $video->duration_seconds !== null && (float) $video->duration_seconds > $maxSeconds) {
            throw PublishException::permanent("This TikTok account can post videos up to {$maxSeconds} s; this one is ".round((float) $video->duration_seconds).' s.');
        }

        return [
            'title' => $caption,
            'privacy_level' => $privacy,
            'disable_comment' => ! $settings['allow_comments'] || (bool) ($creator['comment_disabled'] ?? false),
            'disable_duet' => ! $settings['allow_duet'] || (bool) ($creator['duet_disabled'] ?? false),
            'disable_stitch' => ! $settings['allow_stitch'] || (bool) ($creator['stitch_disabled'] ?? false),
            'brand_organic_toggle' => $settings['commercial'] === 'your_brand',
            'brand_content_toggle' => $settings['commercial'] === 'branded',
            'is_aigc' => (bool) $settings['ai_generated'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $postInfo
     */
    private function upload(SocialAccount $account, Media $video, ?array $postInfo): string
    {
        $path = Storage::disk($video->disk)->path($video->path);

        if (! is_file($path)) {
            throw PublishException::permanent("The file {$video->original_name} is missing from the media library.");
        }

        if ($video->kind !== MediaKind::Audio) {
            return $this->uploader->upload($account, $path, $video->mime_type, $postInfo);
        }

        $videoPath = $this->audioToVideoConverter->convert($path);

        try {
            return $this->uploader->upload($account, $videoPath, 'video/mp4', $postInfo);
        } finally {
            @unlink($videoPath);
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function toResult(SocialAccount $account, string $publishId, ?array $result, bool $isDirect, string $privacy, string $caption): PublishResult
    {
        $status = (string) ($result['status'] ?? '');
        $profileUrl = filled($account->handle) ? "https://www.tiktok.com/@{$account->handle}" : null;

        if ($status === 'FAILED') {
            $reason = (string) ($result['fail_reason'] ?? 'unknown');
            LogService::warning('TikTok could not post the video', ['publish_id' => $publishId, 'fail_reason' => $reason]);

            throw PublishException::permanent("TikTok could not post the video ({$reason}).");
        }

        if ($status === 'SEND_TO_USER_INBOX') {
            LogService::info('TikTok video sent to inbox', ['publish_id' => $publishId]);

            return new PublishResult($publishId, $profileUrl, 'Sent to your TikTok inbox. Open the TikTok app to post it.'.($caption !== '' ? " Caption to paste: {$caption}" : ''));
        }

        if ($status === 'PUBLISH_COMPLETE') {
            $postIds = (array) ($result['publicaly_available_post_id'] ?? $result['publicly_available_post_id'] ?? []);
            $postId = $postIds === [] ? null : (string) reset($postIds);

            LogService::info('TikTok video published', ['publish_id' => $publishId, 'post_id' => $postId, 'privacy' => $privacy]);

            if ($postId !== null && $profileUrl !== null) {
                return new PublishResult($postId, "{$profileUrl}/video/{$postId}");
            }

            return new PublishResult($postId ?? $publishId, $profileUrl, $privacy === 'SELF_ONLY'
                ? 'Published as "Only me". Open it from your TikTok profile.'
                : 'Published. TikTok is still checking it, so the link opens your profile.');
        }

        LogService::warning('TikTok was still processing the video', ['publish_id' => $publishId, 'status' => $status]);

        return new PublishResult($publishId, $profileUrl, $isDirect
            ? 'TikTok is still processing the video. Check the TikTok app in a few minutes.'
            : 'TikTok is still processing the upload. It will appear in your TikTok inbox.'.($caption !== '' ? " Caption to paste: {$caption}" : ''));
    }
}
