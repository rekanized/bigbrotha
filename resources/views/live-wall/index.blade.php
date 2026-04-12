@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Live Wall')

@section('layout_mode', 'immersive')

@section('show_immersive_rail', 'true')

@section('body_class', 'page-live-wall')

@section('content')
    @php
        $wallCount = $availableWalls->count();
        $selectedWallIndex = $selectedWall ? $availableWalls->search(fn ($wall) => $wall->id === $selectedWall->id) : false;
        $selectedWallIndex = is_int($selectedWallIndex) ? $selectedWallIndex : 0;
        $previousWall = $wallCount > 0 ? $availableWalls->get(($selectedWallIndex - 1 + $wallCount) % $wallCount) : null;
        $nextWall = $wallCount > 0 ? $availableWalls->get(($selectedWallIndex + 1) % $wallCount) : null;
    @endphp

    <section class="live-wall-canvas" aria-label="Live wall camera monitor">
        @if (!($relayStatus['installed'] ?? false))
            <div class="empty-state live-wall-canvas__empty">
                <strong>Media relay not installed yet.</strong>
                <p>Run <code>composer relay:install</code> and then <code>php artisan relay:start</code> on the host to install the shared relay.</p>
            </div>
        @elseif (!($relayStatus['running'] ?? false))
            <div class="empty-state live-wall-canvas__empty">
                <strong>Media relay is configured but not running.</strong>
                <p>The wall will populate as soon as MediaMTX is running. Start it with <code>php artisan relay:start</code> or reload after checking the relay log.</p>
            </div>
        @elseif ($availableWalls->isEmpty())
            <div class="empty-state live-wall-canvas__empty">
                <strong>No active walls are available yet.</strong>
                <p>Create or activate a wall layout first, then assign cameras to enabled tiles before opening monitoring.</p>
                <a class="button button--primary" href="{{ route('wall-tiles.index') }}" wire:navigate>Open wall tiles</a>
            </div>
        @elseif (!$selectedWall)
            <div class="empty-state live-wall-canvas__empty">
                <strong>No wall is currently selected.</strong>
                <p>Choose an active wall or set one as the default from the wall builder.</p>
                <a class="button button--primary" href="{{ route('wall-tiles.index') }}" wire:navigate>Manage wall tiles</a>
            </div>
        @elseif ($tiles->isEmpty())
            <div class="empty-state live-wall-canvas__empty">
                <strong>This wall has no enabled camera tiles ready to render.</strong>
                <p>Assign cameras to the wall, or enable the existing tile assignments from the wall builder.</p>
                <a class="button button--primary" href="{{ route('wall-tiles.index') }}" wire:navigate>Configure wall tiles</a>
            </div>
        @else
            <div class="live-wall-grid live-wall-grid--configured live-wall-grid--monitor" style="--wall-grid-columns: {{ max(1, (int) $selectedWall->grid_columns) }};" data-live-wall-grid>
                @foreach ($tiles as $tile)
                    @php
                        /** @var \App\Models\Camera $camera */
                        $camera = $tile['camera'];
                        $tileStyle = 'grid-column: span '.($tile['columnSpan'] ?? 1).'; grid-row: span '.($tile['rowSpan'] ?? 1).';';
                        $liveSelection = $tile['liveSelection'];
                        $selectedProfile = $liveSelection['profile'] ?? null;
                        $selectedProfileIndex = $liveSelection['index'] ?? null;
                        $playerPageUrl = $tile['playerPageUrl'] ?? null;
                        $sessionUrl = $tile['sessionUrl'] ?? null;
                        $readerUrl = $tile['readerUrl'] ?? null;
                        $sessionBootstrap = $tile['sessionBootstrap'] ?? null;
                        $webrtcPath = $tile['webrtcPath'] ?? null;
                        $whepUrl = $tile['webrtcWhepUrl'] ?? null;
                        $latestPreview = $camera->latestRtspPreview();
                        $previewIndex = $latestPreview['index'] ?? null;
                        $previewProfile = $latestPreview['profile'] ?? null;
                    @endphp
                    <article
                        class="wall-monitor-tile"
                        style="{{ $tileStyle }}"
                        data-camera-name="{{ e($camera->name) }}"
                        data-camera-id="{{ $camera->getKey() }}"
                        data-profile-index="{{ $selectedProfileIndex ?? '' }}"
                        data-session-url="{{ $sessionUrl ?? '' }}"
                        data-reader-url="{{ $sessionBootstrap['reader_url'] ?? $readerUrl ?? '' }}"
                        data-whep-url="{{ $sessionBootstrap['whep_url'] ?? $whepUrl ?? '' }}"
                        data-access-token="{{ $sessionBootstrap['access_token'] ?? '' }}"
                        data-access-token-expires-in="{{ $sessionBootstrap['expires_in'] ?? '' }}"
                        data-access-token-issued-at="{{ $sessionBootstrap['issued_at'] ?? '' }}"
                        @if (is_string($webrtcPath) && $webrtcPath !== '') data-webrtc-path="{{ $webrtcPath }}" @endif
                    >
                        <div class="wall-monitor-tile__feed">
                            @if (is_array($selectedProfile) && is_string($sessionUrl) && $sessionUrl !== '')
                                <div class="wall-monitor-tile__stream wall-tile__stream">
                                    <div
                                        class="webrtc-player"
                                        data-webrtc-player
                                        data-session-url="{{ $sessionUrl }}"
                                        data-reader-url="{{ $sessionBootstrap['reader_url'] ?? $readerUrl ?? '' }}"
                                        data-whep-url="{{ $sessionBootstrap['whep_url'] ?? $whepUrl ?? '' }}"
                                        data-access-token="{{ $sessionBootstrap['access_token'] ?? '' }}"
                                        data-access-token-expires-in="{{ $sessionBootstrap['expires_in'] ?? '' }}"
                                        data-access-token-issued-at="{{ $sessionBootstrap['issued_at'] ?? '' }}"
                                        data-player-label="{{ $camera->name }}"
                                    >
                                        <video class="webrtc-player__video" data-role="video" autoplay muted playsinline></video>
                                        <div class="webrtc-player__message" data-role="message" aria-live="polite">Connecting to secure stream...</div>
                                        <div class="wall-monitor-tile__overlay">
                                            <div class="wall-monitor-tile__identity">
                                                <span class="wall-monitor-tile__label">Live</span>
                                                <strong class="wall-monitor-tile__name">{{ $camera->name }}</strong>
                                            </div>

                                            <div class="wall-monitor-tile__audio-controls">
                                                <button
                                                    class="wall-monitor-tile__audio-toggle"
                                                    type="button"
                                                    data-role="audio-toggle"
                                                    aria-pressed="false"
                                                    aria-label="Listen to {{ $camera->name }}"
                                                >
                                                    <span class="wall-monitor-tile__audio-icon" aria-hidden="true">
                                                        <svg class="wall-monitor-tile__audio-svg wall-monitor-tile__audio-svg--inactive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M11 5L6 9H3v6h3l5 4V5z"></path>
                                                            <line x1="16.5" y1="9.5" x2="21" y2="14"></line>
                                                            <line x1="21" y1="9.5" x2="16.5" y2="14"></line>
                                                        </svg>
                                                        <svg class="wall-monitor-tile__audio-svg wall-monitor-tile__audio-svg--active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M11 5L6 9H3v6h3l5 4V5z"></path>
                                                            <path d="M15.5 9a4.5 4.5 0 0 1 0 6"></path>
                                                            <path d="M18.5 6.5a8 8 0 0 1 0 11"></path>
                                                        </svg>
                                                    </span>
                                                    <span class="wall-monitor-tile__audio-indicator" data-role="audio-indicator" aria-live="polite">Muted</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @elseif (is_array($previewProfile) && $previewIndex !== null)
                                <a class="wall-monitor-tile__stream wall-tile__stream" href="{{ route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => $previewIndex]) }}" target="_blank" rel="noreferrer">
                                    <img
                                        class="wall-tile__stream-image"
                                        src="{{ route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => $previewIndex]) }}"
                                        alt="Latest saved preview for {{ $camera->name }}"
                                    >
                                </a>
                            @else
                                <div class="wall-tile__empty wall-monitor-tile__stream">
                                    <strong>No live RTSP stream is ready.</strong>
                                    <p>Refresh stream profiles or run a stream test from Camera Fleet to populate a stream and preview.</p>
                                </div>
                            @endif
                            <div class="wall-monitor-tile__gridline" aria-hidden="true"></div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($selectedWall && $wallCount > 0)
            <nav class="live-wall-wall-switcher" aria-label="Switch walls and live wall controls">
                <div class="live-wall-wall-switcher__desktop">
                    <a
                        class="live-wall-wall-switcher__arrow"
                        href="{{ route('live-wall.index', ['wall' => $previousWall?->slug]) }}"
                        wire:navigate
                        aria-label="Open previous wall"
                    >
                        <span aria-hidden="true">&#8249;</span>
                    </a>

                    <div class="live-wall-wall-switcher__status">
                        <strong class="live-wall-wall-switcher__wall-name">{{ $selectedWall->name }}</strong>
                        <span class="live-wall-wall-switcher__wall-meta">Wall {{ $selectedWallIndex + 1 }} of {{ $wallCount }}</span>
                    </div>

                    <div class="live-wall-wall-switcher__controls">
                        <label class="live-wall-wall-switcher__volume" aria-label="Live wall volume">
                            <span class="live-wall-wall-switcher__volume-label">Volume</span>
                            <input
                                class="live-wall-wall-switcher__volume-slider"
                                type="range"
                                min="0"
                                max="100"
                                step="1"
                                value="100"
                                data-role="master-volume-slider"
                                aria-label="Set live wall volume"
                            >
                            <span class="live-wall-wall-switcher__volume-value" data-role="master-volume-value">100%</span>
                        </label>
                    </div>

                    <a
                        class="live-wall-wall-switcher__arrow"
                        href="{{ route('live-wall.index', ['wall' => $nextWall?->slug]) }}"
                        wire:navigate
                        aria-label="Open next wall"
                    >
                        <span aria-hidden="true">&#8250;</span>
                    </a>
                </div>

                <div class="live-wall-wall-switcher__mobile">
                    <div class="live-wall-wall-switcher__mobile-bar">
                        <a
                            class="live-wall-wall-switcher__arrow live-wall-wall-switcher__arrow--mobile"
                            href="{{ route('live-wall.index', ['wall' => $previousWall?->slug]) }}"
                            wire:navigate
                            aria-label="Open previous wall"
                        >
                            <span aria-hidden="true">&#8249;</span>
                        </a>

                        <div class="live-wall-wall-switcher__mobile-status">
                            <span class="live-wall-wall-switcher__mobile-eyebrow">Live wall</span>
                            <strong class="live-wall-wall-switcher__wall-name">{{ $selectedWall->name }}</strong>
                            <span class="live-wall-wall-switcher__wall-meta">Wall {{ $selectedWallIndex + 1 }} of {{ $wallCount }}</span>
                        </div>

                        <a
                            class="live-wall-wall-switcher__arrow live-wall-wall-switcher__arrow--mobile"
                            href="{{ route('live-wall.index', ['wall' => $nextWall?->slug]) }}"
                            wire:navigate
                            aria-label="Open next wall"
                        >
                            <span aria-hidden="true">&#8250;</span>
                        </a>

                        <details class="live-wall-wall-switcher__mobile-drawer">
                            <summary class="live-wall-wall-switcher__mobile-toggle" aria-label="Open live wall controls">
                                <span>Controls</span>
                            </summary>

                            <div class="live-wall-wall-switcher__mobile-panel">
                                <div class="live-wall-wall-switcher__mobile-panel-header">
                                    <span class="live-wall-wall-switcher__mobile-eyebrow">Wall controls</span>
                                    <strong class="live-wall-wall-switcher__wall-name">{{ $selectedWall->name }}</strong>
                                    <span>Volume and navigation shortcuts</span>
                                </div>

                                <label class="live-wall-wall-switcher__volume live-wall-wall-switcher__volume--mobile" aria-label="Live wall volume">
                                    <span class="live-wall-wall-switcher__volume-label">Volume</span>
                                    <input
                                        class="live-wall-wall-switcher__volume-slider"
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="1"
                                        value="100"
                                        data-role="master-volume-slider"
                                        aria-label="Set live wall volume"
                                    >
                                    <span class="live-wall-wall-switcher__volume-value" data-role="master-volume-value">100%</span>
                                </label>

                                <div class="live-wall-wall-switcher__mobile-links">
                                    <a class="live-wall-wall-switcher__menu-link" href="{{ route('wall-tiles.index') }}" wire:navigate>Wall tiles</a>
                                    <a class="live-wall-wall-switcher__menu-link" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
                                    <a class="live-wall-wall-switcher__menu-link" href="{{ route('recordings.index') }}" wire:navigate>Recordings</a>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </nav>
        @endif
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer data-navigate-once></script>
@endpush