<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#10141b">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $camera->name }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <title>{{ $camera->name }} | {{ config('app.name', 'Bigbrotha') }}</title>
        @include('layouts.partials.app-icons', ['assetBase' => rtrim(request()->getBaseUrl(), '/'), 'manifestUrl' => route('cameras.manifest', ['camera' => $camera])])
        <link rel="stylesheet" href="{{ asset('css/pages/camera-quick.css').'?v='.filemtime(public_path('css/pages/camera-quick.css')) }}">
        <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer></script>
        <script src="{{ asset('js/camera-shortcut.js').'?v='.filemtime(public_path('js/camera-shortcut.js')) }}" defer></script>
    </head>
    <body>
        <header class="quick-header">
            <h1>{{ $camera->name }}</h1>
            <a href="{{ route('camera-fleet.index') }}">Camera fleet</a>
        </header>
        <main aria-label="{{ $camera->name }} live feed">
            @include('live-wall.partials.single-player')
            <details class="quick-shortcut" data-camera-shortcut>
                <summary>Add to Home Screen</summary>
                <p>Save a shortcut that opens {{ $camera->name }} directly.</p>
                <button class="button" type="button" data-install-camera hidden>Install camera shortcut</button>
                <p><strong>iPhone / iPad:</strong> Open this camera page in Safari, tap Share, then Add to Home Screen. Keep Open as Web App enabled if shown, then tap Add.</p>
                <p><strong>Android:</strong> Open this camera page in Chrome, tap the menu, then Install and create shortcut or Add to Home screen. Choose Install or Create shortcut and confirm.</p>
                <p>Sign in before adding the shortcut. If it asks you to sign in again when opened, login will return you to this camera.</p>
                <p data-shortcut-status role="status" aria-live="polite"></p>
            </details>
        </main>
    </body>
</html>
