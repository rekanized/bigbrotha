<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f2efe8">

        <title>@yield('title', config('app.name', 'BigBrothas'))</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        @stack('styles')
    </head>
    <body class="@yield('body_class')">
        <div class="app-shell">
            <header class="topbar">
                <div class="topbar__brand">
                    <span class="topbar__eyebrow">Operations console</span>
                    <span class="topbar__title">{{ config('app.name', 'BigBrothas') }}</span>
                </div>
                <div class="topbar__status">Restricted access active</div>
            </header>

            <main class="app-main">
                @yield('content')
            </main>
        </div>

        @stack('scripts')
    </body>
</html>