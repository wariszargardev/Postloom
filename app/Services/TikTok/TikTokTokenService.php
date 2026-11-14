<?php

namespace App\Services\TikTok;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TikTokTokenService
{
    public const REFRESH_MARGIN_SECONDS = 300;

    public const REFRESH_LOCK_SECONDS = 60;

    public const REFRESH_LOCK_WAIT_SECONDS = 45;

    public const DEFAULT_LIFETIME_SECONDS = 7200;

    /**
     * Errors that mean the refresh token itself is dead, so only a new login helps.
     *
     * @var array<int, string>
     */
    public const RECONNECT_ERRORS = ['invalid_grant', 'access_token_invalid', 'scope_not_authorized'];

    /**
     * An access token that is valid for at least 5 more minutes, refreshing it first when needed.
     *
     * @throws TikTokTokenException
     */
    public function accessTokenFor(SocialAccount $account): string
    {
        if ($this->isFresh($account)) {
            return $account->access_token;
        }

        return $this->refresh($account);
    }

    /**
     * Swap the refresh token for a new access token. TikTok returns a new refresh token every time, and the old one stops working, so both are saved.
     * Only one process refreshes an account at a time; if another one already did while this one waited, its new token is used instead.
     *
     * @throws TikTokTokenException
     */
    public function refresh(SocialAccount $account): string
    {
        $knownRefreshToken = $account->refresh_token;

        try {
            return Cache::lock("tiktok-token-refresh:{$account->id}", self::REFRESH_LOCK_SECONDS)->block(self::REFRESH_LOCK_WAIT_SECONDS, function () use ($account, $knownRefreshToken): string {
                $account->refresh();

                if ($account->refresh_token !== $knownRefreshToken && $this->isFresh($account)) {
                    LogService::info('TikTok token already refreshed by another process', ['account_id' => $account->id]);

                    return $account->access_token;
                }

                return $this->requestNewToken($account);
            });
        } catch (LockTimeoutException $exception) {
            LogService::warning('TikTok token refresh is busy in another process', ['account_id' => $account->id], $exception);

            throw new TikTokTokenException('Another process is refreshing the TikTok login. It will be tried again.', true, $exception);
        }
    }

    private function isFresh(SocialAccount $account): bool
    {
        return $account->token_expires_at !== null && $account->token_expires_at->isAfter(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    /**
     * @throws TikTokTokenException
     */
    private function requestNewToken(SocialAccount $account): string
    {
        if (! $account->canRefreshToken()) {
            return $this->reconnectNeeded($account, 'no_refresh_token');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post(config('services.tiktok.api_base_url').'/oauth/token/', [
                'client_key' => config('services.tiktok.client_key'),
                'client_secret' => config('services.tiktok.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
        } catch (ConnectionException $exception) {
            LogService::warning('TikTok could not be reached to refresh a token', ['account_id' => $account->id], $exception);

            throw new TikTokTokenException('TikTok could not be reached to refresh the login.', true, $exception);
        }

        if ($response->successful() && filled($response->json('access_token'))) {
            $account->update(array_filter([
                'access_token' => (string) $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? self::DEFAULT_LIFETIME_SECONDS)),
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            LogService::info('TikTok token refreshed', [
                'account_id' => $account->id,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                'refresh_expires_in' => $response->json('refresh_expires_in'),
            ]);

            return $account->access_token;
        }

        $error = (string) ($response->json('error') ?? '');

        if (in_array($error, self::RECONNECT_ERRORS, true)) {
            return $this->reconnectNeeded($account, $error);
        }

        LogService::warning('TikTok refused to refresh a token', [
            'account_id' => $account->id,
            'status' => $response->status(),
            'error' => $error,
            'log_id' => $response->json('log_id'),
        ]);

        throw new TikTokTokenException(
            "TikTok refused to refresh the login ({$response->status()}".($error !== '' ? ", {$error}" : '').'). Check TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET.',
            $response->serverError() || $response->status() === 429,
        );
    }

    /**
     * @throws TikTokTokenException
     */
    private function reconnectNeeded(SocialAccount $account, string $reason): never
    {
        $account->update(['needs_reconnect' => true]);

        LogService::warning('TikTok access was revoked or expired, marked for reconnect', [
            'account_id' => $account->id,
            'reason' => $reason,
        ]);

        throw new TikTokTokenException("TikTok no longer accepts the login for {$account->name}. Reconnect it on the Accounts page, then retry.", false);
    }
}
