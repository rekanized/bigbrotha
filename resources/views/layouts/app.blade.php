<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#f3f4f7">

        <title>@yield('title', config('app.name', 'Bigbrotha'))</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
            $stylesheetVersion = max(
                filemtime(public_path('css/app.css')),
                filemtime(public_path('css/pages/simplified-theme.css')),
                filemtime(public_path('css/pages/mobile.css')),
                filemtime(public_path('css/pages/live-wall.css')),
                filemtime(public_path('css/components/global-header.css')),
            );
        @endphp

        <link rel="icon" type="image/svg+xml" href="{{ $assetBase }}/favicon.svg" sizes="any">
        <link rel="icon" type="image/x-icon" href="{{ $assetBase }}/favicon.ico">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded">
        <link rel="stylesheet" href="{{ $assetBase }}/css/app.css?v={{ $stylesheetVersion }}">
        <script>
            (() => {
                if (!('fonts' in document)) {
                    return;
                }

                const root = document.documentElement;
                const readyClass = 'material-symbols-ready';
                const fontFace = '20px "Material Symbols Rounded"';

                const ensureMaterialSymbolsReady = () => {
                    const markReady = () => root.classList.add(readyClass);

                    if (document.fonts.check(fontFace)) {
                        markReady();

                        return;
                    }

                    document.fonts.load(fontFace).then(markReady).catch(() => {
                    });
                };

                window.ensureMaterialSymbolsReady = ensureMaterialSymbolsReady;

                ensureMaterialSymbolsReady();
                document.addEventListener('livewire:navigated', ensureMaterialSymbolsReady);
            })();
        </script>
        @livewireStyles
        @stack('styles')
    </head>
    <body class="app-body @yield('body_class')">
        <a class="skip-link" href="#main-content">Skip to main content</a>
        @php
            $layoutMode = trim($__env->yieldContent('layout_mode'));
            $isImmersiveLayout = $layoutMode === 'immersive';
            $hideWorkspaceHero = trim($__env->yieldContent('hide_workspace_hero')) === 'true';
            $currentUser = auth()->user();
            $pageTitle = trim($__env->yieldContent('page_title'));
            $currentUserInitials = $currentUser
                ? collect(preg_split('/\s+/', trim($currentUser->name)))
                    ->filter()
                    ->take(2)
                    ->map(fn (string $segment): string => strtoupper(substr($segment, 0, 1)))
                    ->implode('')
                : 'BB';
            $pageActions = trim($__env->yieldContent('page_actions'));
        @endphp

        @if ($currentUser)
            @include('layouts.partials.global-header')
        @endif

        <div class="app-shell app-shell--global{{ $isImmersiveLayout ? ' app-shell--immersive' : '' }}">
            <div class="app-content app-content--global{{ $isImmersiveLayout ? ' app-content--immersive' : '' }}">
                @unless ($isImmersiveLayout || $hideWorkspaceHero)
                    <header class="workspace-hero workspace-hero--compact page-card">
                        <div class="workspace-hero__body">
                            <div class="workspace-topbar__intro">
                                <h1 class="workspace-topbar__title workspace-topbar__title--compact">{{ $pageTitle !== '' ? $pageTitle : 'Camera control room' }}</h1>
                            </div>

                            @if ($pageActions !== '')
                                <div class="workspace-hero__utility workspace-hero__utility--compact">
                                    <div class="workspace-topbar__controls">
                                        @yield('page_actions')
                                    </div>
                                </div>
                            @endif
                        </div>
                    </header>
                @endunless

                <main id="main-content" class="app-main{{ $isImmersiveLayout ? ' app-main--immersive' : '' }}" tabindex="-1">
                    @yield('content')
                </main>
            </div>
        </div>

        @if ($currentUser)
            <script src="{{ $assetBase }}/js/global-header.js?v={{ filemtime(public_path('js/global-header.js')) }}" defer data-navigate-once></script>
        @endif
        @livewireScripts
        @stack('scripts')
    </body>
</html>
