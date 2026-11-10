<?php

namespace App\Services\LinkedIn;

use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LinkedInApiClient
{
    public const RESTLI_PROTOCOL_VERSION = '2.0.0';

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws LinkedInApiException
     */
    public function post(SocialAccount $account, string $path, array $body, string $action): Response
    {
        return $this->send($account, $action, fn (): Response => $this->request($account)->post($path, $body));
    }

    /**
     * @throws LinkedInApiException
     */
    public function get(SocialAccount $account, string $path, string $action): Response
    {
        return $this->send($account, $action, fn (): Response => $this->request($account)->get($path));
    }

    /**
     * PUT raw bytes to an upload URL LinkedIn handed out.
     *
     * @throws LinkedInApiException
     */
    public function upload(SocialAccount $account, string $uploadUrl, string $bytes, string $contentType, bool $withToken, string $action): Response
    {
        return $this->send($account, $action, function () use ($account, $uploadUrl, $bytes, $contentType, $withToken): Response {
            $request = Http::timeout(300)->connectTimeout(10);

            if ($withToken) {
                $request = $request->withToken($account->access_token);
            }

            return $request->withBody($bytes, $contentType)->put($uploadUrl);
        });
    }

    public static function encodeUrn(string $urn): string
    {
        return rawurlencode($urn);
    }

    private function request(SocialAccount $account): PendingRequest
    {
        return Http::baseUrl((string) config('services.linkedin-openid.api_base_url'))
            ->withToken($account->access_token)
            ->withHeaders([
                'Linkedin-Version' => (string) config('services.linkedin-openid.api_version'),
                'X-Restli-Protocol-Version' => self::RESTLI_PROTOCOL_VERSION,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->connectTimeout(10);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws LinkedInApiException
     */
    private function send(SocialAccount $account, string $action, callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            LogService::warning('LinkedIn could not be reached', ['action' => $action], $exception);

            throw new LinkedInApiException("LinkedIn could not be reached while trying to {$action}.", true, previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->errorFor($account, $response, $action);
    }

    private function errorFor(SocialAccount $account, Response $response, string $action): LinkedInApiException
    {
        $status = $response->status();
        $linkedInMessage = (string) ($response->json('message') ?? '');
        $serviceErrorCode = $response->json('serviceErrorCode') ?? $response->json('code');
        $serviceErrorCode = $serviceErrorCode === null ? null : (string) $serviceErrorCode;
        $detail = $linkedInMessage !== '' ? ": {$linkedInMessage}" : '.';

        LogService::warning('LinkedIn returned an error', [
            'action' => $action,
            'status' => $status,
            'service_error_code' => $serviceErrorCode,
            'linkedin_message' => mb_substr($linkedInMessage, 0, 500),
        ]);

        if ($status === 401) {
            $account->update(['needs_reconnect' => true]);

            return new LinkedInApiException("LinkedIn rejected the access token. Reconnect {$account->name} on the Accounts page, then retry.", false, $status, $serviceErrorCode);
        }

        return match (true) {
            $status === 403 => new LinkedInApiException("LinkedIn refused to {$action} (403){$detail} Check that the LinkedIn app has the Share on LinkedIn product.", false, $status, $serviceErrorCode),
            $status === 429 => new LinkedInApiException("LinkedIn rate limit reached while trying to {$action} (429).", true, $status, $serviceErrorCode),
            $status >= 500 => new LinkedInApiException("LinkedIn had a problem while trying to {$action} ({$status}){$detail}", true, $status, $serviceErrorCode),
            default => new LinkedInApiException("LinkedIn rejected the request to {$action} ({$status}){$detail}", false, $status, $serviceErrorCode),
        };
    }
}
