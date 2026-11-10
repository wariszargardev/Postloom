<?php

namespace App\Services\YouTube;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class YouTubeTokenService
{
    public const REFRESH_MARGIN_SECONDS = 300;

    public const DEFAULT_LIFETIME_SECONDS = 3600;

    /**
     * An access token that is valid for at least 5 more minutes, refreshing it first when needed.
     *
     * @throws YouTubeTokenException
     */
    public function accessTokenFor(SocialAccount $account): string
    {
        if ($account->token_expires_at !== null && $account->token_expires_at->isAfter(now()->addSeconds(self::REFRESH_MARGIN_SECONDS))) {
            return $account->access_token;
        }

        return $this->refresh($account);
    }

    /**
     * Swap the refresh token for a new access token. A revoked or expired refresh token marks the account for reconnecting.
     *
     * @throws YouTubeTokenException
     */
    public function refresh(SocialAccount $account): string
    {
        if (! $account->canRefreshToken()) {
            return $this->reconnectNeeded($account, 'no_refresh_token');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post((string) config('services.google.token_url'), [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $exception) {
            LogService::warning('Google could not be reached to refresh a YouTube token', ['account_id' => $account->id], $exception);

            throw new YouTubeTokenException('Google could not be reached to refresh the YouTube login.', true, $exception);
        }

        if ($response->successful()) {
            $expiresIn = (int) ($response->json('expires_in') ?? self::DEFAULT_LIFETIME_SECONDS);

            $account->update(array_filter([
                'access_token' => (string) $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'token_expires_at' => now()->addSeconds($expiresIn),
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            LogService::info('YouTube token refreshed', [
                'account_id' => $account->id,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ]);

            return $account->access_token;
        }

        $error = (string) ($response->json('error') ?? '');

        if ($error === 'invalid_grant') {
            return $this->reconnectNeeded($account, $error);
        }

        LogService::warning('Google refused to refresh a YouTube token', [
            'account_id' => $account->id,
            'status' => $response->status(),
            'error' => $error,
        ]);

        throw new YouTubeTokenException(
            "Google refused to refresh the YouTube login ({$response->status()}".($error !== '' ? ", {$error}" : '').'). Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.',
            $response->serverError(),
        );
    }

    /**
     * @throws YouTubeTokenException
     */
    private function reconnectNeeded(SocialAccount $account, string $reason): never
    {
        $account->update(['needs_reconnect' => true]);

        LogService::warning('YouTube access was revoked or expired, marked for reconnect', [
            'account_id' => $account->id,
            'reason' => $reason,
        ]);

        throw new YouTubeTokenException("Google no longer accepts the login for {$account->name}. Reconnect it on the Accounts page, then retry.", false);
    }
}
