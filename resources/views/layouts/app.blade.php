<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f3f4f7" data-theme-color-light="#f3f4f7" data-theme-color-dark="#0f141b">

        <script>
            (() => {
                const storageKey = 'bigbrotha-theme';
                const root = document.documentElement;
                const media = window.matchMedia('(prefers-color-scheme: dark)');
                let storedTheme = null;

                try {
                    storedTheme = window.localStorage.getItem(storageKey);
                } catch (error) {
                    storedTheme = null;
                }

                const theme = storedTheme === 'dark' || storedTheme === 'light'
                    ? storedTheme
                    : (media.matches ? 'dark' : 'light');
                const themeColor = theme === 'dark' ? '#0f141b' : '#f3f4f7';

                root.dataset.theme = theme;
                root.style.colorScheme = theme;

                const themeColorMeta = document.querySelector('meta[name="theme-color"]');

                if (themeColorMeta) {
                    themeColorMeta.content = themeColor;
                }
            })();
        </script>

        <title>@yield('title', config('app.name', 'Bigbrotha'))</title>

        @php
            $assetBase = rtrim(request()->getBaseUrl(), '/');
        @endphp

        <link rel="icon" type="image/svg+xml" href="{{ $assetBase }}/favicon.svg" sizes="any">
        <link rel="icon" type="image/x-icon" href="{{ $assetBase }}/favicon.ico">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded">
        <link rel="stylesheet" href="{{ $assetBase }}/css/app.css">
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

                        <section class="rail-section rail-section--theme">
                            <p class="rail-kicker">Appearance</p>
                            <button class="theme-toggle theme-toggle--rail" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch theme">
                                <span class="theme-toggle__meta">
                                    <span class="theme-toggle__label">Theme</span>
                                    <strong class="theme-toggle__value" data-theme-toggle-value>Light</strong>
                                </span>
                                <span class="theme-toggle__switch" aria-hidden="true">
                                    <span class="theme-toggle__switch-state theme-toggle__switch-state--light">Light</span>
                                    <span class="theme-toggle__switch-state theme-toggle__switch-state--dark">Dark</span>
                                    <span class="theme-toggle__indicator"></span>
                                </span>
                            </button>
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
        <script>
            (() => {
                const storageKey = 'bigbrotha-theme';
                const root = document.documentElement;
                const media = window.matchMedia('(prefers-color-scheme: dark)');
                const themeColorMeta = document.querySelector('meta[name="theme-color"]');

                const readStoredTheme = () => {
                    try {
                        const storedTheme = window.localStorage.getItem(storageKey);

                        return storedTheme === 'dark' || storedTheme === 'light' ? storedTheme : null;
                    } catch (error) {
                        return null;
                    }
                };

                const resolvedTheme = () => readStoredTheme() ?? (media.matches ? 'dark' : 'light');

                const syncThemeControls = () => {
                    const isDarkTheme = root.dataset.theme === 'dark';
                    const nextThemeLabel = isDarkTheme ? 'Light' : 'Dark';

                    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
                        if (!(button instanceof HTMLButtonElement)) {
                            return;
                        }

                        button.setAttribute('aria-pressed', isDarkTheme ? 'true' : 'false');
                        button.setAttribute('aria-label', `Switch to ${nextThemeLabel.toLowerCase()} mode`);
                        button.title = `Switch to ${nextThemeLabel.toLowerCase()} mode`;
                    });

                    document.querySelectorAll('[data-theme-toggle-value]').forEach((element) => {
                        if (element instanceof HTMLElement) {
                            element.textContent = isDarkTheme ? 'Dark' : 'Light';
                        }
                    });
                };

                const applyTheme = (theme, persist = false) => {
                    root.dataset.theme = theme;
                    root.style.colorScheme = theme;

                    if (themeColorMeta) {
                        themeColorMeta.content = theme === 'dark'
                            ? (themeColorMeta.dataset.themeColorDark || '#0f141b')
                            : (themeColorMeta.dataset.themeColorLight || '#f3f4f7');
                    }

                    if (persist) {
                        try {
                            window.localStorage.setItem(storageKey, theme);
                        } catch (error) {
                        }
                    }

                    syncThemeControls();
                };

                document.addEventListener('click', (event) => {
                    const button = event.target instanceof Element ? event.target.closest('[data-theme-toggle]') : null;

                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }

                    event.preventDefault();

                    applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
                });

                const handleSystemThemeChange = () => {
                    if (readStoredTheme() !== null) {
                        return;
                    }

                    applyTheme(resolvedTheme(), false);
                };

                if (typeof media.addEventListener === 'function') {
                    media.addEventListener('change', handleSystemThemeChange);
                } else if (typeof media.addListener === 'function') {
                    media.addListener(handleSystemThemeChange);
                }

                document.addEventListener('livewire:navigated', syncThemeControls);

                applyTheme(resolvedTheme(), false);
            })();
        </script>
    </body>
</html>