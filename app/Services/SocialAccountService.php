<?php

namespace App\Services;

use App\Enums\LogFeature;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

class SocialAccountService
{
    /**
     * Save an account after its OAuth login. Reconnecting the same account updates it in place.
     *
     * @param  array{
     *     provider: SocialProvider,
     *     account_type: SocialAccountType,
     *     provider_account_id: string,
     *     name: string,
     *     handle?: string|null,
     *     avatar_url?: string|null,
     *     access_token: string,
     *     refresh_token?: string|null,
     *     token_expires_at?: Carbon|null,
     *     scopes?: array<int, string>|null,
     * }  $details
     */
    public function saveConnectedAccount(Workspace $workspace, array $details): SocialAccount
    {
        LogService::startFeatureLogging(LogFeature::Accounts, [
            'workspace_id' => $workspace->id,
            'provider' => $details['provider']->value,
        ]);

        $account = $workspace->socialAccounts()->updateOrCreate(
            [
                'provider' => $details['provider'],
                'provider_account_id' => $details['provider_account_id'],
            ],
            [
                'account_type' => $details['account_type'],
                'name' => $details['name'],
                'handle' => $details['handle'] ?? null,
                'avatar_url' => $details['avatar_url'] ?? null,
                'access_token' => $details['access_token'],
                'refresh_token' => $details['refresh_token'] ?? null,
                'token_expires_at' => $details['token_expires_at'] ?? null,
                'scopes' => $details['scopes'] ?? null,
                'needs_reconnect' => false,
                'connected_at' => now(),
            ],
        );

        LogService::info($account->wasRecentlyCreated ? 'Social account connected' : 'Social account reconnected', [
            'account_id' => $account->id,
            'account_type' => $account->account_type->value,
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
        ]);

        return $account;
    }

    public function disconnect(SocialAccount $account): void
    {
        LogService::startFeatureLogging(LogFeature::Accounts, [
            'workspace_id' => $account->workspace_id,
            'provider' => $account->provider->value,
        ]);

        $accountId = $account->id;
        $account->delete();

        LogService::info('Social account disconnected', ['account_id' => $accountId]);
    }
}
