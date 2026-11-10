<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->socialAccountService = app(SocialAccountService::class);
});

it('stores tokens encrypted', function () {
    $account = SocialAccount::factory()->create([
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
    ]);

    $raw = SocialAccount::query()->toBase()->where('id', $account->id)->first();

    expect($raw->access_token)->not->toContain('plain-access-token')
        ->and($raw->refresh_token)->not->toContain('plain-refresh-token')
        ->and($account->fresh()->access_token)->toBe('plain-access-token');
});

it('never sends tokens to the accounts page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user)->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    SocialAccount::factory()->for($workspace)->create(['access_token' => 'secret-access-token']);

    $response = $this->actingAs($user)->get(route('accounts.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('accounts/Index')
        ->has('accounts', 1)
        ->missing('accounts.0.access_token')
        ->missing('accounts.0.refresh_token'));
    expect($response->getContent())->not->toContain('secret-access-token');
});

it('only lists accounts from the current workspace', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->for($user)->create();
    $other = Workspace::factory()->for($user)->create();
    $user->forceFill(['current_workspace_id' => $current->id])->save();
    SocialAccount::factory()->for($current)->create(['name' => 'In current']);
    SocialAccount::factory()->for($other)->create(['name' => 'In other']);

    $this->actingAs($user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', 'In current'));
});

it('works out the account status', function (string $state, SocialAccountStatus $expected) {
    $factory = SocialAccount::factory();
    $account = ($state === 'valid' ? $factory : $factory->{$state}())->create();

    expect($account->status())->toBe($expected);
})->with([
    'valid token' => ['valid', SocialAccountStatus::Connected],
    'expires within 7 days' => ['expiringSoon', SocialAccountStatus::ExpiringSoon],
    'expired token' => ['expired', SocialAccountStatus::ReconnectNeeded],
    'marked for reconnect' => ['needsReconnect', SocialAccountStatus::ReconnectNeeded],
]);

it('saves a connected account and updates it on reconnect', function () {
    $workspace = Workspace::factory()->create();
    $details = [
        'provider' => SocialProvider::LinkedIn,
        'account_type' => SocialAccountType::Profile,
        'provider_account_id' => 'abc123',
        'name' => 'Muhammad Waris',
        'access_token' => 'first-token',
        'token_expires_at' => now()->addDays(60),
        'scopes' => ['openid', 'w_member_social'],
    ];

    $first = $this->socialAccountService->saveConnectedAccount($workspace, $details);
    $second = $this->socialAccountService->saveConnectedAccount($workspace, [...$details, 'access_token' => 'second-token']);

    expect($second->id)->toBe($first->id)
        ->and($workspace->socialAccounts()->count())->toBe(1)
        ->and($second->fresh()->access_token)->toBe('second-token')
        ->and($second->needs_reconnect)->toBeFalse();
});

it('allows several accounts of the same platform in one workspace', function () {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->count(3)->create(['provider' => SocialProvider::LinkedIn]);

    expect($workspace->socialAccounts()->count())->toBe(3);
});

it('disconnects an account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('accounts.index'));

    $this->assertModelMissing($account);
});

it('blocks disconnecting another owner\'s account', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->create();

    $this->actingAs($user)->delete(route('accounts.destroy', $account))->assertForbidden();

    $this->assertModelExists($account);
});
