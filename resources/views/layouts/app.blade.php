<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#eef2f7">

        <title>@yield('title', config('app.name', 'BigBrothas'))</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        @livewireStyles
        @stack('styles')
    </head>
    <body class="@yield('body_class')">
        @php
            $navigation = [
                'Control Room' => [
                    ['label' => 'Overview', 'icon' => 'OV', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
                    ['label' => 'Live Wall', 'icon' => 'LW', 'href' => route('live-wall.index'), 'active' => request()->routeIs('live-wall.*')],
                    ['label' => 'Camera Fleet', 'icon' => 'CF', 'href' => route('camera-fleet.index'), 'active' => request()->routeIs('camera-fleet.*')],
                    ['label' => 'Recording Runs', 'icon' => 'RR', 'href' => '#', 'active' => false],
                ],
                'Discovery' => [
                    ['label' => 'ONVIF Sweep', 'icon' => 'ON', 'href' => route('discovery.onvif-sweep'), 'active' => request()->routeIs('discovery.onvif-sweep')],
                    ['label' => 'RTSP Profiles', 'icon' => 'RP', 'href' => '#', 'active' => false],
                    ['label' => 'Site Zones', 'icon' => 'SZ', 'href' => '#', 'active' => false],
                ],
                'Governance' => [
                    ['label' => 'Operators', 'icon' => 'OP', 'href' => '#', 'active' => false],
                    ['label' => 'Incident Review', 'icon' => 'IR', 'href' => '#', 'active' => false],
                    ['label' => 'Audit Trail', 'icon' => 'AT', 'href' => '#', 'active' => false],
                ],
            ];
        @endphp

        <div class="app-shell">
            <aside class="app-sidebar page-card">
                <div class="sidebar-brand">
                    <div class="sidebar-brand__mark">BB</div>
                    <div class="sidebar-brand__meta">
                        <span class="sidebar-brand__eyebrow">Camera operations</span>
                        <span class="sidebar-brand__title">{{ config('app.name', 'BigBrothas') }}</span>
                    </div>
                </div>

                <nav class="sidebar-nav" aria-label="Primary">
                    @foreach ($navigation as $group => $items)
                        <section class="sidebar-nav__group">
                            <h2 class="sidebar-nav__heading">{{ $group }}</h2>

                            <div class="sidebar-nav__links">
                                @foreach ($items as $item)
                                    <a
                                        class="sidebar-link{{ $item['active'] ? ' sidebar-link--active' : '' }}"
                                        href="{{ $item['href'] }}"
                                        @if ($item['href'] !== '#') wire:navigate @endif
                                        @if ($item['active']) aria-current="page" @endif
                                    >
                                        <span class="sidebar-link__icon">{{ $item['icon'] }}</span>
                                        <span class="sidebar-link__label">{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </nav>

                <div class="sidebar-foot">
                    <span class="sidebar-foot__label">Coverage status</span>
                    <strong class="sidebar-foot__title">27 live feeds across 3 sites</strong>
                    <p class="sidebar-foot__copy">ONVIF discovery, RTSP routing, and recorder health share the same operator workspace.</p>
                </div>
            </aside>

            <div class="app-content">
                <header class="workspace-topbar page-card">
                    <div class="workspace-topbar__intro">
                        <p class="workspace-topbar__eyebrow">@yield('page_eyebrow', 'Operations dashboard')</p>
                        <h1 class="workspace-topbar__title">@yield('page_title', 'Camera control room')</h1>
                        <p class="workspace-topbar__lead">@yield('page_lead', 'Monitor feeds, recording coverage, and operator workload from a single workspace.') </p>
                    </div>

                    <div class="workspace-topbar__actions">
                        <div class="workspace-topbar__controls">
                            @yield('page_actions')
                        </div>

                        <div class="operator-chip" aria-label="Current operator">
                            <span class="operator-chip__avatar">CS</span>
                            <span class="operator-chip__meta">
                                <strong>Control Shift</strong>
                                <small>Supervisor</small>
                            </span>
                        </div>
                    </div>
                </header>

                <main class="app-main">
                    @yield('content')
                </main>
            </div>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>