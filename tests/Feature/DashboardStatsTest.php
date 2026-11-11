<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 08:00:00');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

it('counts scheduled, published, failed posts and accounts needing attention for the current workspace only', function () {
    $make = fn (PostStatus $status, ?CarbonInterface $at, ?Workspace $workspace = null) => Post::factory()
        ->for($workspace ?? $this->workspace)
        ->create(['status' => $status, 'scheduled_at' => $at]);

    $make(PostStatus::Scheduled, now()->addDays(2));
    $make(PostStatus::Scheduled, now()->addDays(10));
    $make(PostStatus::Published, now()->subDays(3));
    $make(PostStatus::PartlyPublished, now()->subDays(5));
    $make(PostStatus::Failed, now()->subDay());
    $make(PostStatus::Published, now()->subDays(40));
    $make(PostStatus::Scheduled, now()->addDay(), Workspace::factory()->for($this->user)->create());
    SocialAccount::factory()->for($this->workspace)->needsReconnect()->create();
    SocialAccount::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.scheduled_this_week', 1)
            ->where('stats.published_last_30_days', 2)
            ->where('stats.failed', 2)
            ->where('stats.accounts_needing_attention', 1));
});

it('lists the next scheduled posts in time order with their platforms', function () {
    $later = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(2), 'content' => 'Later post']);
    $sooner = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addHour(), 'content' => 'Sooner post']);
    PostTarget::factory()->for($sooner)->for(SocialAccount::factory()->for($this->workspace)->youTube()->create())->create();
    Post::factory()->for($this->workspace)->create(['status' => PostStatus::Draft, 'scheduled_at' => null]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upNext', 2)
            ->where('upNext.0.id', $sooner->id)
            ->where('upNext.0.providers', ['youtube'])
            ->where('upNext.1.id', $later->id));
});
