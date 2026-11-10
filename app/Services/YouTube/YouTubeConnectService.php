<?php

namespace App\Services\YouTube;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Exceptions\YouTubeConnectException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\LogService;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class YouTubeConnectService
{
    public const DRIVER = 'google';

    public const UPLOAD_SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    public const READ_SCOPE = 'https://www.googleapis.com/auth/youtube.readonly';

    /**
     * Added to Socialite's default Google scopes (openid, profile, email).
     *
     * @var array<int, string>
     */
    public const SCOPES = [self::UPLOAD_SCOPE, self::READ_SCOPE];

    public function __construct(private SocialAccountService $socialAccountService) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function redirect(Workspace $workspace): RedirectResponse
    {
        LogService::startFeatureLogging(LogFeature::YouTube, ['workspace_id' => $workspace->id]);
        LogService::info('Redirecting to Google to connect a YouTube channel', ['scopes' => self::SCOPES]);

        return $this->provider()
            ->scopes(self::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account'])
            ->redirect();
    }

    public function completeConnection(Workspace $workspace): SocialAccount
    {
        LogService::startFeatureLogging(LogFeature::YouTube, ['workspace_id' => $workspace->id]);
        LogService::info('Google callback received', []);

        $googleUser = $this->provider()->user();

        if (! $googleUser instanceof GoogleUser) {
            throw new RuntimeException('Google returned an unexpected user type.');
        }

        $approvedScopes = collect($googleUser->approvedScopes)
            ->flatMap(fn (string $scope): array => preg_split('/[\s,]+/', $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->unique()
            ->values()
            ->all();

        if ($approvedScopes !== [] && ! in_array(self::UPLOAD_SCOPE, $approvedScopes, true)) {
            LogService::warning('Google did not grant the YouTube upload permission', ['approved_scopes' => $approvedScopes]);

            throw YouTubeConnectException::uploadScopeMissing();
        }

        if (blank($googleUser->refreshToken)) {
            LogService::warning('Google did not return a refresh token', []);

            throw YouTubeConnectException::refreshTokenMissing();
        }

        $channel = $this->fetchChannel($googleUser->token);

        $account = $this->socialAccountService->saveConnectedAccount($workspace, [
            'provider' => SocialProvider::YouTube,
            'account_type' => SocialAccountType::Channel,
            'provider_account_id' => $channel['id'],
            'name' => $channel['title'],
            'handle' => $channel['handle'],
            'avatar_url' => $channel['avatar_url'] ?? $googleUser->getAvatar(),
            'access_token' => $googleUser->token,
            'refresh_token' => $googleUser->refreshToken,
            'token_expires_at' => now()->addSeconds($googleUser->expiresIn > 0 ? $googleUser->expiresIn : YouTubeTokenService::DEFAULT_LIFETIME_SECONDS),
            'scopes' => $approvedScopes !== [] ? $approvedScopes : self::SCOPES,
        ]);

        LogService::info('YouTube channel connected', [
            'account_id' => $account->id,
            'channel_id' => $channel['id'],
        ]);

        return $account;
    }

    /**
     * The channel the owner picked on the Google screen.
     *
     * @return array{id: string, title: string, handle: string|null, avatar_url: string|null}
     */
    private function fetchChannel(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get(config('services.google.youtube_api_base_url').'/channels', ['part' => 'snippet', 'mine' => 'true']);

        if ($response->failed()) {
            LogService::warning('YouTube channel lookup failed', [
                'status' => $response->status(),
                'reason' => $response->json('error.errors.0.reason'),
                'message' => mb_substr((string) $response->json('error.message'), 0, 300),
            ]);

            throw new RuntimeException("YouTube channel lookup failed ({$response->status()}).");
        }

        /** @var array{id?: string, snippet?: array{title?: string, customUrl?: string, thumbnails?: array{default?: array{url?: string}}}}|null $channel */
        $channel = $response->json('items.0');

        if ($channel === null || blank($channel['id'] ?? null)) {
            LogService::warning('Google account has no YouTube channel', []);

            throw YouTubeConnectException::noChannel();
        }

        return [
            'id' => (string) $channel['id'],
            'title' => (string) ($channel['snippet']['title'] ?? 'YouTube channel'),
            'handle' => isset($channel['snippet']['customUrl']) ? ltrim($channel['snippet']['customUrl'], '@') : null,
            'avatar_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
        ];
    }

    private function provider(): AbstractProvider
    {
        $provider = Socialite::driver(self::DRIVER);

        if (! $provider instanceof AbstractProvider) {
            throw new RuntimeException('The Google Socialite driver is not an OAuth2 provider.');
        }

        return $provider;
    }
}
