<?php

use App\Enums\SocialAccountStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\YouTubeConnectController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\YouTube\YouTubeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const YOUTUBE_CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels*';

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect' => 'http://localhost:8000/accounts/youtube/callback',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create(['name' => 'Personal']);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function fakeGoogleUser(?array $approvedScopes = null, ?string $refreshToken = 'google-refresh-token', string $token = 'google-access-token'): GoogleUser
{
    return (new GoogleUser)
        ->setRaw(['sub' => 'google-user-1'])
        ->map(['id' => 'google-user-1', 'name' => 'Muhammad Waris', 'email' => 'waris@example.com', 'avatar' => 'https://lh3.googleusercontent.com/a.jpg'])
        ->setToken($token)
        ->setRefreshToken($refreshToken)
        ->setExpiresIn(3599)
        ->setApprovedScopes($approvedScopes ?? ['openid', 'https://www.googleapis.com/auth/userinfo.email', ...YouTubeConnectService::SCOPES]);
}

function fakeGoogleCallback(GoogleUser|Throwable $result, GoogleUser ...$laterResults): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $expectation = $provider->shouldReceive('user');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result, ...$laterResults);

    Socialite::shouldReceive('driver')->with(YouTubeConnectService::DRIVER)->andReturn($provider);
}

function fakeChannel(string $id = 'UCchannel000000000000001', string $title = 'Waris Codes'): void
{
    Http::fake([YOUTUBE_CHANNELS_URL => Http::response(['items' => [[
        'id' => $id,
        'snippet' => [
            'title' => $title,
            'customUrl' => '@wariscodes',
            'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/avatar.jpg']],
        ],
    ]]])]);
}

it('redirects to Google with the YouTube scopes, offline access and the account picker', function () {
    $location = urldecode($this->actingAs($this->user)->get(route('accounts.youtube.redirect'))->headers->get('Location'));

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->and($location)->toContain(YouTubeConnectService::UPLOAD_SCOPE)
        ->and($location)->toContain(YouTubeConnectService::READ_SCOPE)
        ->and($location)->toContain('access_type=offline')
        ->and($location)->toContain('prompt=consent select_account')
        ->and($location)->toContain('client_id=google-client-id');
    expect(session(YouTubeConnectController::SESSION_WORKSPACE_KEY))->toBe($this->workspace->id);
});

it('saves the picked channel into the workspace it was started from', function () {
    $brandWorkspace = Workspace::factory()->for($this->user)->create();
    fakeGoogleCallback(fakeGoogleUser());
    fakeChannel();

    $this->actingAs($this->user)
        ->withSession([YouTubeConnectController::SESSION_WORKSPACE_KEY => $brandWorkspace->id])
        ->get(route('accounts.youtube.callback', ['code' => 'abc', 'state' => 'xyz']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Waris Codes is connected.');

    $account = SocialAccount::query()->sole();

    expect($account->workspace_id)->toBe($brandWorkspace->id)
        ->and($account->provider)->toBe(SocialProvider::YouTube)
        ->and($account->account_type)->toBe(SocialAccountType::Channel)
        ->and($account->provider_account_id)->toBe('UCchannel000000000000001')
        ->and($account->name)->toBe('Waris Codes')
        ->and($account->handle)->toBe('wariscodes')
        ->and($account->avatar_url)->toBe('https://yt3.ggpht.com/avatar.jpg')
        ->and($account->access_token)->toBe('google-access-token')
        ->and($account->refresh_token)->toBe('google-refresh-token')
        ->and($account->status())->toBe(SocialAccountStatus::Connected);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')
        && $request['mine'] === 'true'
        && $request->hasHeader('Authorization', 'Bearer google-access-token'));
});

it('adds a second channel as its own account and updates a channel connected again', function () {
    fakeGoogleCallback(fakeGoogleUser(token: 'first'), fakeGoogleUser(token: 'second'), fakeGoogleUser(token: 'third'));
    Http::fake([YOUTUBE_CHANNELS_URL => Http::sequence()
        ->push(['items' => [['id' => 'UCmain', 'snippet' => ['title' => 'Main']]]])
        ->push(['items' => [['id' => 'UCbrand', 'snippet' => ['title' => 'Brand']]]])
        ->push(['items' => [['id' => 'UCmain', 'snippet' => ['title' => 'Main renamed']]]])]);

    foreach (['a', 'b', 'c'] as $code) {
        $this->actingAs($this->user)->get(route('accounts.youtube.callback', ['code' => $code]));
    }

    expect(SocialAccount::query()->orderBy('id')->pluck('name', 'provider_account_id')->all())
        ->toBe(['UCmain' => 'Main renamed', 'UCbrand' => 'Brand'])
        ->and(SocialAccount::query()->where('provider_account_id', 'UCmain')->sole()->access_token)->toBe('third');
});

it('refuses the connection when upload permission was unticked', function () {
    fakeGoogleCallback(fakeGoogleUser(approvedScopes: ['openid', YouTubeConnectService::READ_SCOPE]));
    fakeChannel();

    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'did not allow uploading'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('refuses the connection when Google sends no refresh token', function () {
    fakeGoogleCallback(fakeGoogleUser(refreshToken: null));
    fakeChannel();

    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'refresh token'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('tells the owner when the Google account has no channel', function () {
    fakeGoogleCallback(fakeGoogleUser());
    Http::fake([YOUTUBE_CHANNELS_URL => Http::response(['items' => []])]);

    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message) => str_contains($message, 'no YouTube channel'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('shows a general error when the channel lookup fails', function () {
    fakeGoogleCallback(fakeGoogleUser());
    Http::fake([YOUTUBE_CHANNELS_URL => Http::response(['error' => ['message' => 'API not enabled', 'errors' => [['reason' => 'accessNotConfigured']]]], 403)]);

    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Could not connect YouTube. Check the logs and try again.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('saves nothing when the owner cancels on Google', function () {
    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['error' => 'access_denied']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'YouTube connection was cancelled.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('asks to connect again when the Google login state expired', function () {
    fakeGoogleCallback(new InvalidStateException);

    $this->actingAs($this->user)
        ->get(route('accounts.youtube.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'The Google login expired. Please connect again.');
});

it('keeps YouTube disabled until the Google keys are set', function () {
    config(['services.google.client_id' => null]);

    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('providers.1.value', 'youtube')
            ->where('providers.1.is_available', false)
            ->where('providers.1.connect_url', null));

    $this->actingAs($this->user)->get(route('accounts.youtube.redirect'))->assertRedirect(route('accounts.index'));
});

it('shows the YouTube connect link and an auto-renewing token on the accounts page', function () {
    SocialAccount::factory()->for($this->workspace)->youTube()->create();

    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('providers.1.connect_url', route('accounts.youtube.redirect'))
            ->where('providers.1.availability_note', 'Channel')
            ->where('accounts.0.token_renews_automatically', true)
            ->where('accounts.0.status', 'connected'));
});

it('blocks guests', function () {
    $this->get(route('accounts.youtube.redirect'))->assertRedirect(route('login'));
});
