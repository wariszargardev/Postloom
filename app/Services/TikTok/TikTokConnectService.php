<?php

namespace App\Services\TikTok;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Exceptions\TikTokConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TikTokConnectService
{
    public const PUBLISH_SCOPE = 'video.publish';

    public const UPLOAD_SCOPE = 'video.upload';

    /**
     * @var array<int, string>
     */
    public const SCOPES = ['user.info.basic', 'user.info.profile', self::UPLOAD_SCOPE, self::PUBLISH_SCOPE];

    public const USER_FIELDS = 'open_id,union_id,avatar_url,display_name,username';

    public function __construct(private SocialAccountService $socialAccountService) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.tiktok.client_key'))
            && filled(config('services.tiktok.client_secret'));
    }

    public static function redirectUri(): string
    {
        return url((string) config('services.tiktok.redirect'));
    }

    public static function newState(): string
    {
        return Str::random(40);
    }

    public function authorizationUrl(Workspace $workspace, string $state): string
    {
        LogService::startFeatureLogging(LogFeature::TikTok, ['workspace_id' => $workspace->id]);
        LogService::info('Redirecting to TikTok to connect an account', ['scopes' => self::SCOPES, 'redirect_uri' => self::redirectUri()]);

        return config('services.tiktok.authorize_url').'?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * @throws TikTokConnectException
     */
    public function completeConnection(Workspace $workspace, string $code, ?string $returnedState, ?string $expectedState): SocialAccount
    {
        LogService::startFeatureLogging(LogFeature::TikTok, ['workspace_id' => $workspace->id]);
        LogService::info('TikTok callback received', []);

        if (blank($expectedState) || blank($returnedState) || ! hash_equals((string) $expectedState, (string) $returnedState)) {
            LogService::warning('TikTok callback state did not match', []);

            throw TikTokConnectException::stateMismatch();
        }

        $tokens = $this->exchangeCode($code);

        $grantedScopes = array_values(array_filter(preg_split('/[\s,]+/', (string) ($tokens['scope'] ?? '')) ?: []));

        if ($grantedScopes !== [] && ! in_array(self::PUBLISH_SCOPE, $grantedScopes, true) && ! in_array(self::UPLOAD_SCOPE, $grantedScopes, true)) {
            LogService::warning('TikTok did not grant a video permission', ['granted_scopes' => $grantedScopes]);

            throw TikTokConnectException::publishScopeMissing();
        }

        $user = $this->fetchUser((string) $tokens['access_token']);
        $openId = (string) ($user['open_id'] ?? $tokens['open_id'] ?? '');

        if ($openId === '') {
            LogService::warning('TikTok user info had no open_id', []);

            throw TikTokConnectException::profileLookupFailed();
        }

        $account = $this->socialAccountService->saveConnectedAccount($workspace, [
            'provider' => SocialProvider::TikTok,
            'account_type' => SocialAccountType::Profile,
            'provider_account_id' => $openId,
            'name' => (string) ($user['display_name'] ?? $user['username'] ?? 'TikTok account'),
            'handle' => filled($user['username'] ?? null) ? (string) $user['username'] : null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? TikTokTokenService::DEFAULT_LIFETIME_SECONDS)),
            'scopes' => $grantedScopes !== [] ? $grantedScopes : self::SCOPES,
        ]);

        LogService::info('TikTok account connected', [
            'account_id' => $account->id,
            'granted_scopes' => $account->scopes,
        ]);

        return $account;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in?: int, open_id?: string, scope?: string}
     *
     * @throws TikTokConnectException
     */
    private function exchangeCode(string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(30)->post(config('services.tiktok.api_base_url').'/oauth/token/', [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
        ]);

        if ($response->failed() || blank($response->json('access_token')) || blank($response->json('refresh_token'))) {
            $reason = (string) ($response->json('error') ?: 'status '.$response->status());

            LogService::warning('TikTok code exchange failed', [
                'status' => $response->status(),
                'error' => $response->json('error'),
                'error_description' => mb_substr((string) $response->json('error_description'), 0, 300),
                'log_id' => $response->json('log_id'),
            ]);

            throw TikTokConnectException::tokenExchangeFailed($reason);
        }

        /** @var array{access_token: string, refresh_token: string, expires_in?: int, open_id?: string, scope?: string} $tokens */
        $tokens = $response->json();

        return $tokens;
    }

    /**
     * @return array{open_id?: string, display_name?: string, username?: string, avatar_url?: string}
     *
     * @throws TikTokConnectException
     */
    private function fetchUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get(config('services.tiktok.api_base_url').'/user/info/', ['fields' => self::USER_FIELDS]);

        $errorCode = (string) $response->json('error.code', '');

        if ($response->failed() || ($errorCode !== '' && $errorCode !== 'ok')) {
            LogService::warning('TikTok user info lookup failed', [
                'status' => $response->status(),
                'error_code' => $errorCode,
                'message' => mb_substr((string) $response->json('error.message'), 0, 300),
                'log_id' => $response->json('error.log_id'),
            ]);

            throw TikTokConnectException::profileLookupFailed();
        }

        /** @var array{open_id?: string, display_name?: string, username?: string, avatar_url?: string} $user */
        $user = $response->json('data.user') ?? [];

        return $user;
    }
}
