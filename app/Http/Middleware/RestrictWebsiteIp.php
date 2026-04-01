<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictWebsiteIp
{
    private const ALLOWED_IP = '192.168.1.1';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->ip() === self::ALLOWED_IP, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}