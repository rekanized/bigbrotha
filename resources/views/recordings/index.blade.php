@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Recordings')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Recordings')

@section('page_title', 'Recordings browser')

@section('page_lead', 'Search saved recording segments, inspect recorder outcomes, and jump into the dedicated timeline review page when you need synchronized scrubbing across recorded cameras.')

@section('page_actions')
    <a class="button button--primary" href="{{ route('recordings.timeline') }}" wire:navigate>Open timeline review</a>
    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    <a class="button button--soft" href="{{ route('wall-tiles.index') }}" wire:navigate>Wall tiles</a>
@endsection

@section('content')
    <div class="recording-browser">
        <section class="screen-card screen-card--accent screen-summary-strip">
            <div class="screen-summary-strip__body">
                <div>
                    <span class="eyebrow">Recording workflow</span>
                    <p class="screen-summary-strip__copy">Use the recordings browser for fast searching and per-segment inspection. Use the separate timeline review page when you need cross-camera scrubbing with synchronized clip control.</p>
                </div>

                <div class="screen-summary-strip__steps" aria-label="Recordings workflow">
                    <span class="screen-summary-strip__step">
                        <span class="screen-summary-strip__step-number">01</span>
                        <strong>Search segments</strong>
                    </span>
                    <span class="screen-summary-strip__step">
                        <span class="screen-summary-strip__step-number">02</span>
                        <strong>Open timeline review</strong>
                    </span>
                    <span class="screen-summary-strip__step">
                        <span class="screen-summary-strip__step-number">03</span>
                        <strong>Download evidence</strong>
                    </span>
                </div>
            </div>
        </section>

        <div class="dashboard-stats">
            <article class="metric-card metric-card--blue">
                <div class="metric-card__icon">SG</div>
                <div class="metric-card__body">
                    <p class="metric-card__value">{{ $summary['total'] }}</p>
                    <p class="metric-card__label">All segments</p>
                    <p class="metric-card__detail">Every queued recording decision currently saved in the browser index.</p>
                </div>
            </article>

            <article class="metric-card metric-card--green">
                <div class="metric-card__icon">OK</div>
                <div class="metric-card__body">
                    <p class="metric-card__value">{{ $summary['recorded'] }}</p>
                    <p class="metric-card__label">Recorded</p>
                    <p class="metric-card__detail">Segments that completed and still point to saved footage on disk.</p>
                </div>
            </article>

            <article class="metric-card metric-card--amber">
                <div class="metric-card__icon">MV</div>
                <div class="metric-card__body">
                    <p class="metric-card__value">{{ $summary['motion'] }}</p>
                    <p class="metric-card__label">Motion clips</p>
                    <p class="metric-card__detail">Saved motion-triggered clips that crossed the configured detection threshold.</p>
                </div>
            </article>

            <article class="metric-card metric-card--violet">
                <div class="metric-card__icon">FL</div>
                <div class="metric-card__body">
                    <p class="metric-card__value">{{ $summary['failed'] }}</p>
                    <p class="metric-card__label">Failed</p>
                    <p class="metric-card__detail">Segments that need operator or host-side attention before footage can be trusted.</p>
                </div>
            </article>
        </div>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Search recordings</h2>
                    <p class="panel-copy">Filter by camera, status, capture mode, or recording date in {{ $displayTimezone }} to find the segment you need.</p>
                </div>
            </div>

            <form class="recording-browser__filters" method="GET" action="{{ route('recordings.index') }}">
                <label class="field-stack field-stack--wide">
                    <span>Search</span>
                    <input class="form-input" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Camera name, IP, path, or recorder message">
                </label>

                <label class="field-stack">
                    <span>Camera</span>
                    <select class="form-select" name="camera_id">
                        <option value="">All cameras</option>
                        @foreach ($cameraOptions as $cameraOption)
                            <option value="{{ $cameraOption->id }}" @selected($filters['camera_id'] === $cameraOption->id)>{{ $cameraOption->name }} · {{ $cameraOption->local_ip }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>Status</span>
                    <select class="form-select" name="status">
                        @foreach ($statusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>Capture mode</span>
                    <select class="form-select" name="mode">
                        @foreach ($modeOptions as $modeValue => $modeLabel)
                            <option value="{{ $modeValue }}" @selected($filters['mode'] === $modeValue)>{{ $modeLabel }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>From</span>
                    <input class="form-input" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </label>

                <label class="field-stack">
                    <span>To</span>
                    <input class="form-input" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </label>

                <div class="probe-actions recording-browser__filter-actions">
                    <button class="button button--primary" type="submit">Apply filters</button>
                    <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Reset</a>
                </div>
            </form>
        </section>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Matching segments</h2>
                    <p class="panel-copy">{{ $recordings->total() }} result{{ $recordings->total() === 1 ? '' : 's' }} matched the current search.</p>
                </div>
            </div>

            @if ($recordings->isEmpty())
                <div class="empty-state">
                    <strong>No recording segments matched the current filters.</strong>
                    <p>Broaden the date range, switch the status filter to all, or wait for the next scheduler tick to queue new work.</p>
                </div>
            @else
                <div class="recording-browser__list">
                    @foreach ($recordings as $recording)
                        @php($camera = $recording->camera)
                        @php($durationSeconds = $recording->started_at && $recording->ended_at ? $recording->ended_at->diffInSeconds($recording->started_at) : null)
                        <article class="screen-card recording-browser__row">
                            <div class="recording-browser__row-header">
                                <div>
                                    <span class="camera-row__label">{{ $appSettings->formatDateTime($recording->scheduled_for, 'Y-m-d H:i') ?? 'Pending' }}</span>
                                    <strong>{{ $camera?->name ?? 'Deleted camera' }}</strong>
                                    <p>{{ $camera?->local_ip ?? 'Unknown IP' }}{{ $camera?->hostname ? ' · '.$camera->hostname : '' }}</p>
                                </div>

                                <div class="badge-row">
                                    <span class="status-pill status-pill--{{ $recording->status === 'recorded' ? 'good' : ($recording->status === 'failed' ? 'warn' : 'neutral') }}">{{ ucfirst($recording->status) }}</span>
                                    <span class="status-pill">{{ $recording->capture_mode === 'motion' ? 'Record on movement' : 'Constantly recording' }}</span>
                                </div>
                            </div>

                            <div class="recording-browser__meta-grid">
                                <div class="camera-row__fact">
                                    <span>Duration</span>
                                    <strong>{{ $durationSeconds !== null ? $durationSeconds.' s' : 'Unavailable' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>File size</span>
                                    <strong>{{ $recording->file_size_bytes ? number_format($recording->file_size_bytes / 1048576, 2).' MB' : 'No file saved' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Motion score</span>
                                    <strong>{{ $recording->motion_score !== null ? $recording->motion_score : 'Not sampled' }}</strong>
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>Recorder message</span>
                                    <strong>{{ $recording->message ?? 'No recorder message stored.' }}</strong>
                                </div>
                            </div>

                            <div class="probe-actions">
                                <a class="button button--primary" href="{{ route('recordings.show', ['recording' => $recording]) }}" wire:navigate>{{ $recording->status === 'recorded' ? 'Open playback' : 'Open details' }}</a>
                                @if ($camera)
                                    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
                                @endif
                                @if ($recording->status === 'recorded' && $recording->relative_path)
                                    <a class="button button--soft" href="{{ route('recordings.download', ['recording' => $recording]) }}">Download file</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($recordings->hasPages())
                    <div class="recording-browser__pager">
                        @if ($recordings->onFirstPage())
                            <span class="button button--soft button--disabled" aria-disabled="true">Previous</span>
                        @else
                            <a class="button button--soft" href="{{ $recordings->previousPageUrl() }}" wire:navigate>Previous</a>
                        @endif

                        <span class="recording-browser__pager-copy">Page {{ $recordings->currentPage() }} of {{ $recordings->lastPage() }}</span>

                        @if ($recordings->hasMorePages())
                            <a class="button button--soft" href="{{ $recordings->nextPageUrl() }}" wire:navigate>Next</a>
                        @else
                            <span class="button button--soft button--disabled" aria-disabled="true">Next</span>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection