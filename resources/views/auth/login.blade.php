<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#edf2f6">

        <title>{{ config('app.name', 'Bigbrotha') }} | Sign in</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
        @endphp

        <link rel="icon" type="image/svg+xml" href="{{ $assetBase }}/favicon.svg" sizes="any">
        <link rel="icon" type="image/x-icon" href="{{ $assetBase }}/favicon.ico">

        <link rel="stylesheet" href="{{ $assetBase }}/css/app.css">
    </head>
    <body class="auth-page">
        <main class="auth-layout">
            <section class="auth-brief page-card">
                <div class="auth-brief__brand">
                    <div class="auth-card__mark" aria-hidden="true">
                        <img class="sidebar-brand__logo" src="{{ $assetBase }}/img/bigbrotha-logo.svg" alt="">
                    </div>
                    <div>
                        <div class="auth-card__eyebrow">Camera operations platform</div>
                        <strong>{{ config('app.name', 'Bigbrotha') }}</strong>
                    </div>
                </div>

                <div class="auth-brief__intro">
                    <h1 class="auth-brief__title">Secure the control room before any feed starts.</h1>
                    <p class="auth-card__copy">Sign in once to manage discovery, camera inventory, RTSP validation, and shared WebRTC playback. The same Laravel session authorizes short-lived read tokens for the wall.</p>
                </div>

                <div class="auth-signal-grid">
                    <article class="auth-signal">
                        <span class="auth-signal__label">Discovery</span>
                        <strong class="auth-signal__title">Sweep the subnet or probe a known endpoint.</strong>
                        <p>Use ONVIF multicast when the network allows it, then fall back to direct SOAP authentication when it does not.</p>
                    </article>

                    <article class="auth-signal">
                        <span class="auth-signal__label">Stream trust</span>
                        <strong class="auth-signal__title">Laravel controls every wall session.</strong>
                        <p>Authenticated operators receive short-lived MediaMTX tokens only when a wall or player session is requested.</p>
                    </article>
                </div>

                <ul class="auth-card__list">
                    <li>The first successful Google sign-in becomes admin automatically.</li>
                    <li>After bootstrap, only admin-approved Google email addresses can sign in.</li>
                    <li>Laravel issues signed stream tokens per secure wall connection.</li>
                    <li>MediaMTX validates those reads before serving camera video.</li>
                </ul>
            </section>

            <section class="auth-card page-card">
                <div class="auth-card__brand">
                    <div class="auth-card__mark" aria-hidden="true">
                        <img class="sidebar-brand__logo" src="{{ $assetBase }}/img/bigbrotha-logo.svg" alt="">
                    </div>
                    <div>
                        <div class="auth-card__eyebrow">Secure operator access</div>
                        <strong>Operator sign-in</strong>
                    </div>
                </div>

                <div class="auth-card__intro">
                    <h2 class="auth-card__title">Open the operator workspace</h2>
                    <p class="auth-card__copy">Authenticate with Google to continue into the dashboard and secure player views. After the first admin is created, Google login is checked against the admin-managed allowlist.</p>
                </div>

                @if (session('auth_error'))
                    <div class="auth-card__alert">{{ session('auth_error') }}</div>
                @endif

                @php
                    $googleReady = filled(config('services.google.client_id'))
                        && filled(config('services.google.client_secret'))
                        && filled(config('services.google.redirect'));
                @endphp

                @if ($googleReady)
                    <a class="button button--primary auth-card__button" href="{{ route('auth.google.redirect') }}">
                        Continue with Google
                    </a>
                @else
                    <span class="button button--soft auth-card__button is-disabled" aria-disabled="true">
                        Google OAuth is not configured yet
                    </span>
                @endif

                <p class="auth-card__footnote">OAuth configuration comes from Laravel service settings. When those values are missing, sign-in stays unavailable by design.</p>
            </section>
        </main>
    </body>
</html>