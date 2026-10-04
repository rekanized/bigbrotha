<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#f5f6fa">

        <title>{{ config('app.name', 'Bigbrotha') }} | Initial setup</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
            $stylesheetVersion = max(
                filemtime(public_path('css/app.css')),
                filemtime(public_path('css/pages/simplified-theme.css')),
                filemtime(public_path('css/pages/mobile.css')),
            );
        @endphp

        @include('layouts.partials.app-icons')
        <link rel="stylesheet" href="{{ $assetBase }}/css/app.css?v={{ $stylesheetVersion }}">
        <link rel="stylesheet" href="{{ $assetBase }}/css/pages/modern-theme.css?v={{ filemtime(public_path('css/pages/modern-theme.css')) }}">
        @livewireStyles
    </head>
    <body class="auth-page auth-page--setup">
        <main class="auth-shell auth-shell--setup">
            <livewire:setup.setup-wizard />
        </main>

        @livewireScripts
    </body>
</html>
