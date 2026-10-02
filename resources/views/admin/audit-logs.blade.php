@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Audit log')
@section('body_class', 'page-dashboard page-audit')
@section('page_eyebrow', 'Admin')
@section('page_title', 'Audit log')
@section('page_lead', 'See who changed what, follow recording activity, and investigate failed recordings.')

@section('content')
    <div class="screen-grid audit-log">
        <section class="screen-card audit-log__toolbar" aria-label="Activity filters">
            <nav class="audit-log__views" aria-label="Activity views">
                @foreach (['all' => 'All activity', 'users' => 'User actions', 'recordings' => 'Recordings', 'failures' => 'Recording failures'] as $value => $label)
                    <a class="button button--{{ $filters['activity'] === $value ? 'primary' : 'soft' }}" href="{{ route('admin.audit-logs.index', array_merge(request()->except(['page', 'subject_type', 'actor_type', 'event']), ['activity' => $value])) }}" wire:navigate @if ($filters['activity'] === $value) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <details class="audit-log__filter-panel" @if (collect($filters)->except('activity')->contains(fn ($value) => $value !== '' && $value !== 'all')) open @endif>
                <summary>Search and filter activity{{ collect($filters)->except('activity')->contains(fn ($value) => $value !== '' && $value !== 'all') ? ' · filters applied' : '' }}</summary>
                <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="audit-log__filter-form">
                    <input type="hidden" name="activity" value="{{ $filters['activity'] }}">
                    <div class="audit-log__filters">
                        <label class="field-stack audit-log__search">
                            <span>Search activity</span>
                            <input class="form-input" type="search" name="search" value="{{ $filters['search'] }}" maxlength="200" placeholder="Camera, person, changed item, or reason">
                        </label>
                        <label class="field-stack">
                            <span>Time range</span>
                            <select class="form-select" name="period">
                                @foreach (['all' => 'All retained history', 'day' => 'Last 24 hours', 'week' => 'Last 7 days', 'month' => 'Last 30 days'] as $value => $label)
                                    <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field-stack">
                            <span>Camera</span>
                            <select class="form-select" name="camera_id">
                                <option value="">All cameras</option>
                                @foreach ($cameraOptions as $id => $name)
                                    <option value="{{ $id }}" @selected($filters['camera_id'] === (string) $id)>{{ $name }}</option>
                                @endforeach
                                @if ($filters['camera_id'] !== '' && ! $cameraOptions->has($filters['camera_id']))
                                    <option value="{{ $filters['camera_id'] }}" selected>Camera #{{ $filters['camera_id'] }} (removed)</option>
                                @endif
                            </select>
                        </label>
                        <button class="button button--primary" type="submit">Apply filters</button>
                    </div>
                    <details class="audit-log__advanced" @if ($filters['subject_type'] || $filters['actor_type'] || $filters['source'] || $filters['event']) open @endif>
                        <summary>More filters{{ $filters['subject_type'] || $filters['actor_type'] || $filters['source'] || $filters['event'] ? ' · active' : '' }}</summary>
                        <div class="audit-log__filters">
                            @foreach (['subject_type' => ['Changed item', $subjectTypeOptions], 'actor_type' => ['Changed by', $actorTypeOptions], 'source' => ['Run from', $sourceOptions], 'event' => ['Event', $eventOptions]] as $key => [$label, $options])
                                <label class="field-stack">
                                    <span>{{ $label }}</span>
                                    <select class="form-select" name="{{ $key }}">
                                        <option value="">All</option>
                                        @foreach ($options as $value => $optionLabel)
                                            <option value="{{ $value }}" @selected($filters[$key] === $value)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    </details>
                    @if (collect($filters)->contains(fn ($value, $key) => $value !== '' && $value !== 'all'))
                        <a class="audit-log__clear" href="{{ route('admin.audit-logs.index') }}" wire:navigate>Clear all filters</a>
                    @endif
                </form>
            </details>
        </section>

        <section class="screen-card screen-card--spacious audit-log__feed">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">{{ number_format($auditLogs->total()) }} {{ $auditLogs->total() === 1 ? 'event' : 'events' }}{{ collect($filters)->contains(fn ($value) => $value !== '' && $value !== 'all') ? ' matching your filters' : ' in retained history' }}</h2>
                    <p class="panel-copy">Newest first · Times in {{ $timezone }} · Updated {{ $appSettings->formatDateTime($refreshedAt, 'H:i:s', false) }}</p>
                </div>
                <a class="button button--soft" href="{{ request()->fullUrl() }}" wire:navigate>Refresh activity</a>
                <span class="status-pill status-pill--neutral">Page {{ $auditLogs->currentPage() }} of {{ max(1, $auditLogs->lastPage()) }}</span>
            </div>
            <p class="audit-log__scope">{{ $retentionDays }}-day retention · Older entries are removed daily. <a href="{{ route('admin.settings.index') }}#audit-retention" wire:navigate>Change retention</a> · Refresh to load new events.</p>

            @if ($auditLogs->isEmpty())
                <div class="empty-state">
                    <strong>{{ $filters['activity'] === 'failures' ? 'No recording failures found in this view.' : 'No activity found in this view.' }}</strong>
                    <p>Try a wider time range or clear the filters. An empty history does not confirm that cameras or recording workers are healthy.</p>
                    <a class="button button--soft" href="{{ route('admin.audit-logs.index') }}" wire:navigate>Show all activity</a>
                </div>
            @else
                <div class="audit-log__entries">
                    @php($previousDate = null)
                    @foreach ($entries as $entry)
                        @php($audit = $entry->audit)
                        @php($date = $appSettings->formatDateTime($audit->created_at, 'l, j F Y', false))
                        @if ($date !== $previousDate)
                            <h3 class="audit-log__date">{{ $date }}</h3>
                            @php($previousDate = $date)
                        @endif
                        <article class="audit-entry audit-entry--{{ $entry->tone() }}" aria-labelledby="audit-title-{{ $audit->id }}">
                            <div class="audit-entry__header">
                                <div>
                                    <h4 id="audit-title-{{ $audit->id }}">{{ $entry->headline() }}</h4>
                                    <p class="audit-entry__subject">{{ $entry->cameraName() ? $entry->cameraName().' · ' : '' }}{{ $entry->subject() }}</p>
                                </div>
                                <time datetime="{{ $audit->created_at->toIso8601String() }}" title="{{ $appSettings->formatDateTime($audit->created_at, 'Y-m-d H:i:s T', false) }}">{{ $appSettings->formatDateTime($audit->created_at, 'H:i:s', false) }}</time>
                            </div>
                            <p class="audit-entry__context"><strong>{{ $audit->actor_type === 'system' ? 'System' : $audit->actor_label }}</strong><span aria-hidden="true"> · </span>{{ $entry->source() }}{{ $audit->actor_type === 'system' && $audit->source === 'http' ? ' · No signed-in user recorded' : '' }}</p>
                            <p class="audit-entry__explanation">{{ $entry->explanation() }}</p>
                            @if ($entry->reason())
                                <p class="audit-entry__reason"><strong>Reason:</strong> {{ $entry->reason() }}</p>
                            @endif
                            @if ($audit->event === 'updated' && count($audit->changeKeys()) <= 3)
                                <div class="audit-entry__preview">
                                    @foreach ($audit->changeKeys() as $key)
                                        <p><strong>{{ \App\Support\Audit\AuditLogPresenter::fieldLabel($key) }}:</strong> {{ $entry->value($key, $audit->old_values[$key] ?? null) }} <span aria-label="changed to">→</span> {{ $entry->value($key, $audit->new_values[$key] ?? null) }}</p>
                                    @endforeach
                                </div>
                            @endif
                            <details class="audit-entry__details">
                                <summary>View changes and technical details<span class="audit-entry__id">Event #{{ $audit->id }}</span></summary>
                                @if ($audit->changeKeys() !== [])
                                    <div class="audit-entry__changes" role="region" aria-label="Changes for event {{ $audit->id }}" tabindex="0">
                                        <table>
                                            <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
                                            <tbody>
                                                @foreach ($audit->changeKeys() as $key)
                                                    <tr><th scope="row">{{ \App\Support\Audit\AuditLogPresenter::fieldLabel($key) }}</th><td>{{ $entry->value($key, $audit->old_values[$key] ?? null) }}</td><td>{{ $entry->value($key, $audit->new_values[$key] ?? null) }}</td></tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <p>No field changes were stored for this event.</p>
                                @endif
                                @if ($audit->metadata)
                                    <dl class="audit-entry__facts">
                                        @foreach ($audit->metadata as $key => $value)
                                            <div><dt>{{ \App\Support\Audit\AuditLogPresenter::fieldLabel($key) }}</dt><dd>{{ $entry->value($key, $value) }}</dd></div>
                                        @endforeach
                                    </dl>
                                @endif
                                <dl class="audit-entry__facts">
                                    <div><dt>Event key</dt><dd>{{ $audit->event }}</dd></div>
                                    <div><dt>Subject ID</dt><dd>{{ $audit->auditable_id }}</dd></div>
                                    <div><dt>Source</dt><dd>{{ $audit->sourceLabel() }}</dd></div>
                                    <div><dt>User ID</dt><dd>{{ $audit->user_id ?? 'System event' }}</dd></div>
                                    <div><dt>IP address</dt><dd>{{ $audit->ip_address ?? 'Not captured' }}</dd></div>
                                    <div><dt>Browser / client</dt><dd>{{ $audit->user_agent ?? 'Not captured' }}</dd></div>
                                </dl>
                            </details>
                        </article>
                    @endforeach
                </div>
                @if ($auditLogs->hasPages())
                    @php($currentPage = $auditLogs->currentPage())
                    @php($lastPage = $auditLogs->lastPage())
                    @php($windowStart = max(1, $currentPage - 2))
                    @php($windowEnd = min($lastPage, $currentPage + 2))
                    <div class="recording-browser__pager">
                        <span class="recording-browser__pager-copy">Page {{ $auditLogs->currentPage() }} of {{ $auditLogs->lastPage() }}</span>

                        <nav class="recording-browser__pager-links" aria-label="Audit log pages">
                            @if ($auditLogs->onFirstPage())
                                <span class="button button--soft button--disabled" aria-disabled="true">Previous</span>
                            @else
                                <a class="button button--soft" href="{{ $auditLogs->previousPageUrl() }}" wire:navigate rel="prev">Previous</a>
                            @endif

                            @if ($windowStart > 1)
                                <a class="button button--soft" href="{{ $auditLogs->url(1) }}" wire:navigate>1</a>

                                @if ($windowStart > 2)
                                    <span class="recording-browser__pager-ellipsis" aria-hidden="true">…</span>
                                @endif
                            @endif

                            @for ($page = $windowStart; $page <= $windowEnd; $page++)
                                @if ($page === $currentPage)
                                    <span class="button button--primary button--disabled" aria-current="page" aria-disabled="true">{{ $page }}</span>
                                @else
                                    <a class="button button--soft" href="{{ $auditLogs->url($page) }}" wire:navigate>{{ $page }}</a>
                                @endif
                            @endfor

                            @if ($windowEnd < $lastPage)
                                @if ($windowEnd < $lastPage - 1)
                                    <span class="recording-browser__pager-ellipsis" aria-hidden="true">…</span>
                                @endif

                                <a class="button button--soft" href="{{ $auditLogs->url($lastPage) }}" wire:navigate>{{ $lastPage }}</a>
                            @endif

                            @if ($auditLogs->hasMorePages())
                                <a class="button button--soft" href="{{ $auditLogs->nextPageUrl() }}" wire:navigate rel="next">Next</a>
                            @else
                                <span class="button button--soft button--disabled" aria-disabled="true">Next</span>
                            @endif
                        </nav>
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
