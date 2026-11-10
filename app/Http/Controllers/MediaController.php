<?php

namespace App\Http\Controllers;

use App\Enums\MediaKind;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(
        private MediaService $mediaService,
        private WorkspaceService $workspaceService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $kind = MediaKind::tryFrom((string) $request->query('kind'));

        $media = $workspace->media()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->latest()
            ->paginate(48)
            ->withQueryString()
            ->through(fn (Media $item): array => $this->present($item));

        $counts = $workspace->media()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return Inertia::render('media/Index', [
            'media' => $media,
            'filters' => ['kind' => $kind?->value],
            'kinds' => collect(MediaKind::cases())->map(fn (MediaKind $mediaKind): array => [
                'value' => $mediaKind->value,
                'label' => $mediaKind->label(),
                'count' => (int) ($counts[$mediaKind->value] ?? 0),
                'extensions' => $mediaKind->extensions(),
                'max_mb' => intdiv($mediaKind->maxKilobytes(), 1024),
            ]),
            'maxFilesPerUpload' => (int) config('postloom.media.max_files_per_upload'),
        ]);
    }

    public function store(StoreMediaRequest $request): RedirectResponse
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('files');
        $results = collect($files)->map(fn (UploadedFile $file): array => $this->mediaService->upload($workspace, $file));
        $duplicateCount = $results->where('duplicate', true)->count();
        $uploadedCount = $results->count() - $duplicateCount;

        $message = trans_choice('{1} 1 file uploaded.|[0,*] :count files uploaded.', $uploadedCount);

        if ($duplicateCount > 0) {
            $message .= ' '.trans_choice('{1} 1 was already in the library.|[2,*] :count were already in the library.', $duplicateCount);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function show(Media $media): StreamedResponse
    {
        Gate::authorize('view', $media);

        return Storage::disk($media->disk)->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function thumbnail(Media $media): StreamedResponse
    {
        Gate::authorize('view', $media);

        if ($media->thumbnail_path === null) {
            abort_unless($media->kind === MediaKind::Image, 404);

            return $this->show($media);
        }

        return Storage::disk($media->disk)->response($media->thumbnail_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function update(UpdateMediaRequest $request, Media $media): RedirectResponse
    {
        Gate::authorize('update', $media);

        $this->mediaService->updateAltText($media, $request->validated('alt_text'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alt text saved.')]);

        return back();
    }

    public function destroy(Media $media): RedirectResponse
    {
        Gate::authorize('delete', $media);

        $blockReason = $this->mediaService->deletionBlockReason($media);

        if ($blockReason !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $blockReason]);

            return back();
        }

        $this->mediaService->delete($media);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Media $media): array
    {
        $hasPreview = $media->kind === MediaKind::Image || $media->thumbnail_path !== null;

        return [
            'id' => $media->id,
            'kind' => $media->kind->value,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'duration_seconds' => $media->duration_seconds === null ? null : (float) $media->duration_seconds,
            'alt_text' => $media->alt_text,
            'url' => route('media.show', $media),
            'thumbnail_url' => $hasPreview ? route('media.thumbnail', $media) : null,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
