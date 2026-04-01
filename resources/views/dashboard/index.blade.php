@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Operations Dashboard')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Control room')

@section('page_title', 'Operations dashboard')

@section('page_lead', 'Live counts now come directly from the saved camera fleet and the recorder runtime instead of static placeholders.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Run ONVIF sweep</a>
@endsection

@section('content')
    <section class="dashboard-frame page-card">
        <div class="dashboard-frame__header">
            <div class="dashboard-frame__headline">
                <span class="eyebrow">Live overview</span>
                <h2 class="dashboard-frame__title">Current readiness snapshot</h2>
            </div>

            <div class="dashboard-frame__meta">
                <span class="status-pill status-pill--{{ $recorderStatus['is_ready'] ? 'good' : 'warn' }}">Recorder {{ strtolower($recorderStatus['summary']) }}</span>
                <span class="status-pill">{{ $recentCameras->count() }} recently updated camera records</span>
            </div>
        </div>

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
            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Recorder status</h3>
                        <p class="panel-copy">The recorder check is based on the current ffmpeg and ffprobe binary resolution plus the writable temp workspace used for processing.</p>
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
                        <h3 class="panel-title">Camera health</h3>
                        <p class="panel-copy">Seen state is calculated from the persisted last-seen timestamps on camera records, so this becomes useful as soon as discovery or sync starts saving data.</p>
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
                        <p class="panel-copy">Recent changes from the camera fleet table. This view will fill in as devices are discovered and saved.</p>
                    </div>
                </div>

                @if ($recentCameras->isEmpty())
                    <div class="empty-state">
                        <strong>No cameras are saved yet.</strong>
                        <p>Run the ONVIF sweep first, then start saving discovered devices into the fleet.</p>
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

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Next operator step</h3>
                        <p class="panel-copy">The first discovery workflow is now available. Once devices are visible on the network, the next step is saving them into the fleet and wiring stream validation.</p>
                    </div>
                </div>

                <div class="action-stack">
                    <a class="button button--primary" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Discover ONVIF devices</a>
                    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Review camera fleet</a>
                    <a class="button button--soft" href="{{ route('live-wall.index') }}" wire:navigate>Open live wall</a>
                </div>
            </article>
        </div>
    </section>
@endsection