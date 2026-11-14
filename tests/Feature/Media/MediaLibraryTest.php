<?php

use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $binary = is_array($command) ? $command[0] : $command;

        if ($binary === 'ffmpeg') {
            file_put_contents(end($command), 'fake-jpeg');

            return Process::result();
        }

        return Process::result(json_encode([
            'format' => ['duration' => '42.5'],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
        ]));
    });

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

function uploadMedia(array $files): TestResponse
{
    return test()->actingAs(test()->user)->post(route('media.store'), ['files' => $files]);
}

it('uploads an image and reads its size', function () {
    uploadMedia([UploadedFile::fake()->image('photo.jpg', 1200, 800)])->assertRedirect();

    $media = Media::query()->sole();

    expect($media->kind)->toBe(MediaKind::Image)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->original_name)->toBe('photo.jpg')
        ->and($media->width)->toBe(1200)
        ->and($media->height)->toBe(800)
        ->and($media->path)->toStartWith("media/{$this->workspace->id}/");
    Storage::disk('local')->assertExists($media->path);
});

it('brings a deleted file back when the same file is uploaded again', function () {
    $file = UploadedFile::fake()->image('again.jpg', 10, 10);
    uploadMedia([$file])->assertRedirect();
    $media = Media::query()->sole();
    $this->actingAs($this->user)->delete(route('media.destroy', $media));

    uploadMedia([$file])->assertRedirect();

    expect(Media::withTrashed()->count())->toBe(1)
        ->and($media->fresh()->trashed())->toBeFalse();
});

it('stores the file again on the deleted row when its old file is gone from disk', function () {
    $file = UploadedFile::fake()->image('gone.jpg', 10, 10);
    uploadMedia([$file])->assertRedirect();
    $media = Media::query()->sole();
    $this->actingAs($this->user)->delete(route('media.destroy', $media));
    Storage::disk('local')->delete($media->path);

    uploadMedia([$file])->assertRedirect();

    $restored = $media->fresh();
    expect(Media::withTrashed()->count())->toBe(1)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->path)->not->toBe($media->path);
    Storage::disk('local')->assertExists($restored->path);
});

it('returns to the page it came from with the uploaded ids, so the post editor keeps its state', function () {
    $existing = UploadedFile::fake()->image('same.jpg', 10, 10);
    uploadMedia([$existing])->assertRedirect();
    $existingId = Media::query()->sole()->id;

    $this->actingAs($this->user)
        ->from(route('posts.create'))
        ->post(route('media.store'), ['files' => [UploadedFile::fake()->image('new.png', 20, 20), $existing]])
        ->assertRedirect(route('posts.create'))
        ->assertSessionHas('inertia.flash_data.uploaded_media_ids', fn (array $ids) => count($ids) === 2 && $ids[1] === $existingId && $ids[0] !== $existingId);
});

it('uploads a video with its length, size and thumbnail', function () {
    uploadMedia([UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4')])->assertRedirect();

    $media = Media::query()->sole();

    expect($media->kind)->toBe(MediaKind::Video)
        ->and((float) $media->duration_seconds)->toBe(42.5)
        ->and($media->width)->toBe(1920)
        ->and($media->thumbnail_path)->not->toBeNull();
    Storage::disk('local')->assertExists($media->thumbnail_path);
});

it('uploads audio and PDF files', function () {
    uploadMedia([
        UploadedFile::fake()->createWithContent('voice.mp3', 'fake-audio-bytes'),
        UploadedFile::fake()->createWithContent('slides.pdf', 'fake-pdf-bytes'),
    ])->assertRedirect();

    expect(Media::query()->pluck('kind')->map->value->sort()->values()->all())->toBe(['audio', 'document']);
});

it('still uploads a video when ffmpeg is missing', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'ffprobe: not found'));

    uploadMedia([UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4')])->assertRedirect();

    $media = Media::query()->sole();

    expect($media->duration_seconds)->toBeNull()
        ->and($media->thumbnail_path)->toBeNull();
});

it('rejects unsupported file types', function () {
    uploadMedia([UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')])
        ->assertSessionHasErrors('files.0');

    expect(Media::query()->count())->toBe(0);
});

it('rejects a file whose content does not match its extension', function () {
    uploadMedia([UploadedFile::fake()->create('fake.mp4', 10, 'text/plain')])
        ->assertSessionHasErrors('files.0');
});

it('rejects a file over its kind limit', function () {
    config(['postloom.media.kinds.image.max_kb' => 1024]);

    uploadMedia([UploadedFile::fake()->image('big.jpg')->size(2048)])
        ->assertSessionHasErrors(['files.0' => '"big.jpg" is larger than the 1 MB limit for image files.']);
});

it('rejects more files than allowed in one upload', function () {
    $files = collect(range(1, 11))->map(fn (int $number) => UploadedFile::fake()->image("photo-{$number}.jpg"))->all();

    uploadMedia($files)->assertSessionHasErrors(['files' => 'Upload at most 10 files at a time.']);
});

it('reuses a file that is already in the library', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 600, 400);

    uploadMedia([$file])->assertRedirect();
    uploadMedia([$file])
        ->assertSessionHas('inertia.flash_data.toast.message', '0 files uploaded. 1 was already in the library.');

    expect(Media::query()->count())->toBe(1);
});

it('lists only the current workspace media and filters by kind', function () {
    Media::factory()->for($this->workspace)->create(['original_name' => 'mine.jpg']);
    Media::factory()->for($this->workspace)->video()->create(['original_name' => 'mine.mp4']);
    Media::factory()->create(['original_name' => 'someone-else.jpg']);

    $this->actingAs($this->user)->get(route('media.index'))
        ->assertInertia(fn ($page) => $page
            ->component('media/Index')
            ->has('media.data', 2)
            ->where('kinds.0.count', 1)
            ->where('kinds.1.count', 1));

    $this->actingAs($this->user)->get(route('media.index', ['kind' => 'video']))
        ->assertInertia(fn ($page) => $page
            ->has('media.data', 1)
            ->where('media.data.0.original_name', 'mine.mp4')
            ->where('filters.kind', 'video'));
});

it('serves the file only to its owner', function () {
    uploadMedia([UploadedFile::fake()->image('photo.jpg')]);
    $media = Media::query()->sole();

    $this->actingAs($this->user)->get(route('media.show', $media))->assertOk();
    $this->actingAs($this->user)->get(route('media.thumbnail', $media))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('media.show', $media))->assertForbidden();
});

it('sends guests to the login page', function () {
    $media = Media::factory()->for($this->workspace)->create();

    auth()->logout();

    $this->get(route('media.show', $media))->assertRedirect(route('login'));
    $this->get(route('media.index'))->assertRedirect(route('login'));
});

it('saves alt text', function () {
    $media = Media::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->patch(route('media.update', $media), ['alt_text' => 'Waris speaking at a meetup'])
        ->assertRedirect();

    expect($media->fresh()->alt_text)->toBe('Waris speaking at a meetup');
});

it('soft deletes the row and keeps the file and thumbnail so it can come back', function () {
    uploadMedia([UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4')]);
    $media = Media::query()->sole();

    $this->actingAs($this->user)->delete(route('media.destroy', $media))->assertRedirect();

    $this->assertSoftDeleted($media);
    Storage::disk('local')->assertExists($media->path);
    Storage::disk('local')->assertExists($media->thumbnail_path);
});

it('keeps a file that a failed post can still retry with', function (PostStatus $postStatus) {
    $media = Media::factory()->for($this->workspace)->create();
    $post = Post::factory()->for($this->workspace)->create(['status' => $postStatus]);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Failed]);
    $post->media()->attach($media, ['sort_order' => 0]);

    $this->actingAs($this->user)->delete(route('media.destroy', $media))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This file is used by 1 failed post that can still be retried. Retry or delete that post first.');

    $this->assertModelExists($media);
})->with([
    'failed' => PostStatus::Failed,
    'partly published' => PostStatus::PartlyPublished,
]);

it('lets a file go once its post has nothing left to retry', function () {
    uploadMedia([UploadedFile::fake()->image('card.png')]);
    $media = Media::query()->sole();
    $post = Post::factory()->for($this->workspace)->create(['status' => PostStatus::Published]);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published]);
    $post->media()->attach($media, ['sort_order' => 0]);

    $this->actingAs($this->user)->delete(route('media.destroy', $media))->assertRedirect();

    $this->assertSoftDeleted($media);
});

it('blocks changes to another owner\'s media', function () {
    $media = Media::factory()->create();

    $this->actingAs($this->user)->patch(route('media.update', $media), ['alt_text' => 'x'])->assertForbidden();
    $this->actingAs($this->user)->delete(route('media.destroy', $media))->assertForbidden();

    $this->assertModelExists($media);
});
