<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Exceptions\YouTubeConnectException;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\WorkspaceService;
use App\Services\YouTube\YouTubeConnectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class YouTubeConnectController extends Controller
{
    public const SESSION_WORKSPACE_KEY = 'youtube_connect_workspace_id';

    public function __construct(
        private YouTubeConnectService $youTubeConnectService,
        private WorkspaceService $workspaceService,
    ) {}

    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! YouTubeConnectService::isConfigured()) {
            return $this->backToAccounts('error', __('Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env first.'));
        }

        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $request->session()->put(self::SESSION_WORKSPACE_KEY, $workspace->id);

        return $this->youTubeConnectService->redirect($workspace);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectingWorkspace($request);

        if ($request->filled('error')) {
            LogService::startFeatureLogging(LogFeature::YouTube, ['workspace_id' => $workspace->id]);
            LogService::info('YouTube connection cancelled', ['error' => $request->string('error')->toString()]);

            return $this->backToAccounts('error', __('YouTube connection was cancelled.'));
        }

        try {
            $account = $this->youTubeConnectService->completeConnection($workspace);
        } catch (YouTubeConnectException $exception) {
            return $this->backToAccounts('error', $exception->getMessage());
        } catch (InvalidStateException $exception) {
            LogService::error('Google callback state did not match', [], $exception);

            return $this->backToAccounts('error', __('The Google login expired. Please connect again.'));
        } catch (Throwable $exception) {
            LogService::error('YouTube connection failed', [], $exception);

            return $this->backToAccounts('error', __('Could not connect YouTube. Check the logs and try again.'));
        }

        return $this->backToAccounts('success', __(':name is connected.', ['name' => $account->name]));
    }

    private function connectingWorkspace(Request $request): Workspace
    {
        $user = $request->user();
        $workspaceId = $request->session()->pull(self::SESSION_WORKSPACE_KEY);

        return $user->workspaces()->whereKey($workspaceId)->first()
            ?? $this->workspaceService->ensureCurrent($user);
    }

    private function backToAccounts(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('accounts.index');
    }
}
