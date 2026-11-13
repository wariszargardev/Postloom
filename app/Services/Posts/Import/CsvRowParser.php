<?php

namespace App\Services\Posts\Import;

use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Services\Posts\Rules\FacebookPostRules;
use App\Services\Posts\Rules\TikTokPostRules;
use App\Services\Posts\Rules\YouTubePostRules;
use DateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CsvRowParser
{
    public const MAX_CAPTION_LENGTH = 10000;

    public const MAX_NAME_LENGTH = 255;

    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d'];

    private const TIME_FORMATS = ['H:i', 'G:i', 'H:i:s', 'G:i:s', 'g:i A', 'g:i a', 'g:iA', 'g:ia'];

    /**
     * @param  Collection<int, SocialAccount>  $accounts  the workspace's accounts
     * @param  array<string, Media>  $mediaByName  lowercased file name => media
     */
    public function __construct(
        private Collection $accounts,
        private array $mediaByName,
        private string $defaultTimezone,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function settingColumns(): array
    {
        return array_keys(self::settingColumnRules());
    }

    /**
     * @return array<string, array{0: SocialProvider, 1: string, 2: array<int, string>|null}>
     */
    public static function settingColumnRules(): array
    {
        return [
            'youtube_title' => [SocialProvider::YouTube, 'title', null],
            'youtube_privacy' => [SocialProvider::YouTube, 'privacy', array_keys(YouTubePostRules::PRIVACY_OPTIONS)],
            'facebook_format' => [SocialProvider::Facebook, 'format', array_keys(FacebookPostRules::FORMAT_OPTIONS)],
            'tiktok_mode' => [SocialProvider::TikTok, 'mode', array_keys(TikTokPostRules::MODE_OPTIONS)],
        ];
    }

    /**
     * @param  array<string, string>  $cells  column => trimmed value
     */
    public function parse(int $rowNumber, array $cells): ImportRow
    {
        $errors = [];

        $schedule = $this->parseStatus($cells['status'] ?? '', $errors);
        $timezone = $this->parseTimezone($cells['timezone'] ?? '', $errors);
        $scheduledAt = $this->parseWhen($cells['date'] ?? '', $cells['time'] ?? '', $timezone, $schedule, $errors);
        $accounts = $this->matchAccounts($cells['accounts'] ?? '', $errors);
        $mediaNames = $this->splitList($cells['media'] ?? '');
        $media = $this->matchMedia($mediaNames, $errors);

        $caption = $cells['caption'] ?? '';
        $name = ($cells['name'] ?? '') === '' ? null : $cells['name'];

        if (mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            $errors[] = 'The caption is longer than '.number_format(self::MAX_CAPTION_LENGTH).' characters.';
        }

        if ($name !== null && mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = 'The name is longer than '.self::MAX_NAME_LENGTH.' characters.';
        }

        $settingsByProvider = $this->parseSettings($cells, $name, $errors);

        return new ImportRow(
            rowNumber: $rowNumber,
            schedule: $schedule,
            postData: [
                'title' => $name,
                'content' => $caption,
                'timezone' => $timezone,
                'scheduled_at' => $scheduledAt?->format('Y-m-d\TH:i'),
                'targets' => $accounts->map(fn (SocialAccount $account): array => [
                    'social_account_id' => $account->id,
                    'content_override' => null,
                    'settings' => $settingsByProvider[$account->provider->value] ?? [],
                ])->values()->all(),
                'media_ids' => $media->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ],
            scheduledAtUtc: $scheduledAt?->copy()->utc(),
            accounts: $accounts,
            media: $media,
            mediaNames: $mediaNames,
            errors: $errors,
        );
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseStatus(string $value, array &$errors): bool
    {
        $normalized = strtolower($value);

        if ($normalized === 'draft') {
            return false;
        }

        if (! in_array($normalized, ['', 'schedule', 'scheduled'], true)) {
            $errors[] = "Status \"{$value}\" is not known. Use schedule or draft.";
        }

        return true;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseTimezone(string $value, array &$errors): string
    {
        if ($value === '') {
            return $this->defaultTimezone;
        }

        if (! in_array($value, timezone_identifiers_list(), true)) {
            $errors[] = "Timezone \"{$value}\" is not known. Use a name like Asia/Karachi.";

            return $this->defaultTimezone;
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseWhen(string $date, string $time, string $timezone, bool $schedule, array &$errors): ?Carbon
    {
        if ($date === '' && $time === '') {
            if ($schedule) {
                $errors[] = 'Add a date and time, or set status to draft.';
            }

            return null;
        }

        $parsedDate = $this->parseWithFormats($date, self::DATE_FORMATS);
        $parsedTime = $this->parseWithFormats($time, self::TIME_FORMATS);

        if ($parsedDate === null) {
            $errors[] = $date === '' ? 'Add a date.' : "Date \"{$date}\" is not valid. Use YYYY-MM-DD, for example 2026-11-20.";
        }

        if ($parsedTime === null) {
            $errors[] = $time === '' ? 'Add a time.' : "Time \"{$time}\" is not valid. Use 24-hour HH:MM, for example 18:00.";
        }

        if ($parsedDate === null || $parsedTime === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $parsedDate->format('Y-m-d').' '.$parsedTime->format('H:i'), $timezone);
    }

    /**
     * @param  array<int, string>  $formats
     */
    private function parseWithFormats(string $value, array $formats): ?DateTime
    {
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat('!'.$format, $value);
            $problems = DateTime::getLastErrors();

            if ($parsed !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $errors
     * @return Collection<int, SocialAccount>
     */
    private function matchAccounts(string $value, array &$errors): Collection
    {
        $tokens = $this->splitList($value);

        if ($tokens === []) {
            $errors[] = 'Add at least one account.';

            return collect();
        }

        $matched = collect();

        foreach ($tokens as $token) {
            $found = $this->accountsFor($token);

            if ($found === null) {
                $errors[] = "No account called \"{$token}\" in this workspace.";

                continue;
            }

            $matched = $matched->merge($found);
        }

        return $matched->unique('id')->values();
    }

    /**
     * @return Collection<int, SocialAccount>|null
     */
    private function accountsFor(string $token): ?Collection
    {
        $provider = null;
        $lookup = $token;

        if (preg_match('/^([a-z]+)\s*:\s*(.+)$/i', $token, $parts) === 1 && SocialProvider::tryFrom(strtolower($parts[1])) !== null) {
            $provider = SocialProvider::from(strtolower($parts[1]));
            $lookup = trim($parts[2]);
        }

        $handleOnly = str_starts_with($lookup, '@');
        $needle = mb_strtolower(ltrim($lookup, '@'));

        $found = $this->accounts->filter(function (SocialAccount $account) use ($provider, $handleOnly, $needle): bool {
            if ($provider !== null && $account->provider !== $provider) {
                return false;
            }

            $handle = mb_strtolower(ltrim((string) $account->handle, '@'));

            return $handleOnly ? $handle === $needle : ($handle === $needle || mb_strtolower($account->name) === $needle);
        });

        return $found->isEmpty() ? null : $found->values();
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $errors
     * @return Collection<int, Media>
     */
    private function matchMedia(array $names, array &$errors): Collection
    {
        $media = collect();

        foreach ($names as $name) {
            $item = $this->mediaByName[mb_strtolower($name)] ?? null;

            if ($item === null) {
                $errors[] = "Media \"{$name}\" is not in the library. Upload it with the CSV or on the Media page.";

                continue;
            }

            $media->push($item);
        }

        return $media->unique('id')->values();
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<int, string>  $errors
     * @return array<string, array<string, string>>
     */
    private function parseSettings(array $cells, ?string $name, array &$errors): array
    {
        $settings = [];

        foreach (self::settingColumnRules() as $column => [$provider, $key, $allowed]) {
            $value = $cells[$column] ?? '';

            if ($value === '') {
                continue;
            }

            if ($allowed !== null && ! in_array(strtolower($value), $allowed, true)) {
                $errors[] = ucfirst(str_replace('_', ' ', $column))." \"{$value}\" is not known. Use ".implode(', ', $allowed).'.';

                continue;
            }

            $settings[$provider->value][$key] = $allowed === null ? $value : strtolower($value);
        }

        if (! isset($settings[SocialProvider::YouTube->value]['title']) && $name !== null) {
            $settings[SocialProvider::YouTube->value]['title'] = mb_substr($name, 0, YouTubePostRules::MAX_TITLE_LENGTH);
        }

        return $settings;
    }

    /**
     * @return array<int, string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[;|]/', $value) ?: []), fn (string $item): bool => $item !== ''));
    }
}
