<?php

use App\Models\SocialAccount;
use App\Services\TikTok\TikTokTokenException;
use App\Services\TikTok\TikTokTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const TIKTOK_REFRESH_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

beforeEach(function () {
    config(['services.tiktok.client_key' => 'tiktok-client-key', 'services.tiktok.client_secret' => 'tiktok-client-secret']);

    $this->tokens = app(TikTokTokenService::class);
    $this->profile = SocialAccount::factory()->tikTok()->create([
        'name' => 'Build Real Code',
        'access_token' => 'act.old',
        'refresh_token' => 'rft.old',
        'token_expires_at' => now()->subMinute(),
    ]);
});

it('refreshes an expired token and saves the new refresh token too', function () {
    Http::fake([TIKTOK_REFRESH_URL => Http::response(['access_token' => 'act.new', 'refresh_token' => 'rft.new', 'expires_in' => 7200, 'refresh_expires_in' => 15552000])]);

    expect($this->tokens->accessTokenFor($this->profile))->toBe('act.new');

    $profile = $this->profile->fresh();
    expect($profile->access_token)->toBe('act.new')
        ->and($profile->refresh_token)->toBe('rft.new')
        ->and($profile->token_expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === TIKTOK_REFRESH_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'rft.old'
        && $request['client_key'] === 'tiktok-client-key');
});

it('uses the token another process just refreshed instead of spending the old refresh token', function () {
    Http::fake();
    SocialAccount::query()->findOrFail($this->profile->id)->update([
        'access_token' => 'act.from-other-process',
        'refresh_token' => 'rft.from-other-process',
        'token_expires_at' => now()->addHours(2),
    ]);

    expect($this->tokens->accessTokenFor($this->profile))->toBe('act.from-other-process');

    Http::assertNothingSent();
    expect($this->profile->fresh()->needs_reconnect)->toBeFalse();
});

it('reuses a token that is still valid', function () {
    Http::fake();
    $this->profile->update(['token_expires_at' => now()->addHour(), 'access_token' => 'act.still-good']);

    expect($this->tokens->accessTokenFor($this->profile))->toBe('act.still-good');
    Http::assertNothingSent();
});

it('marks the profile for reconnect when TikTok rejects the refresh token', function () {
    Http::fake([TIKTOK_REFRESH_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'], 400)]);

    expect(fn () => $this->tokens->refresh($this->profile))
        ->toThrow(fn (TikTokTokenException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('Reconnect it'));

    expect($this->profile->fresh()->needs_reconnect)->toBeTrue();
});

it('treats a TikTok server error as temporary and keeps the profile connected', function () {
    Http::fake([TIKTOK_REFRESH_URL => Http::response(['error' => 'internal_error'], 503)]);

    expect(fn () => $this->tokens->refresh($this->profile))
        ->toThrow(fn (TikTokTokenException $exception) => expect($exception->isRetryable)->toBeTrue());

    expect($this->profile->fresh()->needs_reconnect)->toBeFalse();
});

it('refreshes TikTok tokens in the daily check so the refresh token never runs out', function () {
    Http::fake([TIKTOK_REFRESH_URL => Http::response(['access_token' => 'act.daily', 'refresh_token' => 'rft.daily', 'expires_in' => 7200])]);
    $this->profile->update(['token_expires_at' => now()->addHour()]);

    $this->artisan('accounts:check-tokens')->assertSuccessful();

    expect($this->profile->fresh()->refresh_token)->toBe('rft.daily')
        ->and($this->profile->fresh()->needs_reconnect)->toBeFalse();
});

it('counts a dead TikTok login in the daily check', function () {
    Http::fake([TIKTOK_REFRESH_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('accounts:check-tokens')
        ->expectsOutputToContain('Marked for reconnect: 1.')
        ->assertSuccessful();

    expect($this->profile->fresh()->needs_reconnect)->toBeTrue();
});
