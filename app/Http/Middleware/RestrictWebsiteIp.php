<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\IpUtils;

class RestrictWebsiteIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('network.website_allowed_ips', []);

        if ($allowedIps !== [] && !$this->ipIsAllowed((string) $request->ip(), $allowedIps)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $allowedIps
     */
    private function ipIsAllowed(string $requestIp, array $allowedIps): bool
    {
        foreach ($allowedIps as $allowedIp) {
            if ($allowedIp === '*') {
                return true;
            }

            if (IpUtils::checkIp($requestIp, $allowedIp)) {
                return true;
            }
        }

        return false;
    }
}