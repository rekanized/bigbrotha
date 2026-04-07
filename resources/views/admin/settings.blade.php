@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Admin settings')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Admin')

@section('page_title', 'Admin settings')

@section('page_lead', 'Manage operator-facing application settings and verify recorder runtime prerequisites without touching deployment config files.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Recordings</a>
@endsection

@section('content')
    <div class="screen-grid">
        @if (session('status'))
            <section class="screen-card screen-card--accent screen-summary-strip">
                <div class="screen-summary-strip__body">
                    <div>
                        <span class="eyebrow">Saved</span>
                        <p class="screen-summary-strip__copy">{{ session('status') }}</p>
                    </div>

                    <span class="status-pill status-pill--good">Applied</span>
                </div>
            </section>
        @endif

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Regional settings</h2>
                    <p class="panel-copy">Stored timestamps remain in UTC for recording integrity. Operator-facing dates and times are converted to the selected display timezone.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf
                @method('PUT')

                <div class="inline-action-form-row">
                    <label class="field-stack field-stack--wide">
                        <span>Display timezone</span>
                        <select class="form-select" name="app_timezone">
                            @foreach ($timezoneOptions as $timezoneValue => $timezoneLabel)
                                <option value="{{ $timezoneValue }}" @selected(old('app_timezone', $currentTimezone) === $timezoneValue)>{{ $timezoneLabel }}</option>
                            @endforeach
                        </select>
                        @error('app_timezone')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="probe-form-grid__actions">
                        <button class="button button--primary" type="submit">Save settings</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="dashboard-secondary">
            <article class="dashboard-panel dashboard-panel--wide">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Recorder runtime</h3>
                        <p class="panel-copy">This check uses resolved ffmpeg and ffprobe binaries plus the writable temp workspace used for diagnostics, previews, and recording review assets.</p>
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
                        <h3 class="panel-title">Active timezone</h3>
                        <p class="panel-copy">This is the timezone currently used by dashboards, recordings pages, timeline review labels, and other operator-visible timestamps.</p>
                    </div>
                </div>

                <div class="key-value-list">
                    <div class="key-value-row">
                        <span>Configured timezone</span>
                        <strong>{{ $currentTimezone }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Current operator time</span>
                        <strong>{{ $currentTimeLabel }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>PHP runtime time</span>
                        <strong>{{ $serverTimeLabel }}</strong>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-title">Admin notes</h3>
                        <p class="panel-copy">The first signed-in operator becomes admin automatically if the system has no admin account yet.</p>
                    </div>
                </div>

                <div class="empty-state empty-state--compact">
                    <strong>Timezone changes are immediate for new requests.</strong>
                    <p>Any open pages may need a refresh to pick up the updated display timezone. Recording retention, scheduler timing, and private storage paths still use UTC internally. Recorder runtime status here is read-only and reflects the current server configuration.</p>
                </div>
            </article>
        </div>
    </div>
@endsection