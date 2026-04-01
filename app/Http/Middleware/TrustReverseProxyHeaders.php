<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Symfony\Component\HttpFoundation\Request;

class TrustReverseProxyHeaders extends TrustProxies
{
    /**
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX;

    public function __construct()
    {
        $configured = config('network.trusted_proxies', []);

        $this->proxies = $configured === ['*'] ? '*' : $configured;
    }
}
