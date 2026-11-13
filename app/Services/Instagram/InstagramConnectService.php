<?php

namespace App\Services\Instagram;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Exceptions\InstagramConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Facebook\FacebookConnectService;
use App\Services\Facebook\FacebookGraphClient;
use App\Services\LogService;
use App\Services\SocialAccountService;
use Illuminate\Support\Collection;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\User as FacebookUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class InstagramConnectService
{
    public const PUBLISH_SCOPE = 'instagram_content_publish';

    /**
     * @var array<int, string>
     */
    public const SCOPES = ['instagram_basic', self::PUBLISH_SCOPE, 'pages_show_list', 'pages_read_engagement', 'business_management'];

    public function __construct(
        private SocialAccountService $socialAccountService,
        private FacebookGraphClient $graph,
    ) {}

    public static function isConfigured(): bool
    {
        return FacebookConnectService::isConfigured();
    }

    public function redirect(Workspace $workspace): RedirectResponse
    {
        LogService::startFeatureLogging(LogFeature::Instagram, ['workspace_id' => $workspace->id]);
        LogService::info('Redirecting to Facebook to connect Instagram accounts', ['scopes' => self::SCOPES]);

        return $this->provider()->setScopes(self::SCOPES)->redirect();
    }

    /**
     * Save every Instagram professional account linked to a shared Page as its own account.
     *
     * @return Collection<int, SocialAccount>
     */
    public function completeConnection(Workspace $workspace): Collection
    {
        LogService::startFeatureLogging(LogFeature::Instagram, ['workspace_id' => $workspace->id]);
        LogService::info('Instagram callback received', []);

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

        if (! in_array(self::PUBLISH_SCOPE, $granted, true)) {
            LogService::warning('Facebook did not grant the Instagram publishing permission', ['granted' => $granted]);

            throw InstagramConnectException::publishingPermissionMissing();
        }

        $sharedPages = $this->graph->get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
        ], $userToken, 'list your Pages and Instagram accounts')->collect('data');

        LogService::info('Facebook returned Pages for Instagram', [
            'page_count' => $sharedPages->count(),
            'pages' => $sharedPages->map(fn (array $page): array => [
                'page_id' => $page['id'] ?? null,
                'name' => $page['name'] ?? null,
                'instagram_id' => $page['instagram_business_account']['id'] ?? null,
            ])->values()->all(),
        ]);

        $pages = $sharedPages
            ->filter(fn (array $page): bool => filled($page['access_token'] ?? null)
                && filled($page['instagram_business_account']['id'] ?? null))
            ->unique(fn (array $page): string => (string) $page['instagram_business_account']['id'])
            ->values();

        if ($pages->isEmpty()) {
            LogService::warning('No Instagram professional account is linked to the shared Pages', []);

            throw InstagramConnectException::noAccounts();
        }

        $accounts = $pages->map(function (array $page) use ($workspace, $granted): SocialAccount {
            $instagram = $page['instagram_business_account'];
            $username = $instagram['username'] ?? null;

            return $this->socialAccountService->saveConnectedAccount($workspace, [
                'provider' => SocialProvider::Instagram,
                'account_type' => SocialAccountType::Profile,
                'provider_account_id' => (string) $instagram['id'],
                'name' => (string) ($instagram['name'] ?? $username ?? 'Instagram account'),
                'handle' => $username,
                'avatar_url' => $instagram['profile_picture_url'] ?? null,
                'access_token' => (string) $page['access_token'],
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => $granted,
            ]);
        });

        LogService::info('Instagram accounts connected', [
            'account_ids' => $accounts->pluck('id')->all(),
            'instagram_ids' => $pages->pluck('instagram_business_account.id')->all(),
            'page_ids' => $pages->pluck('id')->all(),
        ]);

        return $accounts;
    }

    private function provider(): FacebookProvider
    {
        $provider = Socialite::driver(FacebookConnectService::DRIVER);

        if (! $provider instanceof FacebookProvider) {
            throw new RuntimeException('The Facebook Socialite driver is not the expected provider.');
        }

        return $provider->usingGraphVersion((string) config('services.facebook.graph_version'))
            ->fields(['name'])
            ->redirectUrl(url((string) config('services.instagram.redirect')));
    }
}
