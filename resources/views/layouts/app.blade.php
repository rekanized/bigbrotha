<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#edf2f6">

        <title>@yield('title', config('app.name', 'BigBrothas'))</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        @livewireStyles
        @stack('styles')
    </head>
    <body class="app-body @yield('body_class')">
        @php
            $bodyClass = trim($__env->yieldContent('body_class'));
            $layoutMode = trim($__env->yieldContent('layout_mode'));
            $isImmersiveLayout = $layoutMode === 'immersive';
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
                    'caption' => 'Health, readiness, and recent changes',
                    'href' => route('dashboard'),
                    'active' => request()->routeIs('dashboard'),
                ],
                [
                    'label' => 'ONVIF Sweep',
                    'icon' => 'DS',
                    'caption' => 'Sweep the network or probe directly',
                    'href' => route('discovery.onvif-sweep'),
                    'active' => request()->routeIs('discovery.onvif-sweep'),
                ],
                [
                    'label' => 'Camera Fleet',
                    'icon' => 'CF',
                    'caption' => 'Save cameras, credentials, and stream defaults',
                    'href' => route('camera-fleet.index'),
                    'active' => request()->routeIs('camera-fleet.*'),
                ],
                [
                    'label' => 'Wall Tiles',
                    'icon' => 'WT',
                    'caption' => 'Build named walls and tile layouts',
                    'href' => route('wall-tiles.index'),
                    'active' => request()->routeIs('wall-tiles.*'),
                ],
                [
                    'label' => 'Live Wall',
                    'icon' => 'LW',
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
                                            <span class="sidebar-link__icon">{{ $item['icon'] }}</span>
                                            <span class="sidebar-link__content">
                                                <span class="sidebar-link__label">{{ $item['label'] }}</span>
                                                <small class="sidebar-link__caption">{{ $item['caption'] }}</small>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            </nav>
                        </section>
                    </div>
                </aside>
            @endunless

            <div class="app-content{{ $isImmersiveLayout ? ' app-content--immersive' : '' }}">
                @unless ($isImmersiveLayout)
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