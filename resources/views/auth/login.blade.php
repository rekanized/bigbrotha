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
        <main class="auth-shell">
            <section class="auth-card page-card">
                <div class="auth-brand">
                    <div class="auth-card__mark" aria-hidden="true">
                        <img class="sidebar-brand__logo" src="{{ $assetBase }}/img/bigbrotha-logo.svg" alt="{{ config('app.name', 'Bigbrotha') }} logo">
                    </div>
                    <div class="auth-brand__copy">
                        <h1 class="auth-brand__name">{{ config('app.name', 'Bigbrotha') }}</h1>
                        <p class="auth-brand__text">Overwatch from Bigbrotha.</p>
                    </div>
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

                <p class="auth-card__footnote">Operator access for live viewing, recordings, and camera management.</p>
            </section>
        </main>
    </body>
</html>