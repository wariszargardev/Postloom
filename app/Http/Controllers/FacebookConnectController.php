<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Exceptions\FacebookConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Facebook\FacebookConnectService;
use App\Services\LogService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class FacebookConnectController extends Controller
{
    public const SESSION_WORKSPACE_KEY = 'facebook_connect_workspace_id';

    public function __construct(
        private FacebookConnectService $facebookConnectService,
        private WorkspaceService $workspaceService,
    ) {}

    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! FacebookConnectService::isConfigured()) {
            return $this->backToAccounts('error', __('Add FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET to .env first.'));
        }

        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $request->session()->put(self::SESSION_WORKSPACE_KEY, $workspace->id);

        return $this->facebookConnectService->redirect($workspace);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectingWorkspace($request);

        if ($request->filled('error')) {
            LogService::startFeatureLogging(LogFeature::Facebook, ['workspace_id' => $workspace->id]);
            LogService::info('Facebook connection cancelled', ['error' => $request->string('error')->toString()]);

            return $this->backToAccounts('error', __('Facebook connection was cancelled.'));
        }

        try {
            $accounts = $this->facebookConnectService->completeConnection($workspace);
        } catch (FacebookConnectException $exception) {
            return $this->backToAccounts('error', $exception->getMessage());
        } catch (InvalidStateException $exception) {
            LogService::error('Facebook callback state did not match', [], $exception);

            return $this->backToAccounts('error', __('The Facebook login expired. Please connect again.'));
        } catch (Throwable $exception) {
            LogService::error('Facebook connection failed', [], $exception);

            return $this->backToAccounts('error', __('Could not connect Facebook. Check the logs and try again.'));
        }

        $names = $accounts->map(fn (SocialAccount $account): string => $account->name)->join(', ', ' and ');

        return $this->backToAccounts('success', trans_choice('{1} :names is connected.|[2,*] :names are connected.', $accounts->count(), ['names' => $names]));
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
