<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGoogleOAuthMediaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && is_string($user->google_id ?? null) && trim((string) $user->google_id) !== '',
            Response::HTTP_FORBIDDEN,
            'Google OAuth is required for media access.',
        );

        return $next($request);
    }
}