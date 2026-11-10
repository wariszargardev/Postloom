<?php

namespace App\Http\Controllers;

use App\Http\Requests\Workspaces\WorkspaceRequest;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __construct(private WorkspaceService $workspaceService) {}

    public function index(Request $request): Response
    {
        $workspaces = $request->user()->workspaces()
            ->withCount('socialAccounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'accounts_count' => $workspace->social_accounts_count,
                'is_current' => $workspace->id === $request->user()->current_workspace_id,
                'delete_block_reason' => $this->workspaceService->deletionBlockReason($workspace),
            ]);

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
        ]);
    }

    public function store(WorkspaceRequest $request): RedirectResponse
    {
        $workspace = $this->workspaceService->create($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace ":name" created.', ['name' => $workspace->name])]);

        return to_route('workspaces.index');
    }

    public function update(WorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $this->workspaceService->rename($workspace, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace renamed.')]);

        return to_route('workspaces.index');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('switch', $workspace);

        $this->workspaceService->switchTo($request->user(), $workspace);

        return back();
    }

    public function destroy(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('delete', $workspace);

        $blockReason = $this->workspaceService->deletionBlockReason($workspace);

        if ($blockReason !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $blockReason]);

            return to_route('workspaces.index');
        }

        $this->workspaceService->delete($workspace);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace deleted.')]);

        return to_route('workspaces.index');
    }
}
