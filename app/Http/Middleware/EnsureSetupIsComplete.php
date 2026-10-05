<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupIsComplete
{
    public function __construct(private readonly AuthenticationSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $isSetupRequest = $request->is('setup', 'setup/*');
        $isGoogleAuthTestRequest = $request->is('auth/google/test/*');
        $isGoogleCallbackRequest = $request->is('auth/google/callback');
        $isLivewireRequest = $path === 'up' || str_starts_with($path, 'livewire');

        if ($this->settings->isSetupComplete()) {
            if ($isSetupRequest) {
                return redirect()->route($request->user() ? 'live-wall.index' : 'login');
            }

            return $next($request);
        }

        if ($isSetupRequest || $isGoogleAuthTestRequest || $isGoogleCallbackRequest || $isLivewireRequest) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Initial application setup is required.');
        }

        return redirect()->route('setup.index');
    }
}
