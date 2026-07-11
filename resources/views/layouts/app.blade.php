<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f3f4f7">

        <title>@yield('title', config('app.name', 'Bigbrotha'))</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
            $stylesheetVersion = filemtime(public_path('css/pages/simplified-theme.css'));
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
        @php
            $bodyClass = trim($__env->yieldContent('body_class'));
            $layoutMode = trim($__env->yieldContent('layout_mode'));
            $isImmersiveLayout = $layoutMode === 'immersive';
            $showImmersiveRail = $isImmersiveLayout && trim($__env->yieldContent('show_immersive_rail')) === 'true';
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

        <div class="app-shell{{ $isImmersiveLayout ? ' app-shell--immersive' : '' }}{{ $showImmersiveRail ? ' app-shell--with-immersive-rail' : '' }}">
            @if (!$isImmersiveLayout || $showImmersiveRail)
                <aside class="app-rail page-card{{ $showImmersiveRail ? ' app-rail--immersive-desktop-only' : '' }}">
                    <div class="app-rail__inner">
                        <div class="sidebar-brand">
                            <div class="sidebar-brand__mark" aria-hidden="true">
                                <img class="sidebar-brand__logo" src="{{ $assetBase }}/img/bigbrotha-logo.svg" alt="">
                            </div>
                            <div class="sidebar-brand__meta">
                                <span class="sidebar-brand__eyebrow">Operator workspace</span>
                                <span class="sidebar-brand__title">{{ config('app.name', 'Bigbrotha') }}</span>
                            </div>
                        </div>

                        <section class="rail-section">
                            @include('layouts.partials.sidebar-navigation')
                        </section>

                    </div>
                </aside>
            @endif

            <div class="app-content{{ $isImmersiveLayout ? ' app-content--immersive' : '' }}">
                @unless ($isImmersiveLayout || $hideWorkspaceHero)
                    <header class="workspace-hero workspace-hero--compact page-card">
                        <div class="workspace-hero__body">
                            <div class="workspace-topbar__intro">
                                <h1 class="workspace-topbar__title workspace-topbar__title--compact">{{ $pageTitle !== '' ? $pageTitle : 'Camera control room' }}</h1>
                            </div>

                            <div class="workspace-hero__utility workspace-hero__utility--compact">
                                @if ($pageActions !== '')
                                    <div class="workspace-topbar__controls">
                                        @yield('page_actions')
                                    </div>
                                @endif

                                @if ($currentUser)
                                    <div class="workspace-topbar__auth workspace-topbar__auth--compact">
                                        <div class="operator-chip" aria-label="Current operator">
                                            <span class="operator-chip__avatar">{{ $currentUserInitials }}</span>
                                            <span class="operator-chip__meta">
                                                <strong>{{ $currentUser->name }}</strong>
                                            </span>
                                        </div>

                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button class="button button--soft" type="submit">Sign out</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </header>
                @endunless

                <main class="app-main{{ $isImmersiveLayout ? ' app-main--immersive' : '' }}">
                    @yield('content')
                </main>
            </div>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
