<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictTunnelRequests
{
    /**
     * The only paths a request through the public tunnel may reach.
     *
     * @var array<int, string>
     */
    public const TUNNEL_PATHS = ['public-media/*', 'up'];

    /**
     * Requests from the public tunnel (Cloudflare adds CF-Ray, or the host isn't local) only reach the signed media
     * route and the health check; everything else answers 404, so the app itself stays private.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isFromTunnel($request) && ! $request->is(...self::TUNNEL_PATHS)) {
            return response('Not found', 404);
        }

        return $next($request);
    }

    private function isFromTunnel(Request $request): bool
    {
        if ($request->headers->has('CF-Ray') || $request->headers->has('CF-Connecting-IP')) {
            return true;
        }

        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', $appHost], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost');
    }
}
