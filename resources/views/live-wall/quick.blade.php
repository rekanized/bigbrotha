<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#10141b">
        <title>{{ $camera->name }} | {{ config('app.name', 'Bigbrotha') }}</title>
        @include('layouts.partials.app-icons', ['assetBase' => rtrim(request()->getBaseUrl(), '/')])
        <link rel="stylesheet" href="{{ asset('css/pages/camera-quick.css').'?v='.filemtime(public_path('css/pages/camera-quick.css')) }}">
        <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer></script>
    </head>
    <body>
        <header class="quick-header">
            <h1>{{ $camera->name }}</h1>
            <a href="{{ route('camera-fleet.index') }}">Camera fleet</a>
        </header>
        <main aria-label="{{ $camera->name }} live feed">
            @include('live-wall.partials.single-player')
        </main>
    </body>
</html>
