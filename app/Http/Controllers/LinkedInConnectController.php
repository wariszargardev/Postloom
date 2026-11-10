<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Exceptions\LinkedInScopeMissingException;
use App\Models\Workspace;
use App\Services\LinkedIn\LinkedInConnectService;
use App\Services\LogService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class LinkedInConnectController extends Controller
{
    public const SESSION_WORKSPACE_KEY = 'linkedin_connect_workspace_id';

    public function __construct(
        private LinkedInConnectService $linkedInConnectService,
        private WorkspaceService $workspaceService,
    ) {}

    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! LinkedInConnectService::isConfigured()) {
            return $this->backToAccounts('error', __('Add LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET to .env first.'));
        }

        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $request->session()->put(self::SESSION_WORKSPACE_KEY, $workspace->id);

        return $this->linkedInConnectService->redirect($workspace);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectingWorkspace($request);

        if ($request->filled('error')) {
            LogService::startFeatureLogging(LogFeature::LinkedIn, ['workspace_id' => $workspace->id]);
            LogService::info('LinkedIn connection cancelled', ['error' => $request->string('error')->toString()]);

            return $this->backToAccounts('error', __('LinkedIn connection was cancelled.'));
        }

        try {
            $account = $this->linkedInConnectService->completeConnection($workspace);
        } catch (LinkedInScopeMissingException $exception) {
            return $this->backToAccounts('error', $exception->getMessage());
        } catch (InvalidStateException $exception) {
            LogService::error('LinkedIn callback state did not match', [], $exception);

            return $this->backToAccounts('error', __('The LinkedIn login expired. Please connect again.'));
        } catch (Throwable $exception) {
            LogService::error('LinkedIn connection failed', [], $exception);

            return $this->backToAccounts('error', __('Could not connect LinkedIn. Check the logs and try again.'));
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
