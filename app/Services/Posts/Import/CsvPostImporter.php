<?php

namespace App\Services\Posts\Import;

use App\Enums\LogFeature;
use App\Enums\PostStatus;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\Posts\PostService;
use App\Services\Posts\PostValidator;
use App\Services\Posts\Rules\PlatformRulesRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use SplFileObject;
use SplTempFileObject;

class CsvPostImporter
{
    public const MAX_ROWS = 200;

    public const REQUIRED_COLUMNS = ['date', 'time', 'accounts', 'caption'];

    public const OPTIONAL_COLUMNS = ['timezone', 'status', 'name', 'media'];

    public function __construct(
        private PostService $postService,
        private PostValidator $postValidator,
        private PlatformRulesRegistry $platformRulesRegistry,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return ['date', 'time', 'timezone', 'status', 'accounts', 'name', 'caption', 'media', ...CsvRowParser::settingColumns()];
    }

    /**
     * Read and check every row without saving anything.
     *
     * @param  array<string, int>  $uploadedMedia  lowercased uploaded file name => media id
     * @return array<int, ImportRow>
     */
    public function analyse(Workspace $workspace, string $csv, array $uploadedMedia): array
    {
        $records = $this->readRecords($csv);
        $parser = new CsvRowParser(
            $workspace->socialAccounts()->get(),
            $this->mediaByName($workspace, $uploadedMedia),
            (string) config('postloom.timezone'),
        );

        $seenSlots = [];
        $rows = [];

        foreach ($records as $rowNumber => $cells) {
            $row = $parser->parse($rowNumber, $cells);

            if ($row->schedule && $row->isValid()) {
                $row->errors = array_values($this->postValidator->scheduleErrors($this->unsavedPost($row)));
            }

            if ($row->schedule && $row->isValid()) {
                $row->errors = $this->duplicateErrors($workspace, $row, $seenSlots);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $uploadedMedia
     * @return array<int, ImportRow>
     */
    public function preview(Workspace $workspace, string $csv, array $uploadedMedia): array
    {
        $rows = $this->analyse($workspace, $csv, $uploadedMedia);
        $invalid = array_filter($rows, fn (ImportRow $row): bool => ! $row->isValid());

        LogService::info('CSV import preview ready', [
            'rows' => count($rows),
            'valid' => count($rows) - count($invalid),
            'invalid' => count($invalid),
            'invalid_rows' => array_values(array_map(fn (ImportRow $row): int => $row->rowNumber, $invalid)),
        ]);

        return $rows;
    }

    /**
     * Save every valid row as a post. Rows with errors are skipped.
     *
     * @param  array<string, int>  $uploadedMedia
     * @return array{created: int, scheduled: int, drafts: int, skipped: int}
     */
    public function import(Workspace $workspace, User $user, string $csv, array $uploadedMedia, string $importId): array
    {
        $context = ['workspace_id' => $workspace->id, 'import_id' => $importId];
        $traceId = LogService::startFeatureLogging(LogFeature::Import, $context);

        $rows = $this->analyse($workspace, $csv, $uploadedMedia);
        LogService::info('CSV import started', ['rows' => count($rows)]);

        $summary = ['created' => 0, 'scheduled' => 0, 'drafts' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            if (! $row->isValid()) {
                $summary['skipped']++;
                LogService::info('CSV import row skipped', ['row' => $row->rowNumber, 'reasons' => $row->errors]);

                continue;
            }

            try {
                $post = $this->postService->save($workspace, $user, $row->postData, $row->schedule);
            } catch (ValidationException $exception) {
                LogService::startFeatureLogging(LogFeature::Import, $context, $traceId);
                $summary['skipped']++;
                LogService::warning('CSV import row skipped', ['row' => $row->rowNumber, 'reasons' => array_values(Arr::flatten($exception->errors()))]);

                continue;
            }

            LogService::startFeatureLogging(LogFeature::Import, $context, $traceId);
            $summary['created']++;
            $summary[$post->status === PostStatus::Scheduled ? 'scheduled' : 'drafts']++;

            LogService::info('CSV import row saved', ['row' => $row->rowNumber, 'post_id' => $post->id, 'status' => $post->status->value]);
        }

        LogService::info('CSV import finished', $summary);

        return $summary;
    }

    public function templateCsv(Workspace $workspace): string
    {
        $accounts = $workspace->socialAccounts()->orderBy('name')->get();
        $label = function (SocialProvider $provider, string $fallback) use ($accounts): string {
            $account = $accounts->first(fn (SocialAccount $item): bool => $item->provider === $provider);

            if ($account === null) {
                return "{$provider->value}:{$fallback}";
            }

            return $provider === SocialProvider::TikTok && filled($account->handle)
                ? "{$provider->value}:@".ltrim((string) $account->handle, '@')
                : "{$provider->value}:{$account->name}";
        };

        $timezone = (string) config('postloom.timezone');
        $tomorrow = now($timezone)->addDay();

        $rows = [
            self::columns(),
            [
                $tomorrow->format('Y-m-d'), '18:00', $timezone, 'schedule',
                implode(';', [
                    $label(SocialProvider::YouTube, 'Your channel'),
                    $label(SocialProvider::Facebook, 'Your Page'),
                    $label(SocialProvider::TikTok, '@yourhandle'),
                ]),
                'Day 1 video', "Caption for day 1.\nSame text goes to every account. #shorts", 'day-01.mp4',
                'Day 1 video', 'public', 'reel', 'manual',
            ],
            [
                $tomorrow->copy()->addDay()->format('Y-m-d'), '', '', 'draft',
                $label(SocialProvider::LinkedIn, 'Your name'),
                'Idea for later', 'A draft with no time yet.', '',
                '', '', '', '',
            ],
        ];

        $file = new SplTempFileObject;
        $file->fwrite("\u{FEFF}");

        foreach ($rows as $row) {
            $file->fputcsv($row, ',', '"', '');
        }

        $size = max(1, (int) $file->ftell());
        $file->rewind();
        $contents = $file->fread($size);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<int, array<string, string>> row number => column => value
     */
    private function readRecords(string $csv): array
    {
        $csv = mb_check_encoding($csv, 'UTF-8') ? $csv : mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $firstLine = (string) strtok($csv, "\r\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $file = new SplTempFileObject;
        $file->fwrite($csv);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV);
        $file->setCsvControl($delimiter, '"', '');

        $header = null;
        $records = [];

        foreach ($file as $index => $values) {
            if (! is_array($values) || $this->isBlank($values)) {
                continue;
            }

            if ($header === null) {
                $header = $this->readHeader($values);

                continue;
            }

            if (count($records) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['csv' => 'The CSV has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']);
            }

            $cells = [];

            foreach ($header as $position => $column) {
                $cells[$column] = trim(str_replace("\r\n", "\n", (string) ($values[$position] ?? '')));
            }

            $records[(int) $index + 1] = $cells;
        }

        if ($header === null) {
            throw ValidationException::withMessages(['csv' => 'The CSV is empty. Download the template to see the columns.']);
        }

        if ($records === []) {
            throw ValidationException::withMessages(['csv' => 'The CSV has no rows under the header.']);
        }

        return $records;
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function readHeader(array $values): array
    {
        $header = array_map(
            fn (?string $value): string => (string) preg_replace('/[\s-]+/', '_', strtolower(trim((string) $value))),
            $values,
        );

        $unknown = array_values(array_diff(array_filter($header), self::columns()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'csv' => 'Unknown column(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::columns()).'.',
            ]);
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missing !== []) {
            throw ValidationException::withMessages(['csv' => 'Missing column(s): '.implode(', ', $missing).'.']);
        }

        return $header;
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function isBlank(array $values): bool
    {
        return collect($values)->every(fn (?string $value): bool => trim((string) $value) === '');
    }

    /**
     * @param  array<string, int>  $uploadedMedia
     * @return array<string, Media>
     */
    private function mediaByName(Workspace $workspace, array $uploadedMedia): array
    {
        $byName = [];

        foreach ($workspace->media()->latest('id')->get() as $media) {
            $byName[mb_strtolower($media->original_name)] ??= $media;
        }

        $uploaded = $workspace->media()->whereIn('id', array_values($uploadedMedia))->get()->keyBy('id');

        foreach ($uploadedMedia as $name => $mediaId) {
            $media = $uploaded->get($mediaId);

            if ($media !== null) {
                $byName[mb_strtolower($name)] = $media;
            }
        }

        return $byName;
    }

    private function unsavedPost(ImportRow $row): Post
    {
        $post = new Post([
            'title' => $row->postData['title'],
            'content' => $row->postData['content'],
            'timezone' => $row->postData['timezone'],
            'scheduled_at' => $row->scheduledAtUtc,
        ]);

        $settingsByAccount = collect($row->postData['targets'])->keyBy('social_account_id');

        $targets = $row->accounts->map(function (SocialAccount $account) use ($settingsByAccount): PostTarget {
            $defaults = $this->platformRulesRegistry->forProvider($account->provider)?->defaultSettings() ?? [];
            $target = new PostTarget([
                'social_account_id' => $account->id,
                'content_override' => null,
                'settings' => [...$defaults, ...($settingsByAccount->get($account->id)['settings'] ?? [])],
            ]);

            return $target->setRelation('socialAccount', $account);
        });

        $post->setRelation('targets', new EloquentCollection($targets->all()));
        $post->setRelation('media', new EloquentCollection($row->media->all()));

        return $post;
    }

    /**
     * @param  array<string, int>  $seenSlots  "account id|UTC time" => row number, filled as rows are read
     * @return array<int, string>
     */
    private function duplicateErrors(Workspace $workspace, ImportRow $row, array &$seenSlots): array
    {
        $errors = [];
        $time = $row->scheduledAtUtc;

        if ($time === null) {
            return [];
        }

        $existing = PostTarget::query()
            ->whereIn('social_account_id', $row->accounts->pluck('id'))
            ->whereHas('post', fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->where('status', '!=', PostStatus::Draft)
                ->where('scheduled_at', $time))
            ->pluck('social_account_id')
            ->all();

        foreach ($row->accounts as $account) {
            $slot = "{$account->id}|{$time->toIso8601String()}";
            $where = "{$account->name} ({$account->provider->label()})";

            if (in_array($account->id, $existing, false)) {
                $errors[] = "{$where} already has a post at this time.";
            } elseif (isset($seenSlots[$slot])) {
                $errors[] = "{$where} is already used at this time in row {$seenSlots[$slot]}.";
            }
        }

        if ($errors === []) {
            foreach ($row->accounts as $account) {
                $seenSlots["{$account->id}|{$time->toIso8601String()}"] = $row->rowNumber;
            }
        }

        return $errors;
    }
}
