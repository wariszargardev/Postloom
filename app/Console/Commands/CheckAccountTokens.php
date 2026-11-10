<?php

namespace App\Console\Commands;

use App\Enums\LogFeature;
use App\Models\SocialAccount;
use App\Services\LogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:check-tokens')]
#[Description('Mark social accounts with expired tokens as needing reconnect and warn about tokens that expire soon')]
class CheckAccountTokens extends Command
{
    public function handle(): int
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

        LogService::info('Social account token check finished', [
            'marked_for_reconnect' => $expiredCount,
            'expiring_soon' => $expiringSoonCount,
        ]);

        $this->info("Marked for reconnect: {$expiredCount}. Expiring soon: {$expiringSoonCount}.");

        return self::SUCCESS;
    }
}
