<?php

namespace App\Services\LinkedIn;

use App\Enums\MediaKind;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\LogService;
use App\Services\Media\AudioToVideoConverter;
use App\Services\Posts\Rules\LinkedInPostRules;
use App\Services\Publishing\Publisher;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class LinkedInPublisher implements Publisher
{
    public function __construct(
        private LinkedInApiClient $client,
        private LinkedInMediaUploader $uploader,
        private LinkedInCommentaryFormatter $formatter,
        private AudioToVideoConverter $audioToVideoConverter,
        private LinkedInPostRules $rules,
    ) {}

    public function provider(): SocialProvider
    {
        return SocialProvider::LinkedIn;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $target->loadMissing(['post.media', 'socialAccount']);
        $post = $target->post;
        $account = $target->socialAccount;
        $media = $post->media;

        $mediaErrors = $this->rules->mediaErrors($media);

        if ($mediaErrors !== []) {
            throw PublishException::permanent($mediaErrors[0]);
        }

        $visibility = (string) ($target->settings['visibility'] ?? 'PUBLIC');

        if (! array_key_exists($visibility, LinkedInPostRules::VISIBILITY_OPTIONS)) {
            throw PublishException::permanent("\"{$visibility}\" is not a LinkedIn visibility option.");
        }

        LogService::info('Sending post to LinkedIn', [
            'media_kind' => $media->first()?->kind->value,
            'media_count' => $media->count(),
            'visibility' => $visibility,
        ]);

        $body = [
            'author' => $this->uploader->authorUrn($account),
            'commentary' => $this->formatter->format($target->contentFor($post)),
            'visibility' => $visibility,
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $content = $this->contentFor($account, $media);

        if ($content !== null) {
            $body['content'] = $content;
        }

        $response = $this->client->post($account, '/posts', $body, 'create the post');
        $postUrn = trim((string) $response->header('x-restli-id'));

        if ($postUrn === '') {
            throw PublishException::permanent('LinkedIn accepted the post but did not return its id. Check your profile before retrying.');
        }

        LogService::info('LinkedIn post created', ['external_id' => $postUrn]);

        return new PublishResult($postUrn, "https://www.linkedin.com/feed/update/{$postUrn}/");
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array<string, mixed>|null
     *
     * @throws PublishException
     */
    private function contentFor(SocialAccount $account, Collection $media): ?array
    {
        $first = $media->first();

        if ($first === null) {
            return null;
        }

        return match ($first->kind) {
            MediaKind::Image => $media->count() === 1
                ? ['media' => $this->imageEntry($account, $first)]
                : ['multiImage' => ['images' => $media->map(fn (Media $image): array => $this->imageEntry($account, $image))->values()->all()]],
            MediaKind::Video => ['media' => [
                'id' => $this->uploader->uploadVideo($account, Storage::disk($first->disk)->path($first->path)),
                'title' => $this->titleFor($first),
            ]],
            MediaKind::Audio => ['media' => [
                'id' => $this->uploadAudioAsVideo($account, $first),
                'title' => $this->titleFor($first),
            ]],
            MediaKind::Document => ['media' => [
                'id' => $this->uploader->uploadDocument($account, $first),
                'title' => $this->titleFor($first),
            ]],
        };
    }

    /**
     * @return array{id: string, altText?: string}
     *
     * @throws PublishException
     */
    private function imageEntry(SocialAccount $account, Media $image): array
    {
        $entry = ['id' => $this->uploader->uploadImage($account, $image)];

        if (filled($image->alt_text)) {
            $entry['altText'] = (string) $image->alt_text;
        }

        return $entry;
    }

    /**
     * @throws PublishException
     */
    private function uploadAudioAsVideo(SocialAccount $account, Media $audio): string
    {
        $videoPath = $this->audioToVideoConverter->convert(Storage::disk($audio->disk)->path($audio->path));

        try {
            return $this->uploader->uploadVideo($account, $videoPath);
        } finally {
            @unlink($videoPath);
        }
    }

    private function titleFor(Media $media): string
    {
        return pathinfo($media->original_name, PATHINFO_FILENAME) ?: $media->original_name;
    }
}
