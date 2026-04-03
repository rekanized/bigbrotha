<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f3f4f7" data-theme-color-light="#f3f4f7" data-theme-color-dark="#0f141b">

        <script>
            (() => {
                const storageKey = 'bigbrothas-theme';
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

        <title>@yield('title', config('app.name', 'BigBrothas'))</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded">
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
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
            $navigation = [
                [
                    'label' => 'Overview',
                    'icon' => 'OV',
                    'symbol' => 'dashboard',
                    'caption' => 'Health, readiness, and recent changes',
                    'href' => route('dashboard'),
                    'active' => request()->routeIs('dashboard'),
                ],
                [
                    'label' => 'ONVIF Sweep',
                    'icon' => 'DS',
                    'symbol' => 'radar',
                    'caption' => 'Sweep the network or probe directly',
                    'href' => route('discovery.onvif-sweep'),
                    'active' => request()->routeIs('discovery.onvif-sweep'),
                ],
                [
                    'label' => 'Camera Fleet',
                    'icon' => 'CF',
                    'symbol' => 'videocam',
                    'caption' => 'Save cameras, credentials, and stream defaults',
                    'href' => route('camera-fleet.index'),
                    'active' => request()->routeIs('camera-fleet.*'),
                ],
                [
                    'label' => 'Recordings',
                    'icon' => 'RC',
                    'symbol' => 'movie',
                    'caption' => 'Search recorded segments and playback history',
                    'href' => route('recordings.index'),
                    'active' => request()->routeIs('recordings.index', 'recordings.show', 'recordings.stream', 'recordings.download', 'recordings.review-stream'),
                ],
                [
                    'label' => 'Timeline Review',
                    'icon' => 'TR',
                    'symbol' => 'timeline',
                    'caption' => 'Wall-based synchronized recorded playback',
                    'href' => route('recordings.timeline'),
                    'active' => request()->routeIs('recordings.timeline'),
                ],
                [
                    'label' => 'Wall Tiles',
                    'icon' => 'WT',
                    'symbol' => 'grid_view',
                    'caption' => 'Build named walls and tile layouts',
                    'href' => route('wall-tiles.index'),
                    'active' => request()->routeIs('wall-tiles.*'),
                ],
                [
                    'label' => 'Live Wall',
                    'icon' => 'LW',
                    'symbol' => 'live_tv',
                    'caption' => 'Shared authenticated WebRTC playback',
                    'href' => route('live-wall.index'),
                    'active' => request()->routeIs('live-wall.*'),
                ],
            ];
        @endphp

        <div class="app-shell{{ $isImmersiveLayout ? ' app-shell--immersive' : '' }}">
            @unless ($isImmersiveLayout)
                <aside class="app-rail page-card">
                    <div class="app-rail__inner">
                        <div class="sidebar-brand">
                            <div class="sidebar-brand__mark">BB</div>
                            <div class="sidebar-brand__meta">
                                <span class="sidebar-brand__eyebrow">Operator workspace</span>
                                <span class="sidebar-brand__title">{{ config('app.name', 'BigBrothas') }}</span>
                            </div>
                        </div>

                        <section class="rail-section">
                            <p class="rail-kicker">Primary screens</p>
                            <nav class="sidebar-nav" aria-label="Primary">
                                <div class="sidebar-nav__links">
                                    @foreach ($navigation as $item)
                                        <a
                                            class="sidebar-link{{ $item['active'] ? ' sidebar-link--active' : '' }}"
                                            href="{{ $item['href'] }}"
                                            wire:navigate
                                            @if ($item['active']) aria-current="page" @endif
                                        >
                                            <span class="sidebar-link__icon" aria-hidden="true">
                                                <span class="sidebar-link__abbr">{{ $item['icon'] }}</span>
                                                <span class="sidebar-link__symbol material-symbols-rounded">{{ $item['symbol'] }}</span>
                                            </span>
                                            <span class="sidebar-link__content">
                                                <span class="sidebar-link__label">{{ $item['label'] }}</span>
                                                <small class="sidebar-link__caption">{{ $item['caption'] }}</small>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            </nav>
                        </section>

                        <section class="rail-section rail-section--theme">
                            <p class="rail-kicker">Appearance</p>
                            <button class="theme-toggle theme-toggle--rail" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch theme">
                                <span class="theme-toggle__meta">
                                    <span class="theme-toggle__label">Theme</span>
                                    <strong class="theme-toggle__value" data-theme-toggle-value>Light</strong>
                                </span>
                                <span class="theme-toggle__indicator" aria-hidden="true"></span>
                            </button>
                        </section>
                    </div>
                </aside>
            @endunless

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
                const storageKey = 'bigbrothas-theme';
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