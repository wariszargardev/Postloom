<?php

namespace App\Console\Commands;

use App\Enums\LogFeature;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Services\Facebook\FacebookConnectService;
use App\Services\LogService;
use App\Services\TikTok\TikTokTokenException;
use App\Services\TikTok\TikTokTokenService;
use App\Services\YouTube\YouTubeTokenException;
use App\Services\YouTube\YouTubeTokenService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:check-tokens')]
#[Description('Mark social accounts with expired tokens as needing reconnect, warn about tokens that expire soon, refresh YouTube and TikTok tokens, and test Facebook Page tokens')]
class CheckAccountTokens extends Command
{
    public function handle(YouTubeTokenService $youTubeTokenService, FacebookConnectService $facebookConnectService, TikTokTokenService $tikTokTokenService): int
    {
        LogService::startFeatureLogging(LogFeature::Accounts, ['command' => 'accounts:check-tokens']);
        LogService::info('Checking social account tokens', []);

        $expiredCount = 0;
        $expiringSoonCount = 0;

        SocialAccount::query()->needingAttention()->each(function (SocialAccount $account) use (&$expiredCount, &$expiringSoonCount): void {
            $context = [
                'account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
                'provider' => $account->provider->value,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ];

            if ($account->isTokenExpired() && ! $account->needs_reconnect) {
                $account->update(['needs_reconnect' => true]);
                $expiredCount++;
                LogService::warning('Social account token expired, marked for reconnect', $context);

                return;
            }

            if ($account->expiresSoon()) {
                $expiringSoonCount++;
                LogService::warning('Social account token expires soon', $context);
            }
        });

        SocialAccount::query()
            ->where('provider', SocialProvider::YouTube)
            ->where('needs_reconnect', false)
            ->whereNotNull('refresh_token')
            ->each(function (SocialAccount $account) use ($youTubeTokenService, &$expiredCount): void {
                try {
                    $youTubeTokenService->refresh($account);
                } catch (YouTubeTokenException $exception) {
                    if ($account->needs_reconnect) {
                        $expiredCount++;

                        return;
                    }

                    LogService::warning('YouTube token check failed, will try again tomorrow', ['account_id' => $account->id], $exception);
                }
            });

        SocialAccount::query()
            ->where('provider', SocialProvider::TikTok)
            ->where('needs_reconnect', false)
            ->whereNotNull('refresh_token')
            ->each(function (SocialAccount $account) use ($tikTokTokenService, &$expiredCount): void {
                try {
                    $tikTokTokenService->refresh($account);
                } catch (TikTokTokenException $exception) {
                    if ($account->needs_reconnect) {
                        $expiredCount++;

                        return;
                    }

                    LogService::warning('TikTok token check failed, will try again tomorrow', ['account_id' => $account->id], $exception);
                }
            });

        SocialAccount::query()
            ->where('provider', SocialProvider::Facebook)
            ->where('needs_reconnect', false)
            ->each(function (SocialAccount $account) use ($facebookConnectService, &$expiredCount): void {
                if (! $facebookConnectService->checkPageToken($account)) {
                    $expiredCount++;
                }
            });

        LogService::info('Social account token check finished', [
            'marked_for_reconnect' => $expiredCount,
            'expiring_soon' => $expiringSoonCount,
        ]);

        $this->info("Marked for reconnect: {$expiredCount}. Expiring soon: {$expiringSoonCount}.");

        return self::SUCCESS;
    }
}
