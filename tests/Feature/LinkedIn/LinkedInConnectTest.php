<?php

use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\LinkedInConnectController;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LinkedIn\LinkedInConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use Laravel\Socialite\Two\User as LinkedInUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.linkedin-openid.client_id' => 'test-client-id',
        'services.linkedin-openid.client_secret' => 'test-client-secret',
        'services.linkedin-openid.redirect' => 'http://localhost:8000/accounts/linkedin/callback',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create(['name' => 'Personal']);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function fakeLinkedInUser(array $approvedScopes = LinkedInConnectService::SCOPES, string $token = 'linkedin-access-token'): LinkedInUser
{
    return (new LinkedInUser)
        ->setRaw(['sub' => 'linkedin-person-1'])
        ->map([
            'id' => 'linkedin-person-1',
            'name' => 'Muhammad Waris',
            'email' => 'waris@example.com',
            'avatar' => 'https://media.licdn.com/avatar.jpg',
        ])
        ->setToken($token)
        ->setExpiresIn(5184000)
        ->setApprovedScopes($approvedScopes);
}

function fakeLinkedInCallback(LinkedInUser|Throwable $result, LinkedInUser ...$laterResults): void
{
    $provider = Mockery::mock(LinkedInOpenIdProvider::class);
    $expectation = $provider->shouldReceive('user');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result, ...$laterResults);

    Socialite::shouldReceive('driver')->with(LinkedInConnectService::DRIVER)->andReturn($provider);
}

it('redirects to LinkedIn with the posting scope', function () {
    $response = $this->actingAs($this->user)->get(route('accounts.linkedin.redirect'));

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://www.linkedin.com/oauth/v2/authorization')
        ->and(urldecode($location))->toContain('scope=openid profile email w_member_social')
        ->and(urldecode($location))->toContain('client_id=test-client-id');
    expect(session(LinkedInConnectController::SESSION_WORKSPACE_KEY))->toBe($this->workspace->id);
});

it('saves the profile into the workspace it was started from', function () {
    $otherWorkspace = Workspace::factory()->for($this->user)->create();
    fakeLinkedInCallback(fakeLinkedInUser());

    $this->actingAs($this->user)
        ->withSession([LinkedInConnectController::SESSION_WORKSPACE_KEY => $otherWorkspace->id])
        ->get(route('accounts.linkedin.callback', ['code' => 'abc', 'state' => 'xyz']))
        ->assertRedirect(route('accounts.index'));

    $account = SocialAccount::query()->sole();

    expect($account->workspace_id)->toBe($otherWorkspace->id)
        ->and($account->provider)->toBe(SocialProvider::LinkedIn)
        ->and($account->account_type)->toBe(SocialAccountType::Profile)
        ->and($account->provider_account_id)->toBe('linkedin-person-1')
        ->and($account->name)->toBe('Muhammad Waris')
        ->and($account->avatar_url)->toBe('https://media.licdn.com/avatar.jpg')
        ->and($account->access_token)->toBe('linkedin-access-token')
        ->and($account->scopes)->toBe(LinkedInConnectService::SCOPES)
        ->and($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)))->toBeTrue();
});

it('updates the same profile when it is connected again', function () {
    fakeLinkedInCallback(fakeLinkedInUser(token: 'first-token'), fakeLinkedInUser(token: 'second-token'));

    $this->actingAs($this->user)->get(route('accounts.linkedin.callback', ['code' => 'a']));
    $this->actingAs($this->user)->get(route('accounts.linkedin.callback', ['code' => 'b']));

    expect(SocialAccount::query()->count())->toBe(1)
        ->and(SocialAccount::query()->sole()->access_token)->toBe('second-token');
});

it('saves nothing when the owner cancels on LinkedIn', function () {
    $this->actingAs($this->user)
        ->get(route('accounts.linkedin.callback', ['error' => 'user_cancelled_authorize']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'LinkedIn connection was cancelled.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('accepts the comma-separated scopes LinkedIn really returns', function () {
    fakeLinkedInCallback(fakeLinkedInUser(approvedScopes: ['email,openid,profile,w_member_social']));

    $this->actingAs($this->user)
        ->get(route('accounts.linkedin.callback', ['code' => 'abc']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Muhammad Waris is connected.');

    expect(SocialAccount::query()->sole()->scopes)->toBe(['email', 'openid', 'profile', 'w_member_social']);
});

it('rejects comma-separated scopes without the posting permission', function () {
    fakeLinkedInCallback(fakeLinkedInUser(approvedScopes: ['email,openid,profile']));

    $this->actingAs($this->user)->get(route('accounts.linkedin.callback', ['code' => 'abc']));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('rejects a connection without the posting permission', function () {
    fakeLinkedInCallback(fakeLinkedInUser(approvedScopes: ['openid', 'profile', 'email']));

    $this->actingAs($this->user)
        ->get(route('accounts.linkedin.callback', ['code' => 'abc']))
        ->assertRedirect(route('accounts.index'));

    expect(SocialAccount::query()->count())->toBe(0);
});

it('asks to connect again when the LinkedIn login state expired', function () {
    fakeLinkedInCallback(new InvalidStateException);

    $this->actingAs($this->user)
        ->get(route('accounts.linkedin.callback', ['code' => 'abc']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'The LinkedIn login expired. Please connect again.');

    expect(SocialAccount::query()->count())->toBe(0);
});

it('keeps LinkedIn disabled until the keys are set', function () {
    config(['services.linkedin-openid.client_id' => null]);

    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.value', 'linkedin')
            ->where('providers.0.is_available', false)
            ->where('providers.0.connect_url', null));

    $this->actingAs($this->user)
        ->get(route('accounts.linkedin.redirect'))
        ->assertRedirect(route('accounts.index'));
});

it('shows the connect link once the keys are set', function () {
    $this->actingAs($this->user)->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.is_available', true)
            ->where('providers.0.connect_url', route('accounts.linkedin.redirect')));
});

it('sends guests to the login page', function () {
    $this->get(route('accounts.linkedin.redirect'))->assertRedirect(route('login'));
    $this->get(route('accounts.linkedin.callback'))->assertRedirect(route('login'));
});
