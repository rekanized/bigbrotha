<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('relay/auth/mediamtx')) {
            $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            abort_unless(is_string($expectedHost) && strcasecmp($request->getHost(), $expectedHost) === 0, 400, 'Invalid application host.');
        }

        return $next($request);
    }
}
