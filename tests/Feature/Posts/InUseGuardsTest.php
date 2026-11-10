<?php

use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

it('blocks disconnecting an account that has a scheduled post', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create();
    PostTarget::factory()->for(Post::factory()->for($this->workspace)->scheduled())->for($account)->create();

    $this->actingAs($this->user)
        ->delete(route('accounts.destroy', $account))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This account has 1 scheduled post. Move it to drafts or remove the account from it first.');

    $this->assertModelExists($account);
});

it('disconnects an account used only in drafts and removes it from them', function () {
    $account = SocialAccount::factory()->for($this->workspace)->create();
    $target = PostTarget::factory()->for(Post::factory()->for($this->workspace))->for($account)->create();

    $this->actingAs($this->user)->delete(route('accounts.destroy', $account))->assertRedirect(route('accounts.index'));

    $this->assertModelMissing($account);
    $this->assertModelMissing($target);
});

it('blocks deleting media used by a scheduled post', function () {
    $media = Media::factory()->for($this->workspace)->create();
    Post::factory()->for($this->workspace)->scheduled()->create()->media()->attach($media, ['sort_order' => 0]);

    $this->actingAs($this->user)
        ->delete(route('media.destroy', $media))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This file is used by 1 scheduled post. Remove it from the post or move the post to drafts first.');

    $this->assertModelExists($media);
});

it('deletes media used only in drafts and removes it from them', function () {
    $media = Media::factory()->for($this->workspace)->create();
    $draft = Post::factory()->for($this->workspace)->create();
    $draft->media()->attach($media, ['sort_order' => 0]);

    $this->actingAs($this->user)->delete(route('media.destroy', $media))->assertRedirect();

    $this->assertModelMissing($media);
    expect($draft->media()->count())->toBe(0);
});
