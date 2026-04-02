@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Operations Dashboard')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Control room')

@section('page_title', 'Operations dashboard')

@section('page_lead', 'Track fleet readiness, recorder health, and the next operator move from one board.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Run ONVIF sweep</a>
@endsection

@section('content')
    <section class="screen-grid">
        <section class="screen-card screen-card--accent screen-summary-strip">
            <div class="screen-summary-strip__body">
                <div>
                    <span class="eyebrow">Operator loop</span>
                    <p class="screen-summary-strip__copy">Discovery, fleet updates, stream validation, and monitoring still happen in that order.</p>
                </div>

                <span class="status-pill status-pill--neutral">{{ $recentCameras->count() }} recent updates</span>
            </div>

            <div class="screen-summary-strip__steps" aria-label="Operations workflow">
                <a class="screen-summary-strip__step" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>
                    <span class="screen-summary-strip__step-number">01</span>
                    <strong>Discover endpoints</strong>
                </a>

                <a class="screen-summary-strip__step" href="{{ route('camera-fleet.index') }}" wire:navigate>
                    <span class="screen-summary-strip__step-number">02</span>
                    <strong>Save the fleet</strong>
                </a>

                <a class="screen-summary-strip__step" href="{{ route('camera-fleet.index') }}" wire:navigate>
                    <span class="screen-summary-strip__step-number">03</span>
                    <strong>Validate streams</strong>
                </a>

                <a class="screen-summary-strip__step" href="{{ route('live-wall.index') }}" wire:navigate>
                    <span class="screen-summary-strip__step-number">04</span>
                    <strong>Open live wall</strong>
                </a>
            </div>
        </section>

        <div class="dashboard-stats">
            @foreach ($metricCards as $card)
                <article class="metric-card metric-card--{{ $card['tone'] }}">
                    <div class="metric-card__icon">{{ $card['icon'] }}</div>

                    <div class="metric-card__body">
                        <p class="metric-card__value">{{ $card['value'] }}</p>
                        <p class="metric-card__label">{{ $card['label'] }}</p>
                        <p class="metric-card__detail">{{ $card['detail'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="dashboard-secondary">
            <article class="dashboard-panel dashboard-panel--wide">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Recorder runtime</h3>
                        <p class="panel-copy">This check uses resolved ffmpeg and ffprobe binaries plus the writable temp workspace used for diagnostics and previews.</p>
                    </div>

                    <span class="status-pill status-pill--{{ $recorderStatus['is_ready'] ? 'good' : 'warn' }}">{{ $recorderStatus['summary'] }}</span>
                </div>

                <div class="key-value-list">
                    <div class="key-value-row">
                        <span>ffmpeg binary</span>
                        <strong>{{ $recorderStatus['ffmpeg_binary'] ?? 'Not resolved' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>ffprobe binary</span>
                        <strong>{{ $recorderStatus['ffprobe_binary'] ?? 'Not resolved' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Temp workspace</span>
                        <strong>{{ $recorderStatus['temporary_directory'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Directory writable</span>
                        <strong>{{ $recorderStatus['temporary_directory_writable'] ? 'Yes' : 'No' }}</strong>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Fleet coverage</h3>
                        <p class="panel-copy">Saved camera records are the source of truth for discovery outcomes, stream defaults, and player availability.</p>
                    </div>
                </div>

                <div class="key-value-list">
                    <div class="key-value-row">
                        <span>Total saved cameras</span>
                        <strong>{{ $fleetSummary['total'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Enabled cameras</span>
                        <strong>{{ $fleetSummary['enabled'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>ONVIF capable</span>
                        <strong>{{ $fleetSummary['onvif'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>RTSP ready</span>
                        <strong>{{ $fleetSummary['rtsp'] }}</strong>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Camera health</h3>
                        <p class="panel-copy">Seen state comes from saved last-seen timestamps, so it improves as discovery, validation, and playback touch the fleet.</p>
                    </div>
                </div>

                <div class="key-value-list">
                    <div class="key-value-row">
                        <span>Seen in last 10 minutes</span>
                        <strong>{{ $healthBreakdown['recently_seen'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Offline by timestamp</span>
                        <strong>{{ $healthBreakdown['offline'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Never seen</span>
                        <strong>{{ $healthBreakdown['never_seen'] }}</strong>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Latest camera records</h3>
                        <p class="panel-copy">Recent changes from the fleet table. Discovery saves, camera edits, and stream tests surface here.</p>
                    </div>
                </div>

                @if ($recentCameras->isEmpty())
                    <div class="empty-state">
                        <strong>No cameras are saved yet.</strong>
                        <p>Run discovery, verify a device, then save it to the fleet before worrying about wall playback.</p>
                        <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Open ONVIF sweep</a>
                    </div>
                @else
                    <div class="entity-list">
                        @foreach ($recentCameras as $camera)
                            <article class="entity-row">
                                <div>
                                    <strong>{{ $camera->name }}</strong>
                                    <p>{{ $camera->local_ip }}{{ $camera->model ? ' · '.$camera->model : '' }}</p>
                                </div>

                                <span class="status-pill status-pill--{{ $camera->is_enabled ? 'good' : 'warn' }}">{{ $camera->is_enabled ? 'Enabled' : 'Disabled' }}</span>
                            </article>
                        @endforeach
                    </div>
                @endif
            </article>
        </div>
    </section>
@endsection