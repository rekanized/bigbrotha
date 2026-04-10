@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Audit log')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Admin')

@section('page_title', 'Audit log')

@section('page_lead', 'Review model changes, recording state transitions, and the actor context captured for each audited event.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('admin.users.index') }}" wire:navigate>Operator access</a>
    <a class="button button--soft" href="{{ route('admin.settings.index') }}" wire:navigate>Application settings</a>
@endsection

@section('content')
    <div class="screen-grid">
        <section class="screen-card screen-card--accent screen-summary-strip">
            <div class="screen-summary-strip__body">
                <div>
                    <span class="eyebrow">Audit</span>
                    <p class="screen-summary-strip__copy">{{ $auditLogs->total() }} audit entr{{ $auditLogs->total() === 1 ? 'y' : 'ies' }} matched the current filters.</p>
                </div>

                <span class="status-pill status-pill--neutral">Page {{ $auditLogs->currentPage() }} of {{ max(1, $auditLogs->lastPage()) }}</span>
            </div>
        </section>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Filter audit entries</h2>
                    <p class="panel-copy">Search by actor, event, subject type, source, IP address, or numeric identifiers.</p>
                </div>
            </div>

            <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="recording-browser__filters">
                <label class="field-stack">
                    <span>Search</span>
                    <input class="form-input" type="search" name="search" value="{{ $filters['search'] }}" placeholder="event, actor, IP, id">
                </label>

                <label class="field-stack">
                    <span>Subject type</span>
                    <select class="form-select" name="subject_type">
                        <option value="">All subjects</option>
                        @foreach ($subjectTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['subject_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>Actor type</span>
                    <select class="form-select" name="actor_type">
                        <option value="">All actors</option>
                        @foreach ($actorTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['actor_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>Source</span>
                    <select class="form-select" name="source">
                        <option value="">All sources</option>
                        @foreach ($sourceOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-stack">
                    <span>Event</span>
                    <select class="form-select" name="event">
                        <option value="">All events</option>
                        @foreach ($eventOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['event'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="probe-actions recording-browser__filter-actions">
                    <button class="button button--primary" type="submit">Apply filters</button>
                    <a class="button button--soft" href="{{ route('admin.audit-logs.index') }}" wire:navigate>Reset</a>
                </div>
            </form>
        </section>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Recent audit entries</h2>
                    <p class="panel-copy">Each entry shows the subject, actor, source, stored field deltas, and any event-specific metadata.</p>
                </div>
            </div>

            @if ($auditLogs->isEmpty())
                <div class="empty-state">
                    <strong>No audit entries matched the current filters.</strong>
                    <p>Clear one or more filters above or perform an admin or recording action to generate new audit history.</p>
                </div>
            @else
                <div class="recording-browser__list">
                    @foreach ($auditLogs as $audit)
                        @php($changeKeys = $audit->changeKeys())
                        @php($metadata = is_array($audit->metadata) ? $audit->metadata : [])
                        <article class="screen-card recording-browser__row">
                            <div class="recording-browser__row-header">
                                <div>
                                    <span class="camera-row__label">{{ $appSettings->formatDateTime($audit->created_at, 'Y-m-d H:i:s') ?? 'Unavailable' }}</span>
                                    <strong>{{ $audit->eventLabel() }}</strong>
                                    <p>{{ $audit->subjectTypeLabel() }} · {{ $audit->subjectReference() }}</p>
                                </div>

                                <div class="badge-row">
                                    <span class="status-pill status-pill--neutral">{{ $audit->sourceLabel() }}</span>
                                    <span class="status-pill status-pill--{{ $audit->actor_type === \App\Models\AuditLog::ACTOR_TYPE_USER ? 'good' : 'neutral' }}">{{ $audit->actor_label }}</span>
                                </div>
                            </div>

                            <div class="recording-browser__meta-grid">
                                <div class="camera-row__fact">
                                    <span>Actor</span>
                                    <strong>{{ $audit->actor_label }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Subject</span>
                                    <strong>{{ $audit->subjectReference() }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Event key</span>
                                    <strong>{{ $audit->event }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Source</span>
                                    <strong>{{ $audit->sourceLabel() }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>IP address</span>
                                    <strong>{{ $audit->ip_address ?? 'Not captured' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>User ID</span>
                                    <strong>{{ $audit->user_id ?? 'System event' }}</strong>
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>Changed fields</span>
                                    <strong>{{ $changeKeys === [] ? 'No field delta stored' : implode(', ', $changeKeys) }}</strong>
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>Delta</span>
                                    @if ($changeKeys === [])
                                        <strong>No old/new field values were stored for this entry.</strong>
                                    @else
                                        <div class="key-value-list key-value-list--dense">
                                            @foreach ($changeKeys as $key)
                                                <div class="key-value-row">
                                                    <span>{{ $key }}</span>
                                                    <strong>{{ \App\Models\AuditLog::displayValue($audit->old_values[$key] ?? null) }} -> {{ \App\Models\AuditLog::displayValue($audit->new_values[$key] ?? null) }}</strong>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>User agent</span>
                                    <strong>{{ $audit->user_agent ?? 'Not captured' }}</strong>
                                </div>

                                @if ($metadata !== [])
                                    <div class="camera-row__fact camera-row__fact--wide">
                                        <span>Metadata</span>
                                        <div class="key-value-list key-value-list--dense">
                                            @foreach ($metadata as $key => $value)
                                                <div class="key-value-row">
                                                    <span>{{ $key }}</span>
                                                    <strong>{{ \App\Models\AuditLog::displayValue($value) }}</strong>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($auditLogs->hasPages())
                    <div class="recording-browser__pager">
                        @if ($auditLogs->onFirstPage())
                            <span class="button button--soft button--disabled" aria-disabled="true">Previous</span>
                        @else
                            <a class="button button--soft" href="{{ $auditLogs->previousPageUrl() }}" wire:navigate>Previous</a>
                        @endif

                        <span class="recording-browser__pager-copy">Page {{ $auditLogs->currentPage() }} of {{ $auditLogs->lastPage() }}</span>

                        @if ($auditLogs->hasMorePages())
                            <a class="button button--soft" href="{{ $auditLogs->nextPageUrl() }}" wire:navigate>Next</a>
                        @else
                            <span class="button button--soft button--disabled" aria-disabled="true">Next</span>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection