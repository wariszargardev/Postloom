<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a Personal workspace when the owner registers', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->get(route('dashboard'))->assertOk();

    $user = User::query()->firstOrFail();

    expect($user->workspaces()->pluck('name')->all())->toBe([WorkspaceService::DEFAULT_NAME])
        ->and($user->current_workspace_id)->toBe($user->workspaces()->value('id'));
});

it('creates a Personal workspace for an existing owner on the first visit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('workspaces.list.0.name', WorkspaceService::DEFAULT_NAME)
            ->where('workspaces.current_id', $user->fresh()->current_workspace_id));
});

it('creates a workspace and switches to it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Build Real Code'])
        ->assertRedirect(route('workspaces.index'));

    $workspace = $user->workspaces()->where('name', 'Build Real Code')->firstOrFail();

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

it('rejects a duplicate workspace name for the same owner', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user)->create(['name' => 'Personal']);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Personal'])
        ->assertSessionHasErrors(['name' => 'You already have a workspace with this name.']);
});

it('renames a workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)
        ->patch(route('workspaces.update', $workspace), ['name' => 'New name'])
        ->assertRedirect(route('workspaces.index'));

    expect($workspace->fresh()->name)->toBe('New name');
});

it('switches the current workspace', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->for($user)->create();
    $second = Workspace::factory()->for($user)->create();
    $user->forceFill(['current_workspace_id' => $first->id])->save();

    $this->actingAs($user)->post(route('workspaces.switch', $second));

    expect($user->fresh()->current_workspace_id)->toBe($second->id);
});

it('deletes an empty workspace and moves to another one', function () {
    $user = User::factory()->create();
    $keep = Workspace::factory()->for($user)->create();
    $remove = Workspace::factory()->for($user)->create();
    $user->forceFill(['current_workspace_id' => $remove->id])->save();

    $this->actingAs($user)
        ->delete(route('workspaces.destroy', $remove))
        ->assertRedirect(route('workspaces.index'));

    $this->assertModelMissing($remove);
    expect($user->fresh()->current_workspace_id)->toBe($keep->id);
});

it('does not delete a workspace that still has accounts', function () {
    $user = User::factory()->create();
    Workspace::factory()->for($user)->create();
    $withAccount = Workspace::factory()->for($user)->create();
    SocialAccount::factory()->for($withAccount)->create();

    $this->actingAs($user)->delete(route('workspaces.destroy', $withAccount));

    $this->assertModelExists($withAccount);
});

it('does not delete the last workspace', function () {
    $user = User::factory()->create();
    $only = Workspace::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('workspaces.destroy', $only));

    $this->assertModelExists($only);
});

it('blocks changes to another owner\'s workspace', function () {
    $user = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create();

    $this->actingAs($user)->patch(route('workspaces.update', $otherWorkspace), ['name' => 'Hacked'])->assertForbidden();
    $this->actingAs($user)->post(route('workspaces.switch', $otherWorkspace))->assertForbidden();
    $this->actingAs($user)->delete(route('workspaces.destroy', $otherWorkspace))->assertForbidden();
});

it('lists workspaces with their account count and delete reason', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user)->create(['name' => 'Personal']);
    SocialAccount::factory()->for($workspace)->count(2)->create();

    $this->actingAs($user)->get(route('workspaces.index'))
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Index')
            ->where('workspaces.0.accounts_count', 2)
            ->where('workspaces.0.delete_block_reason', 'Disconnect its accounts before deleting this workspace.'));
});
