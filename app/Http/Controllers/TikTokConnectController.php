<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Exceptions\TikTokConnectException;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\TikTok\TikTokConnectService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

class TikTokConnectController extends Controller
{
    public const SESSION_WORKSPACE_KEY = 'tiktok_connect_workspace_id';

    public const SESSION_STATE_KEY = 'tiktok_connect_state';

    public function __construct(
        private TikTokConnectService $tikTokConnectService,
        private WorkspaceService $workspaceService,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! TikTokConnectService::isConfigured()) {
            return $this->backToAccounts('error', __('Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env first.'));
        }

        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $state = TikTokConnectService::newState();

        $request->session()->put(self::SESSION_WORKSPACE_KEY, $workspace->id);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        return redirect()->away($this->tikTokConnectService->authorizationUrl($workspace, $state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectingWorkspace($request);
        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);

        if ($request->filled('error') || ! $request->filled('code')) {
            LogService::startFeatureLogging(LogFeature::TikTok, ['workspace_id' => $workspace->id]);
            LogService::info('TikTok connection cancelled', [
                'error' => $request->string('error')->toString(),
                'error_description' => mb_substr($request->string('error_description')->toString(), 0, 300),
            ]);

            return $this->backToAccounts('error', __('TikTok connection was cancelled.'));
        }

        try {
            $account = $this->tikTokConnectService->completeConnection(
                $workspace,
                $request->string('code')->toString(),
                $request->string('state')->toString(),
                is_string($expectedState) ? $expectedState : null,
            );
        } catch (TikTokConnectException $exception) {
            return $this->backToAccounts('error', $exception->getMessage());
        } catch (Throwable $exception) {
            LogService::error('TikTok connection failed', [], $exception);

            return $this->backToAccounts('error', __('Could not connect TikTok. Check the logs and try again.'));
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
