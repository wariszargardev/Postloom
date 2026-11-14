<?php

namespace App\Services;

use App\Enums\LogFeature;
use App\Enums\PostStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonInterface;

class SocialAccountService
{
    public function __construct(private SoftDeleteCascade $softDeleteCascade) {}

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
     *     token_expires_at?: CarbonInterface|null,
     *     scopes?: array<int, string>|null,
     * }  $details
     */
    public function saveConnectedAccount(Workspace $workspace, array $details): SocialAccount
    {
        $account = $workspace->socialAccounts()->withTrashed()->updateOrCreate(
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

        if ($account->trashed()) {
            $account->restore();
        }

        LogService::info($account->wasRecentlyCreated ? 'Social account connected' : 'Social account reconnected', [
            'workspace_id' => $workspace->id,
            'provider' => $account->provider->value,
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
        $this->softDeleteCascade->socialAccount($account);

        LogService::info('Social account disconnected', ['account_id' => $accountId]);
    }

    public function disconnectBlockReason(SocialAccount $account): ?string
    {
        $upcomingPosts = $account->postTargets()
            ->whereHas('post', fn ($query) => $query->whereIn('status', PostStatus::upcoming()))
            ->count();

        return $upcomingPosts === 0
            ? null
            : trans_choice('{1} This account has 1 scheduled post. Move it to drafts or remove the account from it first.|[2,*] This account has :count scheduled posts. Move them to drafts or remove the account from them first.', $upcomingPosts);
    }
}
