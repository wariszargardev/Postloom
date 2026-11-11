<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Enums\PostTargetStatus;
use App\Http\Requests\Posts\MarkPostedRequest;
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
    public const TIKTOK_UPLOAD_URL = 'https://www.tiktok.com/tiktokstudio/upload';

    public function __construct(
        private ManualHandoffService $handoffs,
        private WorkspaceService $workspaceService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());

        $items = $this->handoffs->waiting($workspace)->map(function (PostTarget $target): array {
            $video = $this->handoffs->video($target);

            return [
                'id' => $target->id,
                'post_id' => $target->post_id,
                'account' => [
                    'name' => $target->socialAccount->name,
                    'handle' => $target->socialAccount->handle,
                    'provider' => $target->socialAccount->provider->value,
                    'provider_label' => $target->socialAccount->provider->label(),
                    'avatar_url' => $target->socialAccount->avatar_url,
                ],
                'caption' => $target->contentFor($target->post),
                'scheduled_at' => $target->post->scheduled_at?->toIso8601String(),
                'timezone' => $target->post->timezone,
                'video' => $video === null ? null : [
                    'name' => $video->original_name,
                    'size_bytes' => $video->size_bytes,
                    'thumbnail_url' => $video->thumbnail_path === null ? null : route('media.thumbnail', $video),
                    'download_url' => route('to-post.video', $target),
                ],
            ];
        })->values();

        return Inertia::render('to-post/Index', [
            'items' => $items,
            'uploadUrl' => self::TIKTOK_UPLOAD_URL,
        ]);
    }

    public function video(PostTarget $postTarget): StreamedResponse
    {
        Gate::authorize('view', $postTarget->post);

        $video = $this->handoffs->video($postTarget);
        abort_if($video === null, 404);

        LogService::startFeatureLogging(LogFeature::Publishing, ['post_id' => $postTarget->post_id, 'target_id' => $postTarget->id]);
        LogService::info('Hand-off video downloaded', ['media_id' => $video->id]);

        return Storage::disk($video->disk)->download($video->path, $this->handoffs->downloadName($postTarget, $video), [
            'Content-Type' => $video->mime_type,
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
