<?php

namespace App\Services\Facebook;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Exceptions\FacebookConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\SocialAccountService;
use Illuminate\Support\Collection;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\User as FacebookUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FacebookConnectService
{
    public const DRIVER = 'facebook';

    public const POST_SCOPE = 'pages_manage_posts';

    /**
     * @var array<int, string>
     */
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', self::POST_SCOPE];

    public const CREATE_CONTENT_TASK = 'CREATE_CONTENT';

    public function __construct(
        private SocialAccountService $socialAccountService,
        private FacebookGraphClient $graph,
    ) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.facebook.client_id'))
            && filled(config('services.facebook.client_secret'));
    }

    public function redirect(Workspace $workspace): RedirectResponse
    {
        LogService::startFeatureLogging(LogFeature::Facebook, ['workspace_id' => $workspace->id]);
        LogService::info('Redirecting to Facebook to connect Pages', ['scopes' => self::SCOPES]);

        return $this->provider()->setScopes(self::SCOPES)->redirect();
    }

    /**
     * Save every Page the owner shared (and can post to) as its own account.
     *
     * @return Collection<int, SocialAccount>
     */
    public function completeConnection(Workspace $workspace): Collection
    {
        LogService::startFeatureLogging(LogFeature::Facebook, ['workspace_id' => $workspace->id]);
        LogService::info('Facebook callback received', []);

        $facebookUser = $this->provider()->user();

        if (! $facebookUser instanceof FacebookUser) {
            throw new RuntimeException('Facebook returned an unexpected user type.');
        }

        $userToken = $this->graph->longLivedUserToken($facebookUser->token);

        $granted = $this->graph->get('me/permissions', [], $userToken, 'read the granted permissions')->collect('data')
            ->filter(fn (array $permission): bool => ($permission['status'] ?? '') === 'granted')
            ->pluck('permission')
            ->values()
            ->all();

        if (! in_array(self::POST_SCOPE, $granted, true)) {
            LogService::warning('Facebook did not grant the posting permission', ['granted' => $granted]);

            throw FacebookConnectException::postingPermissionMissing();
        }

        $pages = $this->graph->get('me/accounts', [
            'fields' => 'id,name,username,access_token,tasks,picture{url}',
            'limit' => 100,
        ], $userToken, 'list your Pages')->collect('data')
            ->filter(fn (array $page): bool => filled($page['access_token'] ?? null)
                && in_array(self::CREATE_CONTENT_TASK, $page['tasks'] ?? [], true))
            ->values();

        if ($pages->isEmpty()) {
            LogService::warning('Facebook shared no Page the owner can post to', []);

            throw FacebookConnectException::noPages();
        }

        $accounts = $pages->map(fn (array $page): SocialAccount => $this->socialAccountService->saveConnectedAccount($workspace, [
            'provider' => SocialProvider::Facebook,
            'account_type' => SocialAccountType::Page,
            'provider_account_id' => (string) $page['id'],
            'name' => (string) ($page['name'] ?? 'Facebook Page'),
            'handle' => $page['username'] ?? null,
            'avatar_url' => $page['picture']['data']['url'] ?? null,
            'access_token' => (string) $page['access_token'],
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => $granted,
        ]));

        LogService::info('Facebook Pages connected', [
            'account_ids' => $accounts->pluck('id')->all(),
            'page_ids' => $pages->pluck('id')->all(),
        ]);

        return $accounts;
    }

    /**
     * Ask Facebook whether a Page token still works; an invalid token marks the Page for reconnecting.
     * Returns false only when the Page needs reconnecting.
     */
    public function checkPageToken(SocialAccount $account): bool
    {
        try {
            $this->graph->get('me', ['fields' => 'id'], $account->access_token, 'check the Page login');
        } catch (FacebookApiException $exception) {
            if (! $exception->isInvalidToken()) {
                LogService::warning('Facebook Page token check failed, will try again tomorrow', ['account_id' => $account->id], $exception);

                return true;
            }

            $account->update(['needs_reconnect' => true]);
            LogService::warning('Facebook Page login was revoked, marked for reconnect', ['account_id' => $account->id, 'provider' => $account->provider->value, 'subcode' => $exception->errorSubcode]);

            return false;
        }

        return true;
    }

    private function provider(): FacebookProvider
    {
        $provider = Socialite::driver(self::DRIVER);

        if (! $provider instanceof FacebookProvider) {
            throw new RuntimeException('The Facebook Socialite driver is not the expected provider.');
        }

        return $provider->usingGraphVersion((string) config('services.facebook.graph_version'))->fields(['name']);
    }
}
