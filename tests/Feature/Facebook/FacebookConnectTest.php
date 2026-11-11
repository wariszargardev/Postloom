<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\FacebookConnectController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Facebook\FacebookConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as FacebookUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const FB_GRAPH = 'https://graph.facebook.com/v27.0';

beforeEach(function () {
    config([
        'services.facebook.client_id' => 'fb-app-id',
        'services.facebook.client_secret' => 'fb-app-secret',
        'services.facebook.redirect' => 'http://localhost:8000/accounts/facebook/callback',
        'services.facebook.graph_version' => 'v27.0',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function fakeFacebookCallback(FacebookUser|Throwable $result): void
{
    $provider = Mockery::mock(FacebookProvider::class)->makePartial();
    $provider->shouldReceive('usingGraphVersion')->andReturnSelf();
    $provider->shouldReceive('fields')->andReturnSelf();
    $expectation = $provider->shouldReceive('user');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result);

    Socialite::shouldReceive('driver')->with(FacebookConnectService::DRIVER)->andReturn($provider);
}

function facebookUser(): FacebookUser
{
    return (new FacebookUser)->setRaw(['id' => 'fb-user-1'])->map(['id' => 'fb-user-1', 'name' => 'Muhammad Waris'])->setToken('short-user-token');
}

/**
 * @param  array<int, array<string, mixed>>|null  $pages
 * @param  array<int, string>|null  $granted
 */
function fakeGraph(?array $pages = null, ?array $granted = null): void
{
    Http::fake([
        FB_GRAPH.'/oauth/access_token*' => Http::response(['access_token' => 'long-user-token', 'token_type' => 'bearer', 'expires_in' => 5183944]),
        FB_GRAPH.'/me/permissions*' => Http::response(['data' => collect($granted ?? FacebookConnectService::SCOPES)->map(fn ($permission) => ['permission' => $permission, 'status' => 'granted'])->all()]),
        FB_GRAPH.'/me/accounts*' => Http::response(['data' => $pages ?? [
            ['id' => '1111', 'name' => 'Build Real Code', 'username' => 'buildrealcode', 'access_token' => 'page-token-1', 'tasks' => ['ANALYZE', 'ADVERTISE', 'CREATE_CONTENT', 'MANAGE'], 'picture' => ['data' => ['url' => 'https://scontent.example/brc.jpg']]],
            ['id' => '2222', 'name' => 'Side Project', 'access_token' => 'page-token-2', 'tasks' => ['CREATE_CONTENT']],
        ]]),
    ]);
}

it('redirects to Facebook with only the Page permissions', function () {
    $location = urldecode($this->actingAs($this->user)->get(route('accounts.facebook.redirect'))->headers->get('Location'));

    expect($location)->toStartWith('https://www.facebook.com/v27.0/dialog/oauth')
        ->and($location)->toContain('scope=pages_show_list,pages_read_engagement,pages_manage_posts')
        ->and($location)->not->toContain('email')
        ->and($location)->toContain('client_id=fb-app-id');
    expect(session(FacebookConnectController::SESSION_WORKSPACE_KEY))->toBe($this->workspace->id);
});

it('saves every shared Page as its own account with a Page token', function () {
    fakeFacebookCallback(facebookUser());
    fakeGraph();

    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['code' => 'abc', 'state' => 'xyz']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Build Real Code and Side Project are connected.');

    $pages = SocialAccount::query()->orderBy('provider_account_id')->get();

    expect($pages)->toHaveCount(2)
        ->and($pages[0]->provider)->toBe(SocialProvider::Facebook)
        ->and($pages[0]->account_type)->toBe(SocialAccountType::Page)
        ->and($pages[0]->workspace_id)->toBe($this->workspace->id)
        ->and($pages[0]->provider_account_id)->toBe('1111')
        ->and($pages[0]->name)->toBe('Build Real Code')
        ->and($pages[0]->handle)->toBe('buildrealcode')
        ->and($pages[0]->avatar_url)->toBe('https://scontent.example/brc.jpg')
        ->and($pages[0]->access_token)->toBe('page-token-1')
        ->and($pages[0]->token_expires_at)->toBeNull()
        ->and($pages[0]->status())->toBe(SocialAccountStatus::Connected)
        ->and($pages[1]->access_token)->toBe('page-token-2');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FB_GRAPH.'/oauth/access_token')
        && $request['grant_type'] === 'fb_exchange_token'
        && $request['fb_exchange_token'] === 'short-user-token'
        && $request['client_secret'] === 'fb-app-secret');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), FB_GRAPH.'/me/accounts')
        && $request['access_token'] === 'long-user-token');
});

it('updates a Page that is connected again instead of adding it twice', function () {
    fakeFacebookCallback(facebookUser());
    fakeGraph();
    $this->actingAs($this->user)->get(route('accounts.facebook.callback', ['code' => 'a']));
    $this->actingAs($this->user)->get(route('accounts.facebook.callback', ['code' => 'b']));

    expect(SocialAccount::query()->count())->toBe(2);
});

it('skips Pages the owner cannot post to', function () {
    fakeFacebookCallback(facebookUser());
    fakeGraph(pages: [
        ['id' => '1111', 'name' => 'Can post', 'access_token' => 't1', 'tasks' => ['CREATE_CONTENT']],
        ['id' => '3333', 'name' => 'Analyst only', 'access_token' => 't3', 'tasks' => ['ANALYZE']],
    ]);

    $this->actingAs($this->user)->get(route('accounts.facebook.callback', ['code' => 'abc']));

    expect(SocialAccount::query()->pluck('name')->all())->toBe(['Can post']);
});

it('refuses the connection when posting permission was switched off', function () {
    fakeFacebookCallback(facebookUser());
    fakeGraph(granted: ['pages_show_list', 'pages_read_engagement']);

    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'did not allow posting'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('tells the owner when no Page was shared', function () {
    fakeFacebookCallback(facebookUser());
    fakeGraph(pages: []);

    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'No Facebook Page was shared'));
});

it('shows a general error when Facebook rejects the token exchange', function () {
    fakeFacebookCallback(facebookUser());
    Http::fake([FB_GRAPH.'/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid client_secret', 'type' => 'OAuthException', 'code' => 1]], 400)]);

    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Could not connect Facebook. Check the logs and try again.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('saves nothing when the owner cancels on Facebook', function () {
    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['error' => 'access_denied']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Facebook connection was cancelled.');
});

it('asks to connect again when the Facebook login state expired', function () {
    fakeFacebookCallback(new InvalidStateException);

    $this->actingAs($this->user)
        ->get(route('accounts.facebook.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'The Facebook login expired. Please connect again.');
});

it('keeps Facebook disabled until the app keys are set', function () {
    config(['services.facebook.client_id' => null]);

    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('providers.2.value', 'facebook')
            ->where('providers.2.connect_url', null));
});

it('marks a Page for reconnect in the daily check when Facebook rejects its token', function () {
    $revoked = SocialAccount::factory()->for($this->workspace)->create(['provider' => SocialProvider::Facebook, 'account_type' => SocialAccountType::Page, 'access_token' => 'revoked', 'token_expires_at' => null]);
    $fine = SocialAccount::factory()->for($this->workspace)->create(['provider' => SocialProvider::Facebook, 'account_type' => SocialAccountType::Page, 'access_token' => 'fine', 'token_expires_at' => null]);
    Http::fake([FB_GRAPH.'/me*' => function (Request $request) {
        return $request['access_token'] === 'revoked'
            ? Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460]], 400)
            : Http::response(['id' => '1111']);
    }]);

    $this->artisan('accounts:check-tokens')
        ->expectsOutput('Marked for reconnect: 1. Expiring soon: 0.')
        ->assertSuccessful();

    expect($revoked->fresh()->needs_reconnect)->toBeTrue()
        ->and($fine->fresh()->needs_reconnect)->toBeFalse();
});

it('keeps a Page connected when the daily check hits a rate limit', function () {
    $page = SocialAccount::factory()->for($this->workspace)->create(['provider' => SocialProvider::Facebook, 'account_type' => SocialAccountType::Page, 'token_expires_at' => null]);
    Http::fake([FB_GRAPH.'/me*' => Http::response(['error' => ['message' => 'Page request limit reached', 'code' => 32]], 400)]);

    $this->artisan('accounts:check-tokens')->assertSuccessful();

    expect($page->fresh()->needs_reconnect)->toBeFalse();
});
