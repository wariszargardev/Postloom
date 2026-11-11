<?php

namespace App\Http\Controllers;

use App\Http\Requests\Posts\ReschedulePostRequest;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\PostService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * FullCalendar runs in "UTC" mode and gets wall-clock times in this format, so it shows
     * the calendar timezone's time without needing a timezone plugin.
     */
    private const WALL_TIME_FORMAT = 'Y-m-d\TH:i:s';

    public function __construct(
        private PostService $postService,
        private WorkspaceService $workspaceService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('calendar/Index', [
            'timezone' => config('postloom.timezone'),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $timezone = config('postloom.timezone');
        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $rangeStart = $this->wallTimeToUtc($validated['start'], $timezone);
        $rangeEnd = $this->wallTimeToUtc($validated['end'], $timezone);

        $posts = $workspace->posts()
            ->with('targets.socialAccount')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$rangeStart, $rangeEnd])
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($posts->map(fn (Post $post): array => [
            'id' => (string) $post->id,
            'title' => $post->title ?: Str::limit(Str::squish($post->content), 60) ?: '(no text)',
            'start' => $post->scheduled_at?->setTimezone($timezone)->format(self::WALL_TIME_FORMAT),
            'editable' => $post->status->isMovable(),
            'classNames' => ['postloom-event', 'postloom-event--'.$post->status->calendarColor()],
            'extendedProps' => [
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'edit_url' => route('posts.edit', $post),
                'accounts' => $post->targets->map(fn (PostTarget $target): array => [
                    'name' => $target->socialAccount->name,
                    'provider' => $target->socialAccount->provider->value,
                    'avatar_url' => $target->socialAccount->avatar_url,
                ])->values(),
            ],
        ])->values());
    }

    public function reschedule(ReschedulePostRequest $request, Post $post): RedirectResponse
    {
        $post = $this->postService->reschedule($post, $request->newTime());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Moved to :time.', ['time' => $post->scheduled_at?->setTimezone(config('postloom.timezone'))->format('D j M, g:i A')]),
        ]);

        return back();
    }

    /**
     * FullCalendar sends range boundaries as wall-clock times marked UTC ("2026-11-01T00:00:00Z").
     */
    private function wallTimeToUtc(string $wallTime, string $timezone): Carbon
    {
        $wall = Carbon::parse($wallTime)->format('Y-m-d H:i:s');

        return Carbon::createFromFormat('Y-m-d H:i:s', $wall, $timezone)->utc();
    }
}
