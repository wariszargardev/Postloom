<?php

use App\Models\SocialAccount;
use App\Services\YouTube\YouTubeTokenException;
use App\Services\YouTube\YouTubeTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

beforeEach(function () {
    config(['services.google.client_id' => 'google-client-id', 'services.google.client_secret' => 'google-client-secret']);

    $this->tokens = app(YouTubeTokenService::class);
    $this->channel = SocialAccount::factory()->youTube()->create([
        'name' => 'Waris Codes',
        'access_token' => 'old-access-token',
        'refresh_token' => 'the-refresh-token',
        'token_expires_at' => now()->subMinute(),
    ]);
});

it('refreshes an expired access token and saves it', function () {
    Http::fake([GOOGLE_TOKEN_URL => Http::response(['access_token' => 'new-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);

    expect($this->tokens->accessTokenFor($this->channel))->toBe('new-access-token');

    $channel = $this->channel->fresh();
    expect($channel->access_token)->toBe('new-access-token')
        ->and($channel->refresh_token)->toBe('the-refresh-token')
        ->and($channel->token_expires_at->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === GOOGLE_TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'the-refresh-token'
        && $request['client_id'] === 'google-client-id');
});

it('reuses a token that is still valid', function () {
    Http::fake();
    $this->channel->update(['token_expires_at' => now()->addMinutes(30), 'access_token' => 'still-good']);

    expect($this->tokens->accessTokenFor($this->channel))->toBe('still-good');
    Http::assertNothingSent();
});

it('refreshes a token that expires within 5 minutes', function () {
    Http::fake([GOOGLE_TOKEN_URL => Http::response(['access_token' => 'fresh', 'expires_in' => 3599])]);
    $this->channel->update(['token_expires_at' => now()->addMinutes(3)]);

    expect($this->tokens->accessTokenFor($this->channel))->toBe('fresh');
});

it('marks the channel for reconnect when Google revoked the refresh token', function () {
    Http::fake([GOOGLE_TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

    expect(fn () => $this->tokens->refresh($this->channel))
        ->toThrow(fn (YouTubeTokenException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('Reconnect it'));

    expect($this->channel->fresh()->needs_reconnect)->toBeTrue();
});

it('treats a Google server error as temporary and keeps the channel connected', function () {
    Http::fake([GOOGLE_TOKEN_URL => Http::response(['error' => 'backend_error'], 503)]);

    expect(fn () => $this->tokens->refresh($this->channel))
        ->toThrow(fn (YouTubeTokenException $exception) => expect($exception->isRetryable)->toBeTrue());

    expect($this->channel->fresh()->needs_reconnect)->toBeFalse();
});

it('does not blame the channel when the app keys are wrong', function () {
    Http::fake([GOOGLE_TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn () => $this->tokens->refresh($this->channel))
        ->toThrow(fn (YouTubeTokenException $exception) => expect($exception->isRetryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('GOOGLE_CLIENT_ID'));

    expect($this->channel->fresh()->needs_reconnect)->toBeFalse();
});

it('never flags a channel as expiring just because its hourly token expired', function () {
    expect($this->channel->status()->value)->toBe('connected')
        ->and(SocialAccount::query()->needingAttention()->count())->toBe(0);
});

it('checks every channel daily and marks the revoked ones for reconnect', function () {
    $revoked = SocialAccount::factory()->youTube()->create(['refresh_token' => 'revoked-token']);
    $linkedIn = SocialAccount::factory()->create();
    Http::fake([GOOGLE_TOKEN_URL => function (Request $request) {
        return $request['refresh_token'] === 'revoked-token'
            ? Http::response(['error' => 'invalid_grant'], 400)
            : Http::response(['access_token' => 'renewed', 'expires_in' => 3599]);
    }]);

    $this->artisan('accounts:check-tokens')
        ->expectsOutput('Marked for reconnect: 1. Expiring soon: 0.')
        ->assertSuccessful();

    expect($revoked->fresh()->needs_reconnect)->toBeTrue()
        ->and($this->channel->fresh()->access_token)->toBe('renewed')
        ->and($this->channel->fresh()->needs_reconnect)->toBeFalse()
        ->and($linkedIn->fresh()->needs_reconnect)->toBeFalse();
    Http::assertSentCount(2);
});
