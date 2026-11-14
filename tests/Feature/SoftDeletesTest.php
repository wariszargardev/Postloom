<?php

use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RegistrationGate;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function draftPayload(array $overrides = []): array
{
    return [
        'intent' => 'draft',
        'content' => 'Soft deletes',
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => null,
        'targets' => [],
        'media_ids' => [],
        ...$overrides,
    ];
}

it('soft deletes a workspace together with its posts, their accounts and attempts, and its media', function () {
    $other = Workspace::factory()->for($this->user)->create();
    $post = Post::factory()->for($other)->create();
    $target = PostTarget::factory()->for($post)->create();
    $attempt = PublishAttempt::factory()->for($target, 'postTarget')->create();
    $media = Media::factory()->for($other)->create();

    $this->actingAs($this->user)->delete(route('workspaces.destroy', $other))->assertRedirect(route('workspaces.index'));

    $this->assertSoftDeleted($other);
    $this->assertSoftDeleted($post);
    $this->assertSoftDeleted($target);
    $this->assertSoftDeleted($attempt);
    $this->assertSoftDeleted($media);
});

it('lets you reuse the name of a deleted workspace', function () {
    $other = Workspace::factory()->for($this->user)->create(['name' => 'Clients']);
    $this->actingAs($this->user)->delete(route('workspaces.destroy', $other));

    $this->actingAs($this->user)->post(route('workspaces.store'), ['name' => 'Clients'])->assertSessionHasNoErrors();

    expect(Workspace::query()->where('name', 'Clients')->count())->toBe(1);
});

it('soft deletes an account\'s post targets and their attempts when it is disconnected', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create();
    $target = PostTarget::factory()->for(Post::factory()->for($this->workspace))->for($account)->create();
    $attempt = PublishAttempt::factory()->for($target, 'postTarget')->create();

    $this->actingAs($this->user)->delete(route('accounts.destroy', $account))->assertRedirect(route('accounts.index'));

    $this->assertSoftDeleted($account);
    $this->assertSoftDeleted($target);
    $this->assertSoftDeleted($attempt);
});

it('brings a disconnected account back when it is connected again', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create(['provider_account_id' => 'abc123']);
    $account->delete();

    $saved = app(SocialAccountService::class)->saveConnectedAccount($this->workspace, [
        'provider' => $account->provider,
        'account_type' => SocialAccountType::Profile,
        'provider_account_id' => 'abc123',
        'name' => 'Waris',
        'access_token' => 'new-token',
    ]);

    expect($saved->id)->toBe($account->id)
        ->and($saved->trashed())->toBeFalse()
        ->and($saved->fresh()->access_token)->toBe('new-token');
});

it('restores a removed account on a post when it is added back, with a clean slate', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create(['provider' => SocialProvider::LinkedIn]);
    $post = Post::factory()->for($this->workspace)->create();
    $target = PostTarget::factory()->for($post)->for($account)->create(['status' => PostTargetStatus::Failed, 'attempts' => 3, 'last_error' => 'Old error']);

    $this->actingAs($this->user)->put(route('posts.update', $post), draftPayload())->assertSessionHasNoErrors();
    $this->assertSoftDeleted($target);

    $this->actingAs($this->user)->put(route('posts.update', $post), draftPayload([
        'targets' => [['social_account_id' => $account->id, 'settings' => ['visibility' => 'PUBLIC']]],
    ]))->assertSessionHasNoErrors();

    $restored = $post->targets()->sole();
    expect($restored->id)->toBe($target->id)
        ->and($restored->status)->toBe(PostTargetStatus::Pending)
        ->and($restored->attempts)->toBe(0)
        ->and($restored->last_error)->toBeNull();
});

it('refuses a deleted account or deleted media in a post', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create();
    $media = Media::factory()->for($this->workspace)->create();
    $account->delete();
    $media->delete();

    $this->actingAs($this->user)->post(route('posts.store'), draftPayload([
        'targets' => [['social_account_id' => $account->id]],
        'media_ids' => [$media->id],
    ]))->assertSessionHasErrors(['targets.0.social_account_id', 'media_ids.0']);
});

it('keeps registration closed after the owner deletes their account', function () {
    config(['postloom.allow_registration' => false]);
    $this->user->delete();

    expect(User::query()->count())->toBe(0)
        ->and(app(RegistrationGate::class)->isOpen())->toBeFalse();
});
