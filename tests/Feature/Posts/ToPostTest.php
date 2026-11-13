<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
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

    $this->profile = SocialAccount::factory()->for($this->workspace)->tikTok()->create(['name' => 'FruitpurAIUrdu', 'handle' => 'fruitpuraiurdu']);
    $this->post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Published, 'title' => 'Kela kaala kyun', 'content' => 'Kela kaala kyun ho jaata hai? #banana', 'scheduled_at' => now()]);
    $this->target = PostTarget::factory()->for($this->post)->for($this->profile)->create(['status' => PostTargetStatus::ReadyToPost, 'settings' => ['mode' => 'manual']]);
    $this->video = Media::factory()->video()->for($this->workspace)->create(['original_name' => 'clip.mp4']);
    Storage::disk('local')->put($this->video->path, 'video-bytes');
    $this->post->media()->attach($this->video, ['sort_order' => 0]);
});

it('lists the posts waiting for you with caption and download link', function () {
    $other = Post::factory()->for(Workspace::factory()->create())->create();
    PostTarget::factory()->for($other)->create(['status' => PostTargetStatus::ReadyToPost]);

    $this->actingAs($this->user)->get(route('to-post.index'))
        ->assertInertia(fn ($page) => $page
            ->component('to-post/Index')
            ->has('items', 1)
            ->where('items.0.id', $this->target->id)
            ->where('items.0.caption', 'Kela kaala kyun ho jaata hai? #banana')
            ->where('items.0.account.handle', 'fruitpuraiurdu')
            ->has('items.0.files', 1)
            ->where('items.0.files.0.download_url', route('to-post.media', [$this->target, $this->video]))
            ->where('items.0.destination.label', 'TikTok Studio')
            ->where('postsWaitingForYou', 1));
});

it('downloads the video with a clean file name', function () {
    $response = $this->actingAs($this->user)->get(route('to-post.media', [$this->target, $this->video]));

    $response->assertOk()->assertDownload('kela-kaala-kyun.mp4');
    expect($response->streamedContent())->toBe('video-bytes');
});

it('blocks another owner from the download and from marking it posted', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('to-post.media', [$this->target, $this->video]))->assertForbidden();
    $this->actingAs($stranger)->post(route('to-post.posted', $this->target))->assertForbidden();

    expect($this->target->fresh()->status)->toBe(PostTargetStatus::ReadyToPost);
});

it('marks it as posted with the TikTok link', function () {
    $this->actingAs($this->user)
        ->post(route('to-post.posted', $this->target), ['url' => 'https://www.tiktok.com/@fruitpuraiurdu/video/7312345678901234567'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Marked as posted on FruitpurAIUrdu.');

    $target = $this->target->fresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->external_url)->toBe('https://www.tiktok.com/@fruitpuraiurdu/video/7312345678901234567')
        ->and($target->published_at)->not->toBeNull()
        ->and($target->last_error)->toBeNull();
});

it('marks it as posted without a link and refuses links that are not TikTok', function () {
    $this->actingAs($this->user)->post(route('to-post.posted', $this->target), ['url' => 'https://example.com/video'])
        ->assertSessionHasErrors('url');
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::ReadyToPost);

    $this->actingAs($this->user)->post(route('to-post.posted', $this->target), ['url' => ''])->assertSessionHasNoErrors();
    expect($this->target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($this->target->fresh()->external_url)->toBeNull();
});

it('refuses to mark an account that is not waiting', function () {
    $this->target->update(['status' => PostTargetStatus::Published]);

    $this->actingAs($this->user)->post(route('to-post.posted', $this->target))->assertStatus(409);
});

it('does not download media that belongs to another post', function () {
    $foreign = Media::factory()->video()->for($this->workspace)->create();

    $this->actingAs($this->user)->get(route('to-post.media', [$this->target, $foreign]))->assertNotFound();
});

it('hands Instagram photos to you with numbered downloads and an Instagram link check', function () {
    $instagram = SocialAccount::factory()->for($this->workspace)->instagram()->create(['name' => 'Build Real Code', 'handle' => 'buildrealcode']);
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Published, 'title' => 'Desk setup', 'scheduled_at' => now()]);
    $target = PostTarget::factory()->for($post)->for($instagram)->create(['status' => PostTargetStatus::ReadyToPost, 'last_error' => 'Ready for you: the Cloudflare tunnel was not running.']);
    $first = Media::factory()->for($this->workspace)->create(['original_name' => 'one.jpg']);
    $second = Media::factory()->for($this->workspace)->create(['original_name' => 'two.png', 'mime_type' => 'image/png']);
    Storage::disk('local')->put($second->path, 'png-bytes');
    $post->media()->attach($first, ['sort_order' => 0]);
    $post->media()->attach($second, ['sort_order' => 1]);

    $this->actingAs($this->user)->get(route('to-post.index'))
        ->assertInertia(fn ($page) => $page
            ->where('items.1.id', $target->id)
            ->has('items.1.files', 2)
            ->where('items.1.files.0.kind', 'image')
            ->where('items.1.destination.url', 'https://www.instagram.com/')
            ->where('items.1.notice', 'Ready for you: the Cloudflare tunnel was not running.'));

    $this->actingAs($this->user)->get(route('to-post.media', [$target, $second]))->assertDownload('desk-setup-2.png');

    $this->actingAs($this->user)->post(route('to-post.posted', $target), ['url' => 'https://www.tiktok.com/@x/video/1'])
        ->assertSessionHasErrors(['url' => 'Paste the Instagram link (https://www.instagram.com/p/...), or leave it empty.']);
    $this->actingAs($this->user)->post(route('to-post.posted', $target), ['url' => 'https://www.instagram.com/p/DAbc123/'])
        ->assertSessionHasNoErrors();

    expect($target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->fresh()->external_url)->toBe('https://www.instagram.com/p/DAbc123/');
});
