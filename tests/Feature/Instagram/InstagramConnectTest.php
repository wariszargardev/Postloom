<?php

use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\InstagramConnectController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Facebook\FacebookConnectService;
use App\Services\Instagram\InstagramConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as FacebookUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const IGC_GRAPH = 'https://graph.facebook.com/v27.0';

beforeEach(function () {
    config([
        'services.facebook.client_id' => 'fb-app-id',
        'services.facebook.client_secret' => 'fb-app-secret',
        'services.facebook.graph_version' => 'v27.0',
        'services.instagram.redirect' => 'http://localhost:8000/accounts/instagram/callback',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function fakeInstagramCallback(FacebookUser|Throwable $result): void
{
    $provider = Mockery::mock(FacebookProvider::class)->makePartial();
    $provider->shouldReceive('usingGraphVersion')->andReturnSelf();
    $provider->shouldReceive('fields')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $expectation = $provider->shouldReceive('user');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result);

    Socialite::shouldReceive('driver')->with(FacebookConnectService::DRIVER)->andReturn($provider);
}

function instagramLoginUser(): FacebookUser
{
    return (new FacebookUser)->setRaw(['id' => 'fb-user-1'])->map(['id' => 'fb-user-1', 'name' => 'Muhammad Waris'])->setToken('short-user-token');
}

/**
 * @param  array<int, array<string, mixed>>|null  $pages
 * @param  array<int, string>|null  $granted
 */
function fakeInstagramGraph(?array $pages = null, ?array $granted = null): void
{
    Http::fake([
        IGC_GRAPH.'/oauth/access_token*' => Http::response(['access_token' => 'long-user-token', 'token_type' => 'bearer']),
        IGC_GRAPH.'/me/permissions*' => Http::response(['data' => collect($granted ?? InstagramConnectService::SCOPES)->map(fn ($permission) => ['permission' => $permission, 'status' => 'granted'])->all()]),
        IGC_GRAPH.'/me/accounts*' => Http::response(['data' => $pages ?? [
            ['id' => '1111', 'name' => 'Build Real Code', 'access_token' => 'page-token-1', 'instagram_business_account' => ['id' => '17841400000000001', 'username' => 'buildrealcode', 'name' => 'Build Real Code', 'profile_picture_url' => 'https://scontent.example/ig.jpg']],
            ['id' => '2222', 'name' => 'Side Project', 'access_token' => 'page-token-2', 'instagram_business_account' => ['id' => '17841400000000002', 'username' => 'sideproject']],
            ['id' => '3333', 'name' => 'No Instagram', 'access_token' => 'page-token-3'],
        ]]),
    ]);
}

it('redirects to Facebook with the Instagram permissions and the Instagram callback', function () {
    $location = urldecode($this->actingAs($this->user)->get(route('accounts.instagram.redirect'))->headers->get('Location'));

    expect($location)->toStartWith('https://www.facebook.com/v27.0/dialog/oauth')
        ->and($location)->toContain('scope=instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement')
        ->and($location)->toContain('redirect_uri=http://localhost:8000/accounts/instagram/callback')
        ->and($location)->not->toContain('email');
    expect(session(InstagramConnectController::SESSION_WORKSPACE_KEY))->toBe($this->workspace->id);
});

it('saves every Instagram account linked to a shared Page with that Page token', function () {
    fakeInstagramCallback(instagramLoginUser());
    fakeInstagramGraph();

    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['code' => 'abc', 'state' => 'xyz']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', '@buildrealcode and @sideproject are connected.');

    $accounts = SocialAccount::query()->orderBy('provider_account_id')->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->provider)->toBe(SocialProvider::Instagram)
        ->and($accounts[0]->account_type)->toBe(SocialAccountType::Profile)
        ->and($accounts[0]->provider_account_id)->toBe('17841400000000001')
        ->and($accounts[0]->name)->toBe('Build Real Code')
        ->and($accounts[0]->handle)->toBe('buildrealcode')
        ->and($accounts[0]->avatar_url)->toBe('https://scontent.example/ig.jpg')
        ->and($accounts[0]->access_token)->toBe('page-token-1')
        ->and($accounts[0]->token_expires_at)->toBeNull()
        ->and($accounts[1]->name)->toBe('sideproject')
        ->and($accounts[1]->access_token)->toBe('page-token-2');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), IGC_GRAPH.'/me/accounts')
        && str_contains((string) $request['fields'], 'instagram_business_account{id,username,name,profile_picture_url}')
        && $request['access_token'] === 'long-user-token');
});

it('updates an Instagram account connected again instead of adding it twice', function () {
    fakeInstagramCallback(instagramLoginUser());
    fakeInstagramGraph();

    $this->actingAs($this->user)->get(route('accounts.instagram.callback', ['code' => 'a']));
    $this->actingAs($this->user)->get(route('accounts.instagram.callback', ['code' => 'b']));

    expect(SocialAccount::query()->count())->toBe(2);
});

it('refuses the connection when the publishing permission was switched off', function () {
    fakeInstagramCallback(instagramLoginUser());
    fakeInstagramGraph(granted: ['instagram_basic', 'pages_show_list']);

    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'did not allow publishing to Instagram'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('explains what to do when no Page has an Instagram professional account', function () {
    fakeInstagramCallback(instagramLoginUser());
    fakeInstagramGraph(pages: [['id' => '3333', 'name' => 'No Instagram', 'access_token' => 'page-token-3']]);

    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'Edit previous settings'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('saves nothing when the owner cancels', function () {
    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['error' => 'access_denied']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Instagram connection was cancelled.');
});

it('asks to connect again when the login state expired', function () {
    fakeInstagramCallback(new InvalidStateException);

    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'The Instagram login expired. Please connect again.');
});

it('shows a general error when Facebook rejects the token exchange', function () {
    fakeInstagramCallback(instagramLoginUser());
    Http::fake([IGC_GRAPH.'/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid client_secret', 'code' => 1]], 400)]);

    $this->actingAs($this->user)
        ->get(route('accounts.instagram.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Could not connect Instagram. Check the logs and try again.');
});

it('offers Instagram on the Accounts page only when the Meta app keys are set', function () {
    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('providers.3.value', 'instagram')
            ->where('providers.3.connect_url', route('accounts.instagram.redirect')));

    config(['services.facebook.client_id' => null]);

    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('providers.3.connect_url', null));
    $this->actingAs($this->user)->get(route('accounts.instagram.redirect'))
        ->assertRedirect(route('accounts.index'));
});

it('marks an Instagram account for reconnect in the daily check when its Page token is revoked', function () {
    $revoked = SocialAccount::factory()->for($this->workspace)->instagram()->create(['access_token' => 'revoked']);
    $fine = SocialAccount::factory()->for($this->workspace)->instagram()->create(['access_token' => 'fine']);
    Http::fake([IGC_GRAPH.'/me*' => fn (Request $request) => $request['access_token'] === 'revoked'
        ? Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190, 'error_subcode' => 460]], 400)
        : Http::response(['id' => '1111'])]);

    $this->artisan('accounts:check-tokens')->assertSuccessful();

    expect($revoked->fresh()->needs_reconnect)->toBeTrue()
        ->and($fine->fresh()->needs_reconnect)->toBeFalse();
});
