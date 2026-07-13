<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#edf2f6">

        <title>{{ config('app.name', 'Bigbrotha') }} | Sign in</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
            $stylesheetVersion = max(
                filemtime(public_path('css/pages/simplified-theme.css')),
                filemtime(public_path('css/pages/mobile.css')),
            );
        @endphp

        <link rel="icon" type="image/svg+xml" href="{{ $assetBase }}/favicon.svg" sizes="any">
        <link rel="icon" type="image/x-icon" href="{{ $assetBase }}/favicon.ico">

        <link rel="stylesheet" href="{{ $assetBase }}/css/app.css?v={{ $stylesheetVersion }}">
        @livewireStyles
    </head>
    <body class="auth-page">
        <main class="auth-shell auth-shell--wide">
            <livewire:auth.unified-login-screen />
        </main>

        @livewireScripts
    </body>
</html>
