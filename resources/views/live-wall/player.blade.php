@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | '.$camera->name)

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Monitoring')

@section('page_title', $camera->name)

@section('page_lead', 'This player is served by Laravel and requests a short-lived WebRTC token before each MediaMTX session bootstrap.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('live-wall.index') }}" wire:navigate>Back to wall</a>
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
@endsection

@section('content')
    <section class="screen-card screen-card--spacious">
        <div class="player-layout">
            <div class="player-panel">
                @if (!($relayStatus['installed'] ?? false))
                    <div class="empty-state">
                        <strong>Media relay binary is missing from the app container.</strong>
                        <p>Rebuild and restart the Docker app service so the bundled MediaMTX binary is present before opening the player again.</p>
                    </div>
                @elseif (!($relayStatus['running'] ?? false))
                    <div class="empty-state">
                        <strong>Media relay is configured but not running.</strong>
                        <p>The player will connect as soon as MediaMTX is running again.</p>
                    </div>
                @elseif (!is_array($liveSelection) || !is_string($sessionUrl) || $sessionUrl === '')
                    <div class="empty-state">
                        <strong>No live RTSP stream is ready.</strong>
                        <p>Refresh stream profiles or run a stream test from Camera Fleet to populate a playable stream.</p>
                    </div>
                @else
                    <div class="wall-tile__stream">
                        <div class="webrtc-player webrtc-player--single" data-webrtc-player data-session-url="{{ $sessionUrl }}" data-player-label="{{ $camera->name }}">
                            <video class="webrtc-player__video" data-role="video" autoplay muted playsinline controls></video>
                            <div class="webrtc-player__message" data-role="message" aria-live="polite">Connecting to secure stream...</div>
                        </div>
                    </div>
                @endif
            </div>

            <aside class="player-sidebar">
                <div class="screen-card">
                    <div class="panel-heading">
                        <div>
                            <h2 class="panel-title">Player session</h2>
                            <p class="panel-copy">Google-authenticated users receive Laravel-issued stream tokens per connection attempt and camera path.</p>
                        </div>
                    </div>

                    <div class="key-value-list key-value-list--dense">
                        <div class="key-value-row">
                            <span>Camera</span>
                            <strong>{{ $camera->name }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Identity</span>
                            <strong>{{ $camera->manufacturer ?: 'Unknown vendor' }}{{ $camera->model ? ' · '.$camera->model : '' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Local IP</span>
                            <strong>{{ $camera->local_ip }}</strong>
                        </div>

                        @if (is_array($liveSelection))
                            <div class="key-value-row">
                                <span>Profile</span>
                                <strong>{{ $liveSelection['profile']['name'] ?? 'RTSP profile' }}</strong>
                            </div>

                            <div class="key-value-row">
                                <span>Selected URI</span>
                                <strong>{{ $liveSelection['profile']['uri'] ?? $camera->rtspEndpoint() }}</strong>
                            </div>
                        @endif

                        <div class="key-value-row">
                            <span>Relay path</span>
                            <strong>{{ $webrtcPath }}</strong>
                        </div>

                        @if (is_string($webrtcWhepUrl) && $webrtcWhepUrl !== '')
                            <div class="key-value-row">
                                <span>WHEP URL</span>
                                <strong>{{ $webrtcWhepUrl }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="wall-tile__actions">
                        @if (is_array($liveSelection))
                            <a class="button button--soft" href="{{ route('live-wall.relay', ['camera' => $camera, 'profileIndex' => $liveSelection['index'] ?? null]) }}" target="_blank" rel="noreferrer">Open relay</a>
                        @endif

                        <a class="button button--primary" href="{{ route('camera-fleet.index') }}" wire:navigate>Manage camera</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer data-navigate-once></script>
@endpush