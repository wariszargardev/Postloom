<?php

namespace App\Services\LinkedIn;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Exceptions\LinkedInScopeMissingException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\SocialAccountService;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as LinkedInUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LinkedInConnectService
{
    public const DRIVER = 'linkedin-openid';

    public const PUBLISH_SCOPE = 'w_member_social';

    /**
     * @var array<int, string>
     */
    public const SCOPES = ['openid', 'profile', 'email', self::PUBLISH_SCOPE];

    public function __construct(private SocialAccountService $socialAccountService) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.linkedin-openid.client_id'))
            && filled(config('services.linkedin-openid.client_secret'));
    }

    public function redirect(Workspace $workspace): RedirectResponse
    {
        LogService::startFeatureLogging(LogFeature::LinkedIn, ['workspace_id' => $workspace->id]);
        LogService::info('Redirecting to LinkedIn to connect a profile', ['scopes' => self::SCOPES]);

        return $this->provider()->scopes(self::SCOPES)->redirect();
    }

    public function completeConnection(Workspace $workspace): SocialAccount
    {
        LogService::startFeatureLogging(LogFeature::LinkedIn, ['workspace_id' => $workspace->id]);
        LogService::info('LinkedIn callback received', []);

        $linkedInUser = $this->provider()->user();

        if (! $linkedInUser instanceof LinkedInUser) {
            throw new RuntimeException('LinkedIn returned an unexpected user type.');
        }

        $approvedScopes = $linkedInUser->approvedScopes;

        if ($approvedScopes !== [] && ! in_array(self::PUBLISH_SCOPE, $approvedScopes, true)) {
            LogService::warning('LinkedIn did not grant the posting permission', ['approved_scopes' => $approvedScopes]);

            throw new LinkedInScopeMissingException;
        }

        $account = $this->socialAccountService->saveConnectedAccount($workspace, [
            'provider' => SocialProvider::LinkedIn,
            'account_type' => SocialAccountType::Profile,
            'provider_account_id' => (string) $linkedInUser->getId(),
            'name' => $linkedInUser->getName() ?? 'LinkedIn profile',
            'handle' => null,
            'avatar_url' => $linkedInUser->getAvatar(),
            'access_token' => $linkedInUser->token,
            'refresh_token' => $linkedInUser->refreshToken,
            'token_expires_at' => $linkedInUser->expiresIn !== null ? now()->addSeconds((int) $linkedInUser->expiresIn) : null,
            'scopes' => $approvedScopes !== [] ? $approvedScopes : self::SCOPES,
        ]);

        LogService::info('LinkedIn profile connected', [
            'account_id' => $account->id,
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
        ]);

        return $account;
    }

    private function provider(): AbstractProvider
    {
        $provider = Socialite::driver(self::DRIVER);

        if (! $provider instanceof AbstractProvider) {
            throw new RuntimeException('The LinkedIn Socialite driver is not an OAuth2 provider.');
        }

        return $provider;
    }
}
