@php
    $currentUser = auth()->user();
    $canAccessAdminNavigation = $currentUser && ($currentUser->isAdmin() || !\App\Models\User::query()->where('is_admin', true)->exists());
    $navigation = [
        [
            'label' => 'Operations',
            'items' => [
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
                    'label' => 'Live Wall',
                    'icon' => 'LW',
                    'symbol' => 'live_tv',
                    'caption' => 'Shared authenticated WebRTC playback',
                    'href' => route('live-wall.index'),
                    'active' => request()->routeIs('live-wall.*'),
                ],
            ],
        ],
        [
            'label' => 'Setup',
            'items' => [
                [
                    'label' => 'Camera Fleet',
                    'icon' => 'CF',
                    'symbol' => 'videocam',
                    'caption' => 'Probe endpoints, save cameras, and manage stream defaults',
                    'href' => route('camera-fleet.index'),
                    'active' => request()->routeIs('camera-fleet.*'),
                ],
                [
                    'label' => 'Wall Tiles',
                    'icon' => 'WT',
                    'symbol' => 'grid_view',
                    'caption' => 'Build named walls and tile layouts',
                    'href' => route('wall-tiles.index'),
                    'active' => request()->routeIs('wall-tiles.*'),
                ],
            ],
        ],
    ];

    $adminNavigation = $canAccessAdminNavigation ? [
        [
            'label' => 'Operator access',
            'caption' => 'Local accounts, approved Google emails, operators, and admin access',
            'href' => route('admin.users.index'),
            'active' => request()->routeIs('admin.users.*'),
        ],
        [
            'label' => 'Application settings',
            'caption' => 'Display timezone and future operator settings',
            'href' => route('admin.settings.index'),
            'active' => request()->routeIs('admin.settings.*'),
        ],
        [
            'label' => 'Audit log',
            'caption' => 'Review model changes, actors, and captured deltas',
            'href' => route('admin.audit-logs.index'),
            'active' => request()->routeIs('admin.audit-logs.*'),
        ],
    ] : [];
    $adminNavigationActive = collect($adminNavigation)->contains(fn (array $item): bool => $item['active']);
@endphp

<nav class="sidebar-nav" aria-label="Primary">
    <div class="sidebar-nav__links">
        @foreach ($navigation as $section)
            <section class="sidebar-nav__section" aria-labelledby="sidebar-section-{{ \Illuminate\Support\Str::slug($section['label']) }}">
                <p class="sidebar-nav__section-title" id="sidebar-section-{{ \Illuminate\Support\Str::slug($section['label']) }}">{{ $section['label'] }}</p>

                <div class="sidebar-nav__section-links">
                    @foreach ($section['items'] as $item)
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
            </section>
        @endforeach

        @if ($adminNavigation !== [])
            <details class="sidebar-group{{ $adminNavigationActive ? ' sidebar-group--active' : '' }}" @if ($adminNavigationActive) open @endif>
                <summary class="sidebar-group__summary">
                    <span class="sidebar-link sidebar-link--summary{{ $adminNavigationActive ? ' sidebar-link--active' : '' }}">
                        <span class="sidebar-link__icon" aria-hidden="true">
                            <span class="sidebar-link__abbr">AD</span>
                            <span class="sidebar-link__symbol material-symbols-rounded">admin_panel_settings</span>
                        </span>
                        <span class="sidebar-link__content">
                            <span class="sidebar-link__label">Admin</span>
                            <small class="sidebar-link__caption">Users, application settings, and operator-wide controls</small>
                        </span>
                        <span class="sidebar-group__chevron material-symbols-rounded" aria-hidden="true">expand_more</span>
                    </span>
                </summary>

                <div class="sidebar-group__links">
                    @foreach ($adminNavigation as $item)
                        <a
                            class="sidebar-sublink{{ $item['active'] ? ' sidebar-sublink--active' : '' }}"
                            href="{{ $item['href'] }}"
                            wire:navigate
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <span class="sidebar-sublink__label">{{ $item['label'] }}</span>
                            <small class="sidebar-sublink__caption">{{ $item['caption'] }}</small>
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</nav>