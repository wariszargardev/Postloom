<?php

use App\Enums\PostStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\Import\CsvPostImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 10:00:00');
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user)->create();
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->youTube = SocialAccount::factory()->youTube()->for($this->workspace)->create(['name' => 'Build Real Code', 'handle' => 'brc-channel']);
    $this->facebook = SocialAccount::factory()->for($this->workspace)->create([
        'provider' => SocialProvider::Facebook,
        'account_type' => SocialAccountType::Page,
        'name' => 'Build Real Code',
        'handle' => null,
        'token_expires_at' => null,
    ]);
    $this->tikTok = SocialAccount::factory()->tikTok()->for($this->workspace)->create(['name' => 'BRC TikTok', 'handle' => 'buildrealcode']);
    $this->linkedIn = SocialAccount::factory()->for($this->workspace)->create(['name' => 'Muhammad Waris', 'handle' => 'waris']);

    $this->video = Media::factory()->video()->for($this->workspace)->create([
        'original_name' => 'day-01.mp4',
        'width' => 1080,
        'height' => 1920,
        'duration_seconds' => '42.50',
    ]);
});

/**
 * @param  array<int, array<string, string>>  $rows
 */
function importCsv(array $rows, ?array $header = null, string $delimiter = ','): string
{
    $header ??= CsvPostImporter::columns();
    $lines = [implode($delimiter, $header)];

    foreach ($rows as $row) {
        $lines[] = implode($delimiter, array_map(
            fn (string $column): string => '"'.str_replace('"', '""', $row[$column] ?? '').'"',
            $header,
        ));
    }

    return implode("\n", $lines)."\n";
}

/**
 * @return array<string, string>
 */
function dailyPackRow(array $overrides = []): array
{
    return [
        'date' => '2026-11-11',
        'time' => '18:00',
        'accounts' => 'youtube:Build Real Code;facebook:Build Real Code;tiktok:@buildrealcode',
        'name' => 'Day 1 video',
        'caption' => 'Day 1 caption #shorts',
        'media' => 'day-01.mp4',
        'facebook_format' => 'reel',
        ...$overrides,
    ];
}

function previewCsv(string $csv, array $files = []): TestResponse
{
    return test()->actingAs(test()->user)->post(route('posts.import.preview'), [
        'csv' => UploadedFile::fake()->createWithContent('pack.csv', $csv),
        'files' => $files,
    ]);
}

function previewRows(string $csv, array $files = []): array
{
    $response = previewCsv($csv, $files);
    $response->assertRedirect()->assertSessionHasNoErrors();

    return test()->actingAs(test()->user)->get($response->headers->get('Location'))
        ->assertOk()
        ->viewData('page')['props']['preview']['rows'];
}

function importedSummary(string $csv): TestResponse
{
    $location = previewCsv($csv)->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    return test()->actingAs(test()->user)->post(route('posts.import.store'), ['import_id' => $query['import']]);
}

it('downloads a template with every column and the workspace accounts', function () {
    $response = $this->actingAs($this->user)->get(route('posts.import.template'))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->getContent())->toContain(implode(',', CsvPostImporter::columns()))
        ->and($response->getContent())->toContain('youtube:Build Real Code;facebook:Build Real Code;tiktok:@buildrealcode');
});

it('previews the daily pack without saving anything', function () {
    $rows = previewRows(importCsv([dailyPackRow()]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['errors'])->toBe([])
        ->and($rows[0]['status'])->toBe('scheduled')
        ->and($rows[0]['scheduled_at'])->toBe('2026-11-11T13:00:00+00:00')
        ->and(collect($rows[0]['accounts'])->pluck('id')->all())->toEqualCanonicalizing([$this->youTube->id, $this->facebook->id, $this->tikTok->id])
        ->and($rows[0]['media'])->toBe(['day-01.mp4'])
        ->and(Post::query()->count())->toBe(0);
});

it('imports the daily pack as one scheduled post with per-platform settings', function () {
    importedSummary(importCsv([dailyPackRow()]))->assertRedirect(route('posts.index'));

    $post = Post::query()->sole();
    $settings = $post->targets->keyBy('social_account_id')->map(fn (PostTarget $target) => $target->settings);

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->title)->toBe('Day 1 video')
        ->and($post->content)->toBe('Day 1 caption #shorts')
        ->and($post->timezone)->toBe('Asia/Karachi')
        ->and($post->scheduled_at->toDateTimeString())->toBe('2026-11-11 13:00:00')
        ->and($post->media()->pluck('media.id')->all())->toBe([$this->video->id])
        ->and($settings[$this->youTube->id]['title'])->toBe('Day 1 video')
        ->and($settings[$this->facebook->id]['format'])->toBe('reel')
        ->and($settings[$this->tikTok->id]['mode'])->toBe('manual');
});

it('uses the youtube_title, youtube_privacy and tiktok_mode columns', function () {
    importedSummary(importCsv([dailyPackRow([
        'youtube_title' => 'A better title',
        'youtube_privacy' => 'Unlisted',
        'tiktok_mode' => 'inbox',
    ])]));

    $settings = Post::query()->sole()->targets->keyBy('social_account_id')->map(fn (PostTarget $target) => $target->settings);

    expect($settings[$this->youTube->id]['title'])->toBe('A better title')
        ->and($settings[$this->youTube->id]['privacy'])->toBe('unlisted')
        ->and($settings[$this->tikTok->id]['mode'])->toBe('inbox');
});

it('uses the instagram_format column and matches Instagram by @handle', function () {
    $instagram = SocialAccount::factory()->instagram()->for($this->workspace)->create(['name' => 'Build Real Code IG', 'handle' => 'brc.insta']);

    importedSummary(importCsv([dailyPackRow(['accounts' => 'instagram:@brc.insta', 'instagram_format' => 'Reel'])]));

    expect(Post::query()->sole()->targets->sole()->social_account_id)->toBe($instagram->id)
        ->and(Post::query()->sole()->targets->sole()->settings['format'])->toBe('reel');

    $rows = previewRows(importCsv([dailyPackRow(['accounts' => '@brc.insta', 'instagram_format' => 'carousel', 'time' => '19:00'])]));

    expect($rows[0]['errors'][0])->toContain('Instagram format "carousel" is not known. Use post, reel, story.');
});

it('matches accounts by name, @handle and platform prefix', function () {
    $rows = previewRows(importCsv([
        dailyPackRow(['accounts' => 'Build Real Code', 'facebook_format' => '']),
        dailyPackRow(['accounts' => '@buildrealcode', 'time' => '19:00']),
        dailyPackRow(['accounts' => 'youtube:build real code', 'time' => '20:00']),
    ]));

    expect(collect($rows[0]['accounts'])->pluck('id')->all())->toEqualCanonicalizing([$this->youTube->id, $this->facebook->id])
        ->and(collect($rows[1]['accounts'])->pluck('id')->all())->toBe([$this->tikTok->id])
        ->and(collect($rows[2]['accounts'])->pluck('id')->all())->toBe([$this->youTube->id]);
});

it('shows row-by-row errors from the CSV and from the editor rules', function () {
    $rows = previewRows(importCsv([
        dailyPackRow(['accounts' => 'Nobody']),
        dailyPackRow(['media' => 'missing.mp4']),
        dailyPackRow(['date' => '20/11/2026', 'time' => '6pm']),
        dailyPackRow(['timezone' => 'Mars/Base']),
        dailyPackRow(['tiktok_mode' => 'auto']),
        dailyPackRow(['date' => '2026-11-09']),
        dailyPackRow(['name' => '', 'time' => '21:00']),
        dailyPackRow(['accounts' => 'linkedin:Muhammad Waris', 'caption' => str_repeat('a', 3001), 'media' => '', 'time' => '22:00']),
    ]));

    expect($rows[0]['errors'])->toBe(['No account called "Nobody" in this workspace.'])
        ->and($rows[1]['errors'][0])->toContain('Media "missing.mp4" is not in the library')
        ->and($rows[2]['errors'])->toHaveCount(2)
        ->and($rows[2]['errors'][0])->toContain('Use YYYY-MM-DD')
        ->and($rows[2]['errors'][1])->toContain('24-hour HH:MM')
        ->and($rows[3]['errors'][0])->toContain('Timezone "Mars/Base" is not known')
        ->and($rows[4]['errors'][0])->toContain('Tiktok mode "auto" is not known. Use manual, direct, inbox.')
        ->and($rows[5]['errors'])->toContain('Pick a date and time in the future.')
        ->and($rows[6]['errors'][0])->toContain('Build Real Code (YouTube):')
        ->and($rows[7]['errors'][0])->toContain('the limit is 3,000');
});

it('imports draft rows without a time and skips the schedule checks', function () {
    importedSummary(importCsv([
        dailyPackRow(['status' => 'draft', 'date' => '', 'time' => '', 'name' => '', 'media' => '']),
    ]));

    $post = Post::query()->sole();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->scheduled_at)->toBeNull()
        ->and($post->targets)->toHaveCount(3);
});

it('asks for a time when a row is to be scheduled', function () {
    $rows = previewRows(importCsv([dailyPackRow(['date' => '', 'time' => ''])]));

    expect($rows[0]['errors'])->toBe(['Add a date and time, or set status to draft.']);
});

it('refuses a slot that is already taken, in the database or earlier in the file', function () {
    $existing = Post::factory()->for($this->workspace)->create([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => Carbon::parse('2026-11-12 13:00:00'),
    ]);
    PostTarget::factory()->for($existing)->create(['social_account_id' => $this->linkedIn->id]);

    $rows = previewRows(importCsv([
        dailyPackRow(['accounts' => 'linkedin:Muhammad Waris', 'date' => '2026-11-12', 'media' => '']),
        dailyPackRow(),
        dailyPackRow(['name' => 'Day 1 again']),
    ]));

    expect($rows[0]['errors'])->toBe(['Muhammad Waris (LinkedIn) already has a post at this time.'])
        ->and($rows[1]['errors'])->toBe([])
        ->and($rows[2]['errors'][0])->toBe('Build Real Code (YouTube) is already used at this time in row 3.');
});

it('imports valid rows, skips rows with errors and reports the counts', function () {
    importedSummary(importCsv([
        dailyPackRow(),
        dailyPackRow(['accounts' => 'Nobody', 'time' => '19:00']),
        dailyPackRow(['date' => '2026-11-12', 'status' => 'draft']),
    ]))->assertRedirect(route('posts.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 2 posts (1 scheduled, 1 drafts). 1 row skipped.');

    expect(Post::query()->count())->toBe(2)
        ->and(Post::query()->where('status', PostStatus::Draft)->count())->toBe(1);
});

it('refuses an unknown or expired import id', function () {
    $this->actingAs($this->user)->post(route('posts.import.store'), ['import_id' => 'not-there'])
        ->assertRedirect(route('posts.import.create'));

    expect(Post::query()->count())->toBe(0);
});

it('never matches another workspace\'s accounts or media', function () {
    $otherWorkspace = Workspace::factory()->create();
    SocialAccount::factory()->for($otherWorkspace)->create(['name' => 'Someone Else']);
    Media::factory()->video()->for($otherWorkspace)->create(['original_name' => 'theirs.mp4']);

    $rows = previewRows(importCsv([dailyPackRow(['accounts' => 'Someone Else', 'media' => 'theirs.mp4'])]));

    expect($rows[0]['accounts'])->toBe([])
        ->and($rows[0]['errors'])->toContain('No account called "Someone Else" in this workspace.')
        ->and($rows[0]['errors'][1])->toContain('Media "theirs.mp4" is not in the library');
});

it('matches media uploaded together with the CSV', function () {
    $rows = previewRows(
        importCsv([dailyPackRow(['accounts' => 'linkedin:Muhammad Waris', 'media' => 'Cover.JPG'])]),
        [UploadedFile::fake()->image('cover.jpg', 1200, 800)],
    );

    $uploaded = Media::query()->where('original_name', 'cover.jpg')->sole();

    expect($rows[0]['errors'])->toBe([])
        ->and($uploaded->workspace_id)->toBe($this->workspace->id);
});

it('reads semicolon CSVs with a byte order mark', function () {
    $rows = previewRows("\u{FEFF}".importCsv([dailyPackRow(['caption' => 'Hello, world'])], delimiter: ';'));

    expect($rows[0]['errors'])->toBe([])
        ->and($rows[0]['caption'])->toBe('Hello, world');
});

it('refuses a CSV with a missing or unknown column', function () {
    previewCsv(importCsv([dailyPackRow()], ['date', 'time', 'caption']))
        ->assertSessionHasErrors(['csv' => 'Missing column(s): accounts.']);

    previewCsv(importCsv([dailyPackRow()], ['date', 'time', 'accounts', 'caption', 'youtube_tittle']))
        ->assertSessionHasErrors('csv');
});

it('refuses more than the row limit', function () {
    previewCsv(importCsv(array_fill(0, CsvPostImporter::MAX_ROWS + 1, dailyPackRow())))
        ->assertSessionHasErrors(['csv' => 'The CSV has more than '.CsvPostImporter::MAX_ROWS.' rows. Split it into smaller files.']);
});

it('shows the import page with the column help', function () {
    $this->actingAs($this->user)->get(route('posts.import.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('posts/Import')
            ->where('preview', null)
            ->where('columns.required', CsvPostImporter::REQUIRED_COLUMNS));
});

it('sends guests to the login page', function () {
    $this->get(route('posts.import.create'))->assertRedirect(route('login'));
});
