<?php

namespace App\Services\Posts\Import;

use App\Models\Media;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ImportRow
{
    /**
     * @param  array{
     *     title: string|null,
     *     content: string,
     *     timezone: string,
     *     scheduled_at: string|null,
     *     targets: array<int, array{social_account_id: int, content_override: null, settings: array<string, mixed>}>,
     *     media_ids: array<int, int>,
     * }  $postData
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Collection<int, Media>  $media
     * @param  array<int, string>  $mediaNames
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly bool $schedule,
        public readonly array $postData,
        public readonly ?Carbon $scheduledAtUtc,
        public readonly Collection $accounts,
        public readonly Collection $media,
        public readonly array $mediaNames,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array{
     *     row: int,
     *     status: string,
     *     status_label: string,
     *     scheduled_at: string|null,
     *     timezone: string,
     *     title: string|null,
     *     caption: string,
     *     accounts: array<int, array{id: int, name: string, provider: string}>,
     *     media: array<int, string>,
     *     errors: array<int, string>,
     * }
     */
    public function toPreview(): array
    {
        return [
            'row' => $this->rowNumber,
            'status' => $this->schedule ? 'scheduled' : 'draft',
            'status_label' => $this->schedule ? 'Scheduled' : 'Draft',
            'scheduled_at' => $this->scheduledAtUtc?->toIso8601String(),
            'timezone' => $this->postData['timezone'],
            'title' => $this->postData['title'],
            'caption' => $this->postData['content'],
            'accounts' => $this->accounts->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider->value,
            ])->values()->all(),
            'media' => $this->mediaNames,
            'errors' => $this->errors,
        ];
    }
}
