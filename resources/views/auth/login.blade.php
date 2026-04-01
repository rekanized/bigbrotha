<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#eef2f7">

        <title>{{ config('app.name', 'BigBrothas') }} | Sign in</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    </head>
    <body class="auth-page">
        <main class="auth-shell">
            <section class="auth-card page-card">
                <div class="auth-card__brand">
                    <div class="auth-card__mark">BB</div>
                    <div>
                        <div class="auth-card__eyebrow">Secure operator access</div>
                        <strong>{{ config('app.name', 'BigBrothas') }}</strong>
                    </div>
                </div>

                <div class="auth-card__intro">
                    <h1 class="auth-card__title">Control room sign-in</h1>
                    <p class="auth-card__copy">Authenticate with Google to open the operator workspace. Your Laravel session also authorizes short-lived WebRTC reads for the Live Wall.</p>
                </div>

                <ul class="auth-card__list">
                    <li>Google OAuth establishes the site session.</li>
                    <li>Laravel issues short-lived stream tokens to authenticated users only.</li>
                    <li>MediaMTX validates each WebRTC read against Laravel before serving the stream.</li>
                </ul>

                @if (session('auth_error'))
                    <div class="auth-card__alert">{{ session('auth_error') }}</div>
                @endif

                @php
                    $googleReady = filled(config('services.google.client_id'))
                        && filled(config('services.google.client_secret'))
                        && filled(config('services.google.redirect'));
                @endphp

                <a
                    class="button button--primary auth-card__button"
                    href="{{ $googleReady ? route('auth.google.redirect') : '#' }}"
                    @if (! $googleReady) aria-disabled="true" @endif
                >
                    {{ $googleReady ? 'Continue with Google' : 'Google OAuth is not configured yet' }}
                </a>
            </section>
        </main>
    </body>
</html>