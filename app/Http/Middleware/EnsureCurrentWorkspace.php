<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentWorkspace
{
    public function __construct(private WorkspaceService $workspaceService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->workspaceService->ensureCurrent($user);
        }

        return $next($request);
    }
}
