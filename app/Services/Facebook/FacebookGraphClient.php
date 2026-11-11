<?php

namespace App\Services\Facebook;

use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FacebookGraphClient
{
    public const INVALID_TOKEN_CODE = 190;

    /**
     * Graph error codes that mean "slow down"; a later retry can work.
     *
     * @var array<int, int>
     */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80001];

    /**
     * Graph error codes about missing permissions; a retry can't fix them.
     *
     * @var array<int, int>
     */
    public const PERMISSION_CODES = [10, 200];

    public const BLOCKED_CODE = 368;

    public function url(string $path): string
    {
        return rtrim((string) config('services.facebook.graph_base_url'), '/')
            .'/'.config('services.facebook.graph_version')
            .'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws FacebookApiException
     */
    public function get(string $path, array $query, string $accessToken, string $action): Response
    {
        return $this->send($action, fn (): Response => $this->request()->get($this->url($path), [...$query, 'access_token' => $accessToken]));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws FacebookApiException
     */
    public function post(string $path, array $data, string $accessToken, string $action): Response
    {
        return $this->send($action, fn (): Response => $this->request()->asForm()->post($this->url($path), [...$data, 'access_token' => $accessToken]));
    }

    /**
     * Swap a short-lived user token for a long-lived one (about 60 days).
     *
     * @throws FacebookApiException
     */
    public function longLivedUserToken(string $shortLivedToken): string
    {
        $response = $this->send('get a long-lived login', fn (): Response => $this->request()->get($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]));

        return (string) $response->json('access_token');
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(60)->connectTimeout(10);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws FacebookApiException
     */
    private function send(string $action, callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            LogService::warning('Facebook could not be reached', ['action' => $action], $exception);

            throw new FacebookApiException("Facebook could not be reached while trying to {$action}.", true, previous: $exception);
        }

        if ($response->successful() && $response->json('error') === null) {
            return $response;
        }

        throw $this->errorFor($response, $action);
    }

    private function errorFor(Response $response, string $action): FacebookApiException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $code = $code === null ? null : (int) $code;
        $subcode = $response->json('error.error_subcode');
        $subcode = $subcode === null ? null : (int) $subcode;
        $facebookMessage = (string) ($response->json('error.error_user_msg') ?? $response->json('error.message') ?? '');
        $detail = $facebookMessage !== '' ? ": {$facebookMessage}" : '.';
        $codeText = $code === null ? (string) $status : "{$status}, code {$code}";

        LogService::warning('Facebook returned an error', [
            'action' => $action,
            'status' => $status,
            'code' => $code,
            'subcode' => $subcode,
            'facebook_message' => mb_substr($facebookMessage, 0, 500),
        ]);

        return match (true) {
            $code === self::INVALID_TOKEN_CODE => new FacebookApiException("Facebook no longer accepts this login ({$codeText}). Reconnect the Page on the Accounts page, then retry.", false, $status, $code, $subcode),
            in_array($code, self::RATE_LIMIT_CODES, true) => new FacebookApiException("Facebook is rate limiting requests ({$codeText}). It will be tried again later.", true, $status, $code, $subcode),
            in_array($code, self::PERMISSION_CODES, true) => new FacebookApiException("Facebook refused to {$action} ({$codeText}){$detail} Check that the app has pages_manage_posts and reconnect the Page.", false, $status, $code, $subcode),
            $code === self::BLOCKED_CODE => new FacebookApiException("Facebook blocked this action as possibly abusive ({$codeText}){$detail} Wait before posting again.", false, $status, $code, $subcode),
            $status >= 500 => new FacebookApiException("Facebook had a problem while trying to {$action} ({$codeText}){$detail}", true, $status, $code, $subcode),
            default => new FacebookApiException("Facebook rejected the request to {$action} ({$codeText}){$detail}", false, $status, $code, $subcode),
        };
    }
}
