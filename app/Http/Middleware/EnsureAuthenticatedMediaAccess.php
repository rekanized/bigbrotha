<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedMediaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user() !== null,
            Response::HTTP_FORBIDDEN,
            'An authenticated operator session is required for media access.',
        );

        return $next($request);
    }
}
