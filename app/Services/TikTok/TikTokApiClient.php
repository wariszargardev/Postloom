<?php

namespace App\Services\TikTok;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TikTokApiClient
{
    /**
     * Friendly messages for TikTok error codes that a retry can't fix.
     *
     * @var array<string, string>
     */
    public const PERMANENT_MESSAGES = [
        'unaudited_client_can_only_post_to_private_accounts' => 'TikTok only allows direct posts to a private account until the app is reviewed. Set the TikTok account to private, or use "Send to TikTok inbox".',
        'reached_active_user_cap' => 'TikTok\'s limit of accounts posting through this app in 24 hours is reached. Try again tomorrow.',
        'spam_risk_too_many_posts' => 'TikTok\'s daily post limit for this account is reached. Try again tomorrow.',
        'spam_risk_too_many_pending_share' => 'Too many videos are waiting in the TikTok inbox. Post or delete some in the TikTok app, then retry.',
        'spam_risk_user_banned_from_posting' => 'TikTok has blocked this account from posting. Check the TikTok app.',
        'privacy_level_option_mismatch' => 'TikTok doesn\'t allow the chosen "Who can see it" for this account. Pick another option in the TikTok settings.',
        'scope_not_authorized' => 'TikTok no longer allows posting for this account. Reconnect it on the Accounts page and allow every permission.',
        'access_token_invalid' => 'TikTok no longer accepts the login. Reconnect the account on the Accounts page, then retry.',
    ];

    public function __construct(private TikTokTokenService $tokens) {}

    /**
     * POST JSON to the TikTok API with the account's token. A rejected token is refreshed once and the call repeated.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> The `data` part of TikTok's answer.
     *
     * @throws TikTokApiException|TikTokTokenException
     */
    public function post(SocialAccount $account, string $path, array $body, string $action): array
    {
        $response = $this->send($account, $path, $body, $action, $this->tokens->accessTokenFor($account));

        if ($this->isTokenRejected($response)) {
            LogService::info('TikTok rejected the token, refreshing once', ['action' => $action]);
            $response = $this->send($account, $path, $body, $action, $this->tokens->refresh($account));
        }

        $errorCode = (string) $response->json('error.code', '');

        if ($response->successful() && ($errorCode === '' || $errorCode === 'ok')) {
            /** @var array<string, mixed> $data */
            $data = $response->json('data') ?? [];

            return $data;
        }

        throw $this->toException($account, $response, $action);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws TikTokApiException
     */
    private function send(SocialAccount $account, string $path, array $body, string $action, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
                ->timeout(60)
                ->post(rtrim((string) config('services.tiktok.api_base_url'), '/').'/'.ltrim($path, '/'), $body === [] ? (object) [] : $body);
        } catch (ConnectionException $exception) {
            LogService::warning('TikTok could not be reached', ['action' => $action, 'account_id' => $account->id], $exception);

            throw new TikTokApiException("TikTok could not be reached to {$action}.", true, 0, 'connection', null, $exception);
        }
    }

    private function isTokenRejected(Response $response): bool
    {
        return $response->status() === 401 || $response->json('error.code') === 'access_token_invalid';
    }

    private function toException(SocialAccount $account, Response $response, string $action): TikTokApiException
    {
        $status = $response->status();
        $errorCode = (string) $response->json('error.code', '');
        $tikTokMessage = mb_substr((string) $response->json('error.message', ''), 0, 300);
        $logId = $response->json('error.log_id');

        LogService::warning('TikTok returned an error', [
            'action' => $action,
            'status' => $status,
            'error_code' => $errorCode,
            'tiktok_message' => $tikTokMessage,
            'log_id' => $logId,
        ]);

        $isRetryable = $status === 429 || $status >= 500 || in_array($errorCode, ['rate_limit_exceeded', 'internal_error'], true);

        if (in_array($errorCode, ['access_token_invalid', 'scope_not_authorized'], true) || $status === 401) {
            $account->update(['needs_reconnect' => true]);
            $isRetryable = false;
        }

        $message = match (true) {
            isset(self::PERMANENT_MESSAGES[$errorCode]) => self::PERMANENT_MESSAGES[$errorCode],
            $isRetryable => "TikTok is busy or rate limited ({$status}".($errorCode !== '' ? ", {$errorCode}" : '')."), could not {$action}. Will retry.",
            default => "TikTok refused to {$action} ({$status}".($errorCode !== '' ? ", {$errorCode}" : '').')'.($tikTokMessage !== '' ? ": {$tikTokMessage}" : '.'),
        };

        return new TikTokApiException($message, $isRetryable, $status, $errorCode, is_string($logId) ? $logId : null);
    }
}
