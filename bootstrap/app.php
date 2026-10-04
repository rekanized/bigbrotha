<?php

use App\Http\Middleware\EnsureActiveOperator;
use App\Http\Middleware\EnsureAdminUser;
use App\Http\Middleware\EnsureAuthenticatedMediaAccess;
use App\Http\Middleware\EnsurePublicHost;
use App\Http\Middleware\EnsureSetupIsComplete;
use App\Http\Middleware\RestrictWebsiteIp;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustReverseProxyHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdminUser::class,
            'media-access' => EnsureAuthenticatedMediaAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'relay/auth/mediamtx',
        ]);
        $middleware->web(prepend: [SecurityHeaders::class], append: [
            EnsurePublicHost::class,
            AuthenticateSession::class,
            EnsureActiveOperator::class,
        ]);
        $middleware->prepend(TrustReverseProxyHeaders::class);
        $middleware->append(RestrictWebsiteIp::class);
        $middleware->append(EnsureSetupIsComplete::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
