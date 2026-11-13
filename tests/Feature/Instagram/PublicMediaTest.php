<?php

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Instagram\PublicMediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const IGM_TUNNEL = 'https://quiet-river.trycloudflare.com';

beforeEach(function () {
    Storage::fake('local');
    config(['services.instagram.public_base_url' => IGM_TUNNEL, 'services.instagram.public_link_minutes' => 15]);
    $this->workspace = Workspace::factory()->create();
    $this->links = app(PublicMediaUrl::class);
});

function publicPathOf(string $url): string
{
    return substr($url, strlen(IGM_TUNNEL));
}

it('serves a JPEG photo through a signed link without logging in', function () {
    $photo = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($photo->path, 'jpeg-bytes');

    $response = $this->get(publicPathOf($this->links->for($photo)));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('jpeg-bytes');
});

it('turns a PNG into a JPEG for Instagram', function () {
    $png = UploadedFile::fake()->image('cover.png', 40, 40);
    $photo = Media::factory()->for($this->workspace)->create(['mime_type' => 'image/png', 'path' => 'media/test/cover.png', 'original_name' => 'cover.png']);
    Storage::disk('local')->put($photo->path, (string) file_get_contents($png->getPathname()));

    $response = $this->get(publicPathOf($this->links->for($photo)));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(substr((string) $response->getContent(), 0, 3))->toBe("\xFF\xD8\xFF");
});

it('refuses links that are unsigned, tampered with or expired', function () {
    $photo = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($photo->path, 'jpeg-bytes');
    $path = publicPathOf($this->links->for($photo));

    $this->get("/public-media/{$photo->id}")->assertForbidden();
    $other = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($other->path, 'other-bytes');
    $this->get(str_replace("/public-media/{$photo->id}?", "/public-media/{$other->id}?", $path))->assertForbidden();

    $this->travel(16)->minutes();
    $this->get($path)->assertForbidden();
});

it('never serves PDFs or audio through the public link', function () {
    $pdf = Media::factory()->document()->for($this->workspace)->create();
    Storage::disk('local')->put($pdf->path, 'pdf');

    $this->get(publicPathOf($this->links->for($pdf)))->assertNotFound();
});

it('only lets tunnel requests reach the media link and the health check', function () {
    $user = User::factory()->create();
    $photo = Media::factory()->for($this->workspace)->create();
    Storage::disk('local')->put($photo->path, 'jpeg-bytes');
    $tunnel = ['CF-Ray' => '8f1c2d3e4f-KHI', 'CF-Connecting-IP' => '157.240.1.1'];

    $this->withHeaders($tunnel)->get('/login')->assertNotFound();
    $this->withHeaders($tunnel)->actingAs($user)->get('/dashboard')->assertNotFound();
    $this->withHeaders($tunnel)->get('/')->assertNotFound();
    $this->withHeaders($tunnel)->get('/up')->assertOk();
    $this->withHeaders($tunnel)->get(publicPathOf($this->links->for($photo)))->assertOk();

    $this->get('http://quiet-river.trycloudflare.com/login')->assertNotFound();
    $this->flushHeaders()->get('http://localhost/dashboard')->assertOk();
});

it('reads the tunnel address from the file start.ps1 writes', function () {
    $file = storage_path('framework/testing/tunnel-url.txt');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, "https://bright-sun.trycloudflare.com\n");
    config(['services.instagram.public_base_url' => null, 'services.instagram.tunnel_url_file' => $file]);

    expect($this->links->baseUrl())->toBe('https://bright-sun.trycloudflare.com');

    unlink($file);
    expect($this->links->baseUrl())->toBeNull();
});

it('checks that the tunnel answers before using it', function () {
    Http::fake([IGM_TUNNEL.'/up' => Http::sequence()->push('ok')->push('bad gateway', 502)]);

    expect($this->links->isReachable())->toBeTrue()
        ->and($this->links->isReachable())->toBeFalse();
});
