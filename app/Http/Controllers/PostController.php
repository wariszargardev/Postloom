<?php

namespace App\Http\Controllers;

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Http\Requests\Posts\SavePostRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Posts\PostService;
use App\Services\Posts\Rules\PlatformRulesRegistry;
use App\Services\Publishing\PostDispatcher;
use App\Services\WorkspaceService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private PostService $postService,
        private WorkspaceService $workspaceService,
        private PlatformRulesRegistry $platformRulesRegistry,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $status = PostStatus::tryFrom((string) $request->query('status'));

        $posts = $workspace->posts()
            ->with(['targets.socialAccount', 'media'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Post $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'excerpt' => Str::limit($post->content, 140),
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'timezone' => $post->timezone,
                'media_count' => $post->media->count(),
                'thumbnail_url' => $this->thumbnailUrl($post->media->first()),
                'accounts' => $post->targets->map(fn (PostTarget $target): array => [
                    'id' => $target->social_account_id,
                    'name' => $target->socialAccount->name,
                    'provider' => $target->socialAccount->provider->value,
                    'avatar_url' => $target->socialAccount->avatar_url,
                ])->values(),
                'is_editable' => $post->status->isEditable(),
            ]);

        $counts = $workspace->posts()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('posts/Index', [
            'posts' => $posts,
            'filters' => ['status' => $status?->value],
            'statuses' => collect(PostStatus::cases())->map(fn (PostStatus $postStatus): array => [
                'value' => $postStatus->value,
                'label' => $postStatus->label(),
                'count' => (int) ($counts[$postStatus->value] ?? 0),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $prefillTime = (string) $request->query('scheduled_at');

        return Inertia::render('posts/Form', [
            ...$this->editorProps($workspace, null),
            'prefillScheduledAt' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $prefillTime) === 1 ? $prefillTime : null,
        ]);
    }

    public function store(SavePostRequest $request): RedirectResponse
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $post = $this->postService->save($workspace, $request->user(), $request->postData(), $request->wantsToSchedule());

        $this->afterSave($request, $post);

        return to_route('posts.edit', $post);
    }

    public function edit(Post $post): Response
    {
        Gate::authorize('view', $post);

        return Inertia::render('posts/Form', $this->editorProps($post->workspace, $post));
    }

    public function update(SavePostRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        $post = $this->postService->save($post->workspace, $request->user(), $request->postData(), $request->wantsToSchedule(), $post);

        $this->afterSave($request, $post);

        return to_route('posts.edit', $post);
    }

    public function retry(Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        $this->postService->retryFailed($post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => PostDispatcher::isEnabled()
                ? __('Sending the failed accounts again.')
                : __('Failed accounts are scheduled again. Publishing is turned off, so they will wait.'),
        ]);

        return back();
    }

    public function unschedule(Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        $this->postService->unschedule($post);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moved back to drafts.')]);

        return back();
    }

    public function duplicate(Post $post): RedirectResponse
    {
        Gate::authorize('view', $post);

        $copy = $this->postService->duplicate($post);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copy saved as a draft.')]);

        return to_route('posts.edit', $copy);
    }

    public function destroy(Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $this->postService->delete($post);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post deleted.')]);

        return to_route('posts.index');
    }

    private function afterSave(SavePostRequest $request, Post $post): void
    {
        if (! $request->wantsToPublishNow()) {
            $this->flashSaved($post);

            return;
        }

        $sent = $this->postService->publishNow($post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $sent
                ? __('Publishing now.')
                : __('Scheduled for the next minute. Publishing is turned off, so it will wait.'),
        ]);
    }

    private function flashSaved(Post $post): void
    {
        $message = $post->status === PostStatus::Scheduled
            ? __('Scheduled for :time.', ['time' => $post->scheduled_at?->setTimezone($post->timezone)->format('D j M Y, g:i A').' ('.$post->timezone.')'])
            : __('Draft saved.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorProps(Workspace $workspace, ?Post $post): array
    {
        $post?->load(['targets.publishAttempts' => fn ($query) => $query->latest('id')->limit(10), 'media']);

        $accounts = $workspace->socialAccounts()->orderBy('provider')->orderBy('name')->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'provider' => $account->provider->value,
                'provider_label' => $account->provider->label(),
                'name' => $account->name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status()->value,
                'rules' => $this->platformRulesRegistry->forProvider($account->provider)?->describe(),
            ]);

        $library = $workspace->media()->latest()->limit(200)->get()
            ->map(fn (Media $media): array => [
                'id' => $media->id,
                'kind' => $media->kind->value,
                'original_name' => $media->original_name,
                'url' => route('media.show', $media),
                'thumbnail_url' => $this->thumbnailUrl($media),
                'duration_seconds' => $media->duration_seconds === null ? null : (float) $media->duration_seconds,
            ]);

        return [
            'post' => $post === null ? null : [
                'id' => $post->id,
                'title' => $post->title,
                'content' => $post->content,
                'timezone' => $post->timezone,
                'scheduled_at' => $post->scheduled_at?->setTimezone($post->timezone)->format('Y-m-d\TH:i'),
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'is_editable' => $post->status->isEditable(),
                'can_retry' => in_array($post->status, [PostStatus::Failed, PostStatus::PartlyPublished], true),
                'targets' => $post->targets->map(fn (PostTarget $target): array => [
                    'social_account_id' => $target->social_account_id,
                    'content_override' => $target->content_override,
                    'settings' => $target->settings ?? (object) [],
                    'status' => $target->status->value,
                    'status_label' => $target->status->label(),
                    'attempts' => $target->attempts,
                    'last_error' => $target->last_error,
                    'external_url' => $target->external_url,
                    'published_at' => $target->published_at?->toIso8601String(),
                    'history' => $target->publishAttempts->map(fn (PublishAttempt $attempt): array => [
                        'attempt' => $attempt->attempt,
                        'status' => $attempt->status->value,
                        'error_message' => $attempt->error_message,
                        'started_at' => $attempt->started_at->toIso8601String(),
                        'duration_ms' => $attempt->duration_ms,
                    ])->values(),
                ])->values(),
                'media_ids' => $post->media->pluck('id')->values(),
            ],
            'accounts' => $accounts,
            'library' => $library,
            'upload' => [
                'accept' => collect(MediaKind::allExtensions())->map(fn (string $extension): string => ".{$extension}")->implode(','),
                'max_files' => (int) config('postloom.media.max_files_per_upload'),
            ],
            'defaultTimezone' => config('postloom.timezone'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'publishingEnabled' => PostDispatcher::isEnabled(),
        ];
    }

    private function thumbnailUrl(?Media $media): ?string
    {
        if ($media === null) {
            return null;
        }

        return $media->kind === MediaKind::Image || $media->thumbnail_path !== null ? route('media.thumbnail', $media) : null;
    }
}
