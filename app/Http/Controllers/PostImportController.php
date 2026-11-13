<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Http\Requests\Posts\PreviewCsvImportRequest;
use App\Services\LogService;
use App\Services\MediaService;
use App\Services\Posts\Import\CsvPostImporter;
use App\Services\Posts\Import\CsvRowParser;
use App\Services\Posts\Import\ImportRow;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PostImportController extends Controller
{
    private const SESSION_KEY = 'csv_imports';

    public function __construct(
        private CsvPostImporter $csvPostImporter,
        private MediaService $mediaService,
        private WorkspaceService $workspaceService,
    ) {}

    public function create(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $importId = (string) $request->query('import');
        $stored = $this->storedImport($request, $importId, $workspace->id);
        $preview = null;

        if ($stored !== null) {
            try {
                $rows = $this->csvPostImporter->analyse($workspace, $stored['csv'], $stored['media']);
                $preview = [
                    'import_id' => $importId,
                    'file_name' => $stored['file_name'],
                    'rows' => array_map(fn (ImportRow $row): array => $row->toPreview(), $rows),
                    'valid_count' => count(array_filter($rows, fn (ImportRow $row): bool => $row->isValid())),
                ];
            } catch (ValidationException) {
                $preview = null;
            }
        }

        return Inertia::render('posts/Import', [
            'preview' => $preview,
            'columns' => [
                'required' => CsvPostImporter::REQUIRED_COLUMNS,
                'optional' => [...CsvPostImporter::OPTIONAL_COLUMNS, ...CsvRowParser::settingColumns()],
            ],
            'maxRows' => CsvPostImporter::MAX_ROWS,
            'defaultTimezone' => config('postloom.timezone'),
            'upload' => [
                'accept' => collect(MediaKind::allExtensions())->map(fn (string $extension): string => ".{$extension}")->implode(','),
                'max_files' => (int) config('postloom.media.max_files_per_upload'),
            ],
        ]);
    }

    public function preview(PreviewCsvImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $importId = (string) Str::uuid();
        $context = ['workspace_id' => $workspace->id, 'import_id' => $importId];
        $traceId = LogService::startFeatureLogging(LogFeature::Import, $context);

        /** @var UploadedFile $csvFile */
        $csvFile = $request->file('csv');
        /** @var array<int, UploadedFile> $mediaFiles */
        $mediaFiles = $request->file('files', []);

        LogService::info('CSV import preview requested', ['size_bytes' => $csvFile->getSize(), 'media_files' => count($mediaFiles)]);

        $uploadedMedia = [];

        foreach ($mediaFiles as $file) {
            $uploadedMedia[mb_strtolower($file->getClientOriginalName())] = $this->mediaService->upload($workspace, $file)['media']->id;
        }

        LogService::startFeatureLogging(LogFeature::Import, $context, $traceId);

        $csv = (string) file_get_contents((string) $csvFile->getRealPath());
        $this->csvPostImporter->preview($workspace, $csv, $uploadedMedia);

        $request->session()->put(self::SESSION_KEY, [
            $importId => [
                'workspace_id' => $workspace->id,
                'file_name' => $csvFile->getClientOriginalName(),
                'csv' => $csv,
                'media' => $uploadedMedia,
            ],
        ]);

        return to_route('posts.import.create', ['import' => $importId]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $importId = (string) $request->input('import_id');
        $stored = $this->storedImport($request, $importId, $workspace->id);

        if ($stored === null) {
            LogService::startFeatureLogging(LogFeature::Import, ['workspace_id' => $workspace->id, 'import_id' => $importId]);
            LogService::warning('CSV import not found in the session', []);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('This import expired. Upload the CSV again.')]);

            return to_route('posts.import.create');
        }

        $summary = $this->csvPostImporter->import($workspace, $request->user(), $stored['csv'], $stored['media'], $importId);
        $request->session()->forget(self::SESSION_KEY);

        $message = __('Imported :created posts (:scheduled scheduled, :drafts drafts).', $summary);

        if ($summary['skipped'] > 0) {
            $message .= ' '.trans_choice(':count row skipped.|:count rows skipped.', $summary['skipped']);
        }

        Inertia::flash('toast', ['type' => $summary['created'] > 0 ? 'success' : 'error', 'message' => $message]);

        return to_route('posts.index');
    }

    public function template(Request $request): HttpResponse
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());

        return response($this->csvPostImporter->templateCsv($workspace), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="postloom-import-template.csv"',
        ]);
    }

    /**
     * @return array{workspace_id: int, file_name: string, csv: string, media: array<string, int>}|null
     */
    private function storedImport(Request $request, string $importId, int $workspaceId): ?array
    {
        if ($importId === '') {
            return null;
        }

        /** @var array{workspace_id: int, file_name: string, csv: string, media: array<string, int>}|null $stored */
        $stored = $request->session()->get(self::SESSION_KEY.'.'.$importId);

        return $stored !== null && (int) $stored['workspace_id'] === $workspaceId ? $stored : null;
    }
}
