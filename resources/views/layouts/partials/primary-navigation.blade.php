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
                    'caption' => 'Synchronized recorded playback across cameras',
                    'href' => route('recordings.timeline'),
                    'active' => request()->routeIs('recordings.timeline', 'recordings.timeline.*'),
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
                    'caption' => 'Probe endpoints, save cameras, and manage streams',
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
            'caption' => 'Accounts, approved emails, and administrator access',
            'href' => route('admin.users.index'),
            'active' => request()->routeIs('admin.users.*'),
        ],
        [
            'label' => 'Application settings',
            'caption' => 'Authentication, storage, timezone, and queue settings',
            'href' => route('admin.settings.index'),
            'active' => request()->routeIs('admin.settings.*'),
        ],
        [
            'label' => 'Audit log',
            'caption' => 'Who changed what, recording activity, and failures',
            'href' => route('admin.audit-logs.index'),
            'active' => request()->routeIs('admin.audit-logs.*'),
        ],
    ] : [];
    $adminNavigationActive = collect($adminNavigation)->contains(fn (array $item): bool => $item['active']);
    $navigationIdSuffix = $navigationIdSuffix ?? 'default';
    $navigationAriaLabel = $navigationAriaLabel ?? 'Primary navigation';
    $expandActiveGroup = $expandActiveGroup ?? false;
@endphp

<nav class="primary-navigation" aria-label="{{ $navigationAriaLabel }}">
    <div class="primary-navigation__links">
        @foreach ($navigation as $section)
            <section class="primary-navigation__section" aria-labelledby="primary-navigation-section-{{ $navigationIdSuffix }}-{{ \Illuminate\Support\Str::slug($section['label']) }}">
                <p class="primary-navigation__section-title" id="primary-navigation-section-{{ $navigationIdSuffix }}-{{ \Illuminate\Support\Str::slug($section['label']) }}">{{ $section['label'] }}</p>

                <div class="primary-navigation__section-links">
                    @foreach ($section['items'] as $item)
                        <a
                            class="primary-navigation__link{{ $item['active'] ? ' primary-navigation__link--active' : '' }}"
                            href="{{ $item['href'] }}"
                            wire:navigate
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <span class="primary-navigation__icon" aria-hidden="true">
                                <span class="primary-navigation__abbr">{{ $item['icon'] }}</span>
                                <span class="primary-navigation__symbol material-symbols-rounded">{{ $item['symbol'] }}</span>
                            </span>
                            <span class="primary-navigation__content">
                                <span class="primary-navigation__label">{{ $item['label'] }}</span>
                                <small class="primary-navigation__caption">{{ $item['caption'] }}</small>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($adminNavigation !== [])
            <details class="primary-navigation__group{{ $adminNavigationActive ? ' primary-navigation__group--active' : '' }}" @if ($adminNavigationActive && $expandActiveGroup) open @endif>
                <summary class="primary-navigation__group-summary">
                    <span class="primary-navigation__link primary-navigation__link--summary{{ $adminNavigationActive ? ' primary-navigation__link--active' : '' }}">
                        <span class="primary-navigation__icon" aria-hidden="true">
                            <span class="primary-navigation__abbr">AD</span>
                            <span class="primary-navigation__symbol material-symbols-rounded">admin_panel_settings</span>
                        </span>
                        <span class="primary-navigation__content">
                            <span class="primary-navigation__label">Admin</span>
                            <small class="primary-navigation__caption">Users, settings, queues, and audit controls</small>
                        </span>
                        <span class="primary-navigation__chevron material-symbols-rounded" aria-hidden="true">expand_more</span>
                    </span>
                </summary>

                <div class="primary-navigation__group-links">
                    @foreach ($adminNavigation as $item)
                        <a
                            class="primary-navigation__sublink{{ $item['active'] ? ' primary-navigation__sublink--active' : '' }}"
                            href="{{ $item['href'] }}"
                            wire:navigate
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <span class="primary-navigation__sublink-label">{{ $item['label'] }}</span>
                            <small class="primary-navigation__sublink-caption">{{ $item['caption'] }}</small>
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</nav>
