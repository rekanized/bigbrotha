@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | '.$camera->name)

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Monitoring')

@section('page_title', $camera->name)

@section('page_lead', 'Watch this camera live. Use the player controls for sound, playback, and fullscreen.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('live-wall.index') }}" wire:navigate>Back to wall</a>
@endsection

@section('content')
    <section class="screen-card screen-card--spacious">
        <div class="player-layout">
            <div class="player-panel">
                @include('live-wall.partials.single-player')
            </div>

            <aside class="player-sidebar">
                <div class="screen-card">
                    <div class="panel-heading">
                        <div>
                            <h2 class="panel-title">Player session</h2>
                            <p class="panel-copy">Stream access is limited to signed-in operators.</p>
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
