<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public const UP_NEXT_LIMIT = 5;

    public function __construct(private WorkspaceService $workspaceService) {}

    public function __invoke(Request $request): Response
    {
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $now = now();

        $upNext = $workspace->posts()
            ->with('targets.socialAccount')
            ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing])
            ->where('scheduled_at', '>=', $now->subHour())
            ->orderBy('scheduled_at')
            ->limit(self::UP_NEXT_LIMIT)
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'title' => $post->title ?: Str::limit(Str::squish($post->content), 80) ?: '(no text)',
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'timezone' => $post->timezone,
                'providers' => $post->targets->map(fn (PostTarget $target): string => $target->socialAccount->provider->value)->unique()->values(),
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'scheduled_this_week' => $workspace->posts()
                    ->where('status', PostStatus::Scheduled)
                    ->whereBetween('scheduled_at', [$now, $now->addDays(7)])
                    ->count(),
                'published_last_30_days' => $workspace->posts()
                    ->whereIn('status', [PostStatus::Published, PostStatus::PartlyPublished])
                    ->where('scheduled_at', '>=', $now->subDays(30))
                    ->count(),
                'failed' => $workspace->posts()
                    ->whereIn('status', [PostStatus::Failed, PostStatus::PartlyPublished])
                    ->count(),
                'accounts_needing_attention' => SocialAccount::query()
                    ->where('workspace_id', $workspace->id)
                    ->needingAttention()
                    ->count(),
            ],
            'upNext' => $upNext,
        ]);
    }
}
