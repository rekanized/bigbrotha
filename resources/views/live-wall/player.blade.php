@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | '.$camera->name)

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Monitoring')

@section('page_title', $camera->name)

@section('page_lead', 'This player is served by Laravel and requests a short-lived WebRTC token before each MediaMTX session bootstrap.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('live-wall.index') }}" wire:navigate>Back to wall</a>
@endsection

@section('content')
    <section class="screen-card screen-card--spacious">
        <div class="player-layout">
            <div class="player-panel">
                @if (!($relayStatus['installed'] ?? false))
                    <div class="empty-state">
                        <strong>Media relay binary is missing from the app container.</strong>
                        <p>Rebuild and restart the Docker app service so the configured MediaMTX binary is present before opening the player again.</p>
                    </div>
                @elseif (!($relayStatus['running'] ?? false))
                    <div class="empty-state">
                        <strong>Media relay is configured but not running.</strong>
                        <p>The player will connect as soon as MediaMTX is running again.</p>
                    </div>
                @elseif (!is_array($liveSelection) || !is_string($sessionUrl) || $sessionUrl === '')
                    <div class="empty-state">
                        <strong>No live RTSP stream is ready.</strong>
                        <p>This player retries configured live feeds automatically every 15 seconds. Save or verify a direct RTSP path in Camera Fleet first if this camera still has no retryable live path.</p>
                    </div>
                @else
                    <div class="wall-tile__stream">
                        <div
                            class="webrtc-player webrtc-player--single"
                            data-webrtc-player
                            data-session-url="{{ $sessionUrl }}"
                            data-player-label="{{ $camera->name }}"
                            data-expected-video-codec="{{ $streamFormat['video_codec'] ?? 'h264' }}"
                            data-expected-audio-codec="{{ $streamFormat['audio_codec'] ?? 'opus' }}"
                            data-expected-audio-channels="{{ $streamFormat['audio_channels'] ?? 2 }}"
                            data-expected-audio-sample-rate="{{ $streamFormat['audio_sample_rate'] ?? 48000 }}"
                        >
                            <video class="webrtc-player__video" data-role="video" autoplay muted playsinline controls preload="none"></video>
                            <div class="webrtc-player__message" data-role="message" aria-live="polite">Connecting to secure stream...</div>
                            <div class="wall-tile__actions">
                                <button class="button button--soft" type="button" data-role="audio-toggle" aria-pressed="false" aria-label="Listen to {{ $camera->name }}">
                                    <span data-role="audio-toggle-label">Enable audio</span>
                                </button>
                                <span class="panel-copy" data-role="audio-indicator" aria-live="polite">Muted</span>
                            </div>
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

                    @if (is_array($liveSelection))
                        <div class="wall-tile__actions">
                            <a class="button button--soft" href="{{ route('live-wall.relay', ['camera' => $camera, 'profileIndex' => $liveSelection['index'] ?? null]) }}" target="_blank" rel="noreferrer">Open relay</a>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer data-navigate-once></script>
@endpush
