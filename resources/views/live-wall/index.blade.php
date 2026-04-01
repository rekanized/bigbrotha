@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Live Wall')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Monitoring')

@section('page_title', 'Live wall')

@section('page_lead', 'The wall now uses a shared WebRTC relay with Laravel-issued session tokens, so authenticated operators can watch the same camera without exposing raw player pages to the public internet.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Discover devices</a>
@endsection

@section('content')
    <section class="screen-card screen-card--spacious">
        <div class="panel-heading">
            <div>
                <h2 class="panel-title">Shared WebRTC wall</h2>
                <p class="panel-copy">Each enabled camera is mapped onto a shared MediaMTX relay path. The wall prefers lower-cost RTSP substreams where available, then transcodes once per active camera into a browser-safe WebRTC feed.</p>
            </div>
        </div>

        @if (!($relayStatus['installed'] ?? false))
            <div class="empty-state">
                <strong>Media relay not installed yet.</strong>
                <p>Run <code>composer relay:install</code> and then <code>php artisan relay:start</code> on the host to install the shared WebRTC relay.</p>
            </div>
        @elseif (!($relayStatus['running'] ?? false))
            <div class="empty-state">
                <strong>Media relay is configured but not running.</strong>
                <p>The wall will show players as soon as MediaMTX is running. Start it with <code>php artisan relay:start</code> or reload after checking the relay log.</p>
            </div>
        @endif

        @if ($tiles->isEmpty())
            <div class="empty-state">
                <strong>No enabled cameras are available for the wall.</strong>
                <p>Add camera records or run discovery first so the wall has devices to render.</p>
                <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Run ONVIF sweep</a>
            </div>
        @else
            <div class="live-wall-grid">
                @foreach ($tiles as $tile)
                    @php
                        /** @var \App\Models\Camera $camera */
                        $camera = $tile['camera'];
                        $liveSelection = $tile['liveSelection'];
                        $selectedProfile = $liveSelection['profile'] ?? null;
                        $selectedProfileIndex = $liveSelection['index'] ?? null;
                        $playerPageUrl = $tile['playerPageUrl'] ?? null;
                        $sessionUrl = $tile['sessionUrl'] ?? null;
                        $webrtcPath = $tile['webrtcPath'] ?? null;
                        $latestPreview = $camera->latestRtspPreview();
                        $previewIndex = $latestPreview['index'] ?? null;
                        $previewProfile = $latestPreview['profile'] ?? null;
                    @endphp
                    <article class="wall-tile">
                        <div class="wall-tile__header">
                            <div>
                                <span class="status-pill status-pill--good">{{ $camera->name }}</span>
                                <div class="badge-row wall-tile__meta-row">
                                    @if (is_array($selectedProfile))
                                        <span class="status-pill status-pill--neutral">{{ $selectedProfile['name'] ?? 'RTSP profile' }}</span>
                                        @if (($selectedProfile['video_resolution'] ?? $selectedProfile['resolution'] ?? null))
                                            <span class="status-pill status-pill--neutral">{{ $selectedProfile['video_resolution'] ?? $selectedProfile['resolution'] }}</span>
                                        @endif
                                        @if (($selectedProfile['video_codec'] ?? $selectedProfile['encoding'] ?? null))
                                            <span class="status-pill status-pill--neutral">{{ strtoupper($selectedProfile['video_codec'] ?? $selectedProfile['encoding']) }}</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <span class="wall-tile__network">{{ $camera->local_ip }}</span>
                        </div>

                        @if (is_array($selectedProfile) && is_string($sessionUrl) && $sessionUrl !== '')
                            <div class="wall-tile__stream">
                                <div class="webrtc-player" data-webrtc-player data-session-url="{{ $sessionUrl }}" data-player-label="{{ $camera->name }}">
                                    <video class="webrtc-player__video" data-role="video" autoplay muted playsinline></video>
                                    <div class="webrtc-player__message" data-role="message">Loading secure stream…</div>
                                </div>
                            </div>
                        @elseif (is_array($previewProfile) && $previewIndex !== null)
                            <a class="wall-tile__stream" href="{{ route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => $previewIndex]) }}" target="_blank" rel="noreferrer">
                                <img
                                    class="wall-tile__stream-image"
                                    src="{{ route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => $previewIndex]) }}"
                                    alt="Latest saved preview for {{ $camera->name }}"
                                >
                            </a>
                        @else
                            <div class="wall-tile__empty">
                                <strong>No live RTSP stream is ready.</strong>
                                <p>Refresh RTSP profiles or run a connection test from Camera Fleet to populate a stream and preview.</p>
                            </div>
                        @endif

                        <div class="wall-tile__body">
                            <p>{{ $camera->manufacturer ?: 'Unknown vendor' }}{{ $camera->model ? ' · '.$camera->model : '' }}</p>
                            <strong>{{ $selectedProfile['uri'] ?? $camera->rtspEndpoint() ?? 'No RTSP endpoint saved' }}</strong>
                        </div>

                        <div class="wall-tile__actions">
                            @if (is_string($playerPageUrl) && $playerPageUrl !== '')
                                <a class="button button--soft" href="{{ $playerPageUrl }}" wire:navigate>Open secure player</a>
                            @endif

                            @if (is_array($selectedProfile))
                                <a class="button button--soft" href="{{ route('live-wall.relay', ['camera' => $camera, 'profileIndex' => $selectedProfileIndex]) }}" target="_blank" rel="noreferrer">Copy relay</a>
                            @endif

                            @if (is_array($previewProfile) && $previewIndex !== null)
                                <a class="button button--soft" href="{{ route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => $previewIndex]) }}" target="_blank" rel="noreferrer">Latest snapshot</a>
                            @endif

                            <a class="button button--primary" href="{{ route('camera-fleet.index') }}" wire:navigate>Manage camera</a>
                        </div>

                        <div class="wall-tile__footer">
                            <span>{{ $camera->onvifEndpoint() ?? 'No ONVIF endpoint saved' }}</span>
                            <span>{{ $camera->last_seen_at?->diffForHumans() ?? 'Never seen' }}</span>
                            @if (is_array($selectedProfile))
                                <span>Relay path {{ $webrtcPath ?? 'unavailable' }} transcodes once for WebRTC and can fan out to multiple viewers.</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer></script>
@endpush