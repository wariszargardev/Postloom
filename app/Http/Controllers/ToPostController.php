<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Enums\PostTargetStatus;
use App\Http\Requests\Posts\MarkPostedRequest;
use App\Models\Media;
use App\Models\PostTarget;
use App\Services\LogService;
use App\Services\Publishing\ManualHandoffService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ToPostController extends Controller
{
    public function __construct(
        private ManualHandoffService $handoffs,
        private WorkspaceService $workspaceService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());

        $items = $this->handoffs->waiting($workspace)->map(function (PostTarget $target): array {
            $account = $target->socialAccount;
            $destination = $this->handoffs->destination($account->provider);

            return [
                'id' => $target->id,
                'post_id' => $target->post_id,
                'account' => [
                    'name' => $account->name,
                    'handle' => $account->handle,
                    'provider' => $account->provider->value,
                    'provider_label' => $account->provider->label(),
                    'avatar_url' => $account->avatar_url,
                ],
                'caption' => $target->contentFor($target->post),
                'scheduled_at' => $target->post->scheduled_at?->toIso8601String(),
                'timezone' => $target->post->timezone,
                'notice' => $target->last_error,
                'destination' => [
                    'label' => $destination['label'],
                    'url' => $destination['url'],
                    'link_example' => $destination['link_example'],
                ],
                'files' => $this->handoffs->files($target)->map(fn (Media $media): array => [
                    'id' => $media->id,
                    'kind' => $media->kind->value,
                    'name' => $media->original_name,
                    'size_bytes' => $media->size_bytes,
                    'thumbnail_url' => $media->thumbnail_path !== null || $media->kind === MediaKind::Image ? route('media.thumbnail', $media) : null,
                    'download_url' => route('to-post.media', [$target, $media]),
                ])->all(),
            ];
        })->values();

        return Inertia::render('to-post/Index', [
            'items' => $items,
        ]);
    }

    public function media(PostTarget $postTarget, Media $media): StreamedResponse
    {
        Gate::authorize('view', $postTarget->post);

        abort_unless($this->handoffs->files($postTarget)->contains(fn (Media $file): bool => $file->is($media)), 404);

        LogService::startFeatureLogging(LogFeature::Publishing, ['post_id' => $postTarget->post_id, 'target_id' => $postTarget->id]);
        LogService::info('Hand-off file downloaded', ['media_id' => $media->id, 'kind' => $media->kind->value]);

        return Storage::disk($media->disk)->download($media->path, $this->handoffs->downloadName($postTarget, $media), [
            'Content-Type' => $media->mime_type,
        ]);
    }

    public function posted(MarkPostedRequest $request, PostTarget $postTarget): RedirectResponse
    {
        abort_unless($postTarget->status === PostTargetStatus::ReadyToPost, 409, 'This post is not waiting for you.');

        $url = $request->validated('url');
        $this->handoffs->markPosted($postTarget, is_string($url) && $url !== '' ? $url : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as posted on :account.', ['account' => $postTarget->socialAccount->name])]);

        return back();
    }
}
