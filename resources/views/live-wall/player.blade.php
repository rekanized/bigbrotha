@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | '.$camera->name)

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Monitoring')

@section('page_title', $camera->name)

@section('page_lead', 'This player is served by Laravel and requests a short-lived WebRTC access token before each MediaMTX session.')

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
                        <strong>Media relay not installed yet.</strong>
                        <p>Run <code>composer relay:install</code> and then <code>php artisan relay:start</code> on the host to install the shared WebRTC relay.</p>
                    </div>
                @elseif (!($relayStatus['running'] ?? false))
                    <div class="empty-state">
                        <strong>Media relay is configured but not running.</strong>
                        <p>The player will connect as soon as MediaMTX is running again.</p>
                    </div>
                @elseif (!is_array($liveSelection) || !is_string($sessionUrl) || $sessionUrl === '')
                    <div class="empty-state">
                        <strong>No live RTSP stream is ready.</strong>
                        <p>Refresh RTSP profiles or run a connection test from Camera Fleet to populate a playable stream.</p>
                    </div>
                @else
                    <div class="wall-tile__stream">
                        <div class="webrtc-player webrtc-player--single" data-webrtc-player data-session-url="{{ $sessionUrl }}" data-player-label="{{ $camera->name }}">
                            <video class="webrtc-player__video" data-role="video" autoplay muted playsinline controls></video>
                            <div class="webrtc-player__message" data-role="message">Loading secure stream…</div>
                        </div>
                    </div>
                @endif
            </div>

            <aside class="player-sidebar">
                <div class="screen-card">
                    <div class="panel-heading">
                        <div>
                            <h2 class="panel-title">Player session</h2>
                            <p class="panel-copy">Google-authenticated users receive Laravel-issued stream tokens per connection attempt.</p>
                        </div>
                    </div>

                    <div class="player-sidebar__meta">
                        <strong>{{ $camera->name }}</strong>
                        <span>{{ $camera->manufacturer ?: 'Unknown vendor' }}{{ $camera->model ? ' · '.$camera->model : '' }}</span>
                        <span>{{ $camera->local_ip }}</span>
                        @if (is_array($liveSelection))
                            <span>{{ $liveSelection['profile']['name'] ?? 'RTSP profile' }} · {{ $liveSelection['profile']['uri'] ?? $camera->rtspEndpoint() }}</span>
                        @endif
                        <span>Relay path {{ $webrtcPath }}</span>
                        @if (is_string($webrtcWhepUrl) && $webrtcWhepUrl !== '')
                            <span>WHEP {{ $webrtcWhepUrl }}</span>
                        @endif
                    </div>

                    <div class="wall-tile__actions">
                        @if (is_array($liveSelection))
                            <a class="button button--soft" href="{{ route('live-wall.relay', ['camera' => $camera, 'profileIndex' => $liveSelection['index'] ?? null]) }}" target="_blank" rel="noreferrer">Copy relay</a>
                        @endif

                        <a class="button button--primary" href="{{ route('camera-fleet.index') }}" wire:navigate>Manage camera</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer></script>
@endpush