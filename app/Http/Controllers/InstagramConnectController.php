<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Exceptions\InstagramConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Instagram\InstagramConnectService;
use App\Services\LogService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class InstagramConnectController extends Controller
{
    public const SESSION_WORKSPACE_KEY = 'instagram_connect_workspace_id';

    public function __construct(
        private InstagramConnectService $instagramConnectService,
        private WorkspaceService $workspaceService,
    ) {}

    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! InstagramConnectService::isConfigured()) {
            return $this->backToAccounts('error', __('Add FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET to .env first (Instagram uses the Meta app).'));
        }

        $workspace = $this->workspaceService->ensureCurrent($request->user());
        $request->session()->put(self::SESSION_WORKSPACE_KEY, $workspace->id);

        return $this->instagramConnectService->redirect($workspace);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectingWorkspace($request);

        if ($request->filled('error')) {
            LogService::startFeatureLogging(LogFeature::Instagram, ['workspace_id' => $workspace->id]);
            LogService::info('Instagram connection cancelled', ['error' => $request->string('error')->toString()]);

            return $this->backToAccounts('error', __('Instagram connection was cancelled.'));
        }

        try {
            $accounts = $this->instagramConnectService->completeConnection($workspace);
        } catch (InstagramConnectException $exception) {
            return $this->backToAccounts('error', $exception->getMessage());
        } catch (InvalidStateException $exception) {
            LogService::error('Instagram callback state did not match', [], $exception);

            return $this->backToAccounts('error', __('The Instagram login expired. Please connect again.'));
        } catch (Throwable $exception) {
            LogService::error('Instagram connection failed', [], $exception);

            return $this->backToAccounts('error', __('Could not connect Instagram. Check the logs and try again.'));
        }

        $names = $accounts->map(fn (SocialAccount $account): string => $account->handle !== null ? '@'.$account->handle : $account->name)->join(', ', ' and ');

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
