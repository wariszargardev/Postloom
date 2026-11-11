<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\TikTokConnectController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TikTok\TikTokConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const TIKTOK_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
const TIKTOK_USER_URL = 'https://open.tiktokapis.com/v2/user/info/*';

beforeEach(function () {
    config([
        'services.tiktok.client_key' => 'tiktok-client-key',
        'services.tiktok.client_secret' => 'tiktok-client-secret',
        'services.tiktok.redirect' => 'https://postloom.test/accounts/tiktok/callback',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create(['name' => 'Personal']);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

/**
 * @param  array<string, mixed>  $tokenOverrides
 * @param  array<string, mixed>  $userOverrides
 */
function fakeTikTok(array $tokenOverrides = [], array $userOverrides = []): void
{
    Http::fake([
        TIKTOK_TOKEN_URL => Http::response([
            'access_token' => 'act.tiktok-access',
            'refresh_token' => 'rft.tiktok-refresh',
            'expires_in' => 7200,
            'refresh_expires_in' => 15552000,
            'open_id' => '-open-id-1',
            'scope' => 'user.info.basic,user.info.profile,video.upload,video.publish',
            'token_type' => 'Bearer',
            ...$tokenOverrides,
        ]),
        TIKTOK_USER_URL => Http::response([
            'data' => ['user' => [
                'open_id' => '-open-id-1',
                'display_name' => 'Build Real Code',
                'username' => 'buildrealcode',
                'avatar_url' => 'https://p16.tiktokcdn.com/avatar.jpg',
                ...$userOverrides,
            ]],
            'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-1'],
        ]),
    ]);
}

function tikTokCallback(array $query = ['code' => 'the-code', 'state' => 'good-state'], ?int $workspaceId = null): TestResponse
{
    return test()->actingAs(test()->user)
        ->withSession([
            TikTokConnectController::SESSION_STATE_KEY => 'good-state',
            TikTokConnectController::SESSION_WORKSPACE_KEY => $workspaceId ?? test()->workspace->id,
        ])
        ->get(route('accounts.tiktok.callback', $query));
}

it('redirects to TikTok with the client key, scopes, redirect URI and a state', function () {
    $location = $this->actingAs($this->user)->get(route('accounts.tiktok.redirect'))->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize/')
        ->and($query['client_key'])->toBe('tiktok-client-key')
        ->and($query['scope'])->toBe(implode(',', TikTokConnectService::SCOPES))
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe('https://postloom.test/accounts/tiktok/callback')
        ->and($query['state'])->toBe(session(TikTokConnectController::SESSION_STATE_KEY))
        ->and(session(TikTokConnectController::SESSION_WORKSPACE_KEY))->toBe($this->workspace->id);
});

it('asks for the keys when TikTok is not configured', function () {
    config(['services.tiktok.client_key' => null]);

    $this->actingAs($this->user)->get(route('accounts.tiktok.redirect'))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env first.');
});

it('saves the TikTok account into the workspace it was started from', function () {
    $brandWorkspace = Workspace::factory()->for($this->user)->create();
    fakeTikTok();

    tikTokCallback(workspaceId: $brandWorkspace->id)
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Build Real Code is connected.');

    $account = SocialAccount::query()->sole();

    expect($account->workspace_id)->toBe($brandWorkspace->id)
        ->and($account->provider)->toBe(SocialProvider::TikTok)
        ->and($account->account_type)->toBe(SocialAccountType::Profile)
        ->and($account->provider_account_id)->toBe('-open-id-1')
        ->and($account->name)->toBe('Build Real Code')
        ->and($account->handle)->toBe('buildrealcode')
        ->and($account->access_token)->toBe('act.tiktok-access')
        ->and($account->refresh_token)->toBe('rft.tiktok-refresh')
        ->and($account->scopes)->toBe(TikTokConnectService::SCOPES)
        ->and($account->token_expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue()
        ->and($account->status())->toBe(SocialAccountStatus::Connected);

    Http::assertSent(fn (Request $request) => $request->url() === TIKTOK_TOKEN_URL
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && $request['client_key'] === 'tiktok-client-key'
        && $request['redirect_uri'] === 'https://postloom.test/accounts/tiktok/callback');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request->hasHeader('Authorization', 'Bearer act.tiktok-access'));
});

it('updates the same TikTok account when it is connected again', function () {
    Http::fake([
        TIKTOK_TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'act.first', 'refresh_token' => 'rft.first', 'expires_in' => 7200, 'open_id' => '-open-id-1', 'scope' => 'user.info.basic,video.publish'])
            ->push(['access_token' => 'act.second', 'refresh_token' => 'rft.second', 'expires_in' => 7200, 'open_id' => '-open-id-1', 'scope' => 'user.info.basic,video.publish']),
        TIKTOK_USER_URL => Http::sequence()
            ->push(['data' => ['user' => ['open_id' => '-open-id-1', 'display_name' => 'Build Real Code']], 'error' => ['code' => 'ok']])
            ->push(['data' => ['user' => ['open_id' => '-open-id-1', 'display_name' => 'Renamed']], 'error' => ['code' => 'ok']]),
    ]);

    tikTokCallback();
    tikTokCallback();

    $account = SocialAccount::query()->sole();
    expect($account->name)->toBe('Renamed')
        ->and($account->access_token)->toBe('act.second')
        ->and($account->refresh_token)->toBe('rft.second');
});

it('refuses a callback whose state does not match', function () {
    Http::fake();

    tikTokCallback(['code' => 'the-code', 'state' => 'forged'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'The TikTok login expired. Please connect again.');

    expect(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows a cancel message when the owner cancels on TikTok', function () {
    Http::fake();

    tikTokCallback(['error' => 'access_denied', 'error_description' => 'User cancelled', 'state' => 'good-state'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'TikTok connection was cancelled.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('refuses the connection when no video permission was granted', function () {
    fakeTikTok(['scope' => 'user.info.basic']);

    tikTokCallback()
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'did not allow posting videos'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('explains a refused code exchange', function () {
    Http::fake([TIKTOK_TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'Client key does not match', 'log_id' => 'log-2'], 401)]);

    tikTokCallback()
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'invalid_client') && str_contains($message, 'TIKTOK_REDIRECT_URI'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('lists TikTok as available on the accounts page once configured', function () {
    expect(SocialProvider::TikTok->isAvailable())->toBeTrue()
        ->and(SocialProvider::TikTok->availabilityNote())->toBe('Profile (videos)')
        ->and(SocialProvider::TikTok->connectRouteName())->toBe('accounts.tiktok.redirect');

    config(['services.tiktok.client_secret' => null]);

    expect(SocialProvider::TikTok->isAvailable())->toBeFalse()
        ->and(SocialProvider::TikTok->availabilityNote())->toContain('TIKTOK_CLIENT_KEY');
});
