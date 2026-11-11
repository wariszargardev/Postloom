<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\Rules\FacebookPostRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 08:00:00');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->page = SocialAccount::factory()->for($this->workspace)->create(['provider' => SocialProvider::Facebook, 'account_type' => SocialAccountType::Page, 'token_expires_at' => null]);
    $this->rules = app(FacebookPostRules::class);
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<int, int>  $mediaIds
 */
function scheduleFacebook(array $settings, array $mediaIds, string $content = 'Hello'): TestResponse
{
    return test()->actingAs(test()->user)->post(route('posts.store'), [
        'intent' => 'schedule',
        'title' => null,
        'content' => $content,
        'timezone' => 'Asia/Karachi',
        'scheduled_at' => '2026-11-12T09:30',
        'targets' => [['social_account_id' => test()->page->id, 'content_override' => null, 'settings' => [...test()->rules->defaultSettings(), ...$settings]]],
        'media_ids' => $mediaIds,
    ]);
}

function facebookError(): string
{
    return (string) session('errors')?->first('targets.'.test()->page->id);
}

it('schedules a text-only post and a photo post', function () {
    scheduleFacebook([], [])->assertSessionHasNoErrors();
    $photo = Media::factory()->for($this->workspace)->create(['mime_type' => 'image/jpeg']);
    scheduleFacebook([], [$photo->id])->assertSessionHasNoErrors();

    expect(Post::query()->where('status', PostStatus::Scheduled)->count())->toBe(2);
});

it('refuses PDFs, mixed media, too many photos, WebP and big photos', function (string $case, string $message) {
    $ids = match ($case) {
        'pdf' => [Media::factory()->document()->for($this->workspace)->create()->id],
        'mixed' => [Media::factory()->for($this->workspace)->create(['mime_type' => 'image/png'])->id, Media::factory()->video()->for($this->workspace)->create()->id],
        'eleven' => Media::factory()->count(11)->for($this->workspace)->create(['mime_type' => 'image/png'])->pluck('id')->all(),
        'webp' => [Media::factory()->for($this->workspace)->create(['mime_type' => 'image/webp', 'original_name' => 'card.webp'])->id],
        'big' => [Media::factory()->for($this->workspace)->create(['mime_type' => 'image/png', 'size_bytes' => 12 * 1024 * 1024])->id],
    };

    scheduleFacebook([], $ids)->assertSessionHasErrors("targets.{$this->page->id}");

    expect(facebookError())->toContain($message);
})->with([
    'pdf' => ['pdf', 'can\'t post PDFs'],
    'mixed' => ['mixed', 'can\'t mix media'],
    'too many photos' => ['eleven', 'up to 10 photos'],
    'webp' => ['webp', 'Use JPG, PNG or GIF'],
    'over 10 MB' => ['big', '10 MB'],
]);

it('checks Reel format, length and orientation', function () {
    $landscape = Media::factory()->video()->for($this->workspace)->create(['width' => 1920, 'height' => 1080, 'duration_seconds' => '30.00']);
    scheduleFacebook(['format' => 'reel'], [$landscape->id])->assertSessionHasErrors();
    expect(facebookError())->toContain('vertical 9:16');

    $long = Media::factory()->video()->for($this->workspace)->create(['width' => 1080, 'height' => 1920, 'duration_seconds' => '120.00']);
    scheduleFacebook(['format' => 'reel'], [$long->id])->assertSessionHasErrors();
    expect(facebookError())->toContain('3 to 90 seconds');

    scheduleFacebook(['format' => 'reel'], [])->assertSessionHasErrors();
    expect(facebookError())->toContain('needs one video');

    $good = Media::factory()->video()->for($this->workspace)->create(['width' => 1080, 'height' => 1920, 'duration_seconds' => '45.00']);
    scheduleFacebook(['format' => 'reel'], [$good->id])->assertSessionHasNoErrors();
});

it('shows the Facebook settings in the editor', function () {
    $this->actingAs($this->user)->get(route('posts.create'))
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.provider', 'facebook')
            ->where('accounts.0.rules.settings.format.options.reel', 'Reel (vertical video, 3–90 s)')
            ->where('accounts.0.rules.max_text_length', 63206));
});

it('accepts audio as a regular video post', function () {
    $audio = Media::factory()->for($this->workspace)->create(['kind' => MediaKind::Audio, 'mime_type' => 'audio/mpeg']);

    scheduleFacebook([], [$audio->id])->assertSessionHasNoErrors();
});
