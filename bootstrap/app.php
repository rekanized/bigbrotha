<?php

use App\Http\Middleware\TrustReverseProxyHeaders;
use App\Http\Middleware\RestrictWebsiteIp;
use App\Http\Middleware\EnsureAdminUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdminUser::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'relay/auth/mediamtx',
        ]);
        $middleware->prepend(TrustReverseProxyHeaders::class);
        $middleware->append(RestrictWebsiteIp::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
