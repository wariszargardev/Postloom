<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('marks expired accounts for reconnect and leaves the others alone', function () {
    $expired = SocialAccount::factory()->expired()->create();
    $expiringSoon = SocialAccount::factory()->expiringSoon()->create();
    $valid = SocialAccount::factory()->create();

    $this->artisan('accounts:check-tokens')
        ->expectsOutput('Marked for reconnect: 1. Expiring soon: 1.')
        ->assertSuccessful();

    expect($expired->fresh()->needs_reconnect)->toBeTrue()
        ->and($expiringSoon->fresh()->needs_reconnect)->toBeFalse()
        ->and($valid->fresh()->needs_reconnect)->toBeFalse();
});

it('is scheduled every day', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('accounts:check-tokens')
        ->assertSuccessful();
});

it('shares how many of the owner\'s accounts need attention', function () {
    $user = User::factory()->create();
    $personal = Workspace::factory()->for($user)->create();
    $brand = Workspace::factory()->for($user)->create();
    $user->forceFill(['current_workspace_id' => $personal->id])->save();

    SocialAccount::factory()->for($personal)->expiringSoon()->create();
    SocialAccount::factory()->for($brand)->needsReconnect()->create();
    SocialAccount::factory()->for($personal)->create();
    SocialAccount::factory()->expired()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('accountsNeedingAttention', 2));
});
