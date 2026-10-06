<?php

namespace App\Http\Middleware;

use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $settings = app(AuthenticationSettingsService::class);
            $method = $request->session()->get('auth_method');
            // Only local sign-in issues remember-me cookies. A restored cookie
            // must not inherit access from an unrelated Google login method.
            if ($method === null && Auth::viaRemember()) {
                $method = 'local';
                $request->session()->put('auth_method', $method);
            }
            $localAllowed = $settings->manualAuthEnabled() && $user->hasLocalAuth();
            $googleAllowed = $settings->googleAuthEnabled()
                && filled($user->google_id)
                && AllowedLoginEmail::isAllowed($user->email);
            $allowed = match ($method) {
                'local' => $localAllowed,
                'google' => $googleAllowed,
                default => $localAllowed || $googleAllowed,
            };

            if (! $allowed) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw new AuthenticationException('Operator access has been revoked.', ['web'], route('login'));
            }
        }

        return $next($request);
    }
}
