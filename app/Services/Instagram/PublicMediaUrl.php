<?php

namespace App\Services\Instagram;

use App\Models\Media;
use App\Services\LogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class PublicMediaUrl
{
    public const ROUTE_NAME = 'public-media.show';

    /**
     * The public address Instagram can reach: INSTAGRAM_PUBLIC_BASE_URL, or the quick tunnel address start.ps1 saved.
     */
    public function baseUrl(): ?string
    {
        $configured = trim((string) config('services.instagram.public_base_url'));

        if ($configured === '') {
            $file = (string) config('services.instagram.tunnel_url_file');
            $configured = $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';
        }

        return str_starts_with($configured, 'https://') ? rtrim($configured, '/') : null;
    }

    /**
     * True when the public address answers right now (the tunnel is running).
     */
    public function isReachable(): bool
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === null) {
            LogService::warning('No public address for Instagram media is set', []);

            return false;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get("{$baseUrl}/up");
        } catch (ConnectionException $exception) {
            LogService::warning('The public address for Instagram media could not be reached', ['base_url' => $baseUrl], $exception);

            return false;
        }

        if (! $response->successful()) {
            LogService::warning('The public address for Instagram media did not answer', ['base_url' => $baseUrl, 'status_code' => $response->status()]);

            return false;
        }

        return true;
    }

    /**
     * A signed, short-lived public link to one media file. Call isReachable() first.
     */
    public function for(Media $media): string
    {
        $path = URL::temporarySignedRoute(
            self::ROUTE_NAME,
            now()->addMinutes((int) config('services.instagram.public_link_minutes')),
            ['media' => $media->id],
            absolute: false,
        );

        return $this->baseUrl().$path;
    }
}
