@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Admin users')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Admin')

@section('page_title', 'Current users')

@section('page_lead', 'Review authenticated operators, admin access, and the Google-linked identities currently stored in the application.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('admin.settings.index') }}" wire:navigate>Application settings</a>
    <a class="button button--soft" href="{{ route('dashboard') }}" wire:navigate>Overview</a>
@endsection

@section('content')
    <div class="screen-grid">
        @if (session('status') || session('status_error'))
            <section class="screen-card screen-card--accent screen-summary-strip">
                <div class="screen-summary-strip__body">
                    <div>
                        <span class="eyebrow">Admin</span>
                        <p class="screen-summary-strip__copy">{{ session('status') ?? session('status_error') }}</p>
                    </div>

                    <span class="status-pill status-pill--{{ session('status_error') ? 'warn' : 'good' }}">{{ session('status_error') ? 'Not changed' : 'Updated' }}</span>
                </div>
            </section>
        @endif

        <section class="screen-card screen-card--accent screen-summary-strip">
            <div class="screen-summary-strip__body">
                <div>
                    <span class="eyebrow">Admin</span>
                    <p class="screen-summary-strip__copy">Use this screen to verify which operators can access the system and which account currently holds admin privileges.</p>
                </div>

                <span class="status-pill status-pill--neutral">{{ $users->count() }} user{{ $users->count() === 1 ? '' : 's' }}</span>
            </div>
        </section>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Saved operators</h2>
                    <p class="panel-copy">Accounts are created or updated through Google sign-in. The first authenticated operator becomes admin until another admin is assigned directly in storage.</p>
                </div>
            </div>

            @if ($users->isEmpty())
                <div class="empty-state">
                    <strong>No users are stored yet.</strong>
                    <p>Once an operator signs in with Google, their account will appear here.</p>
                </div>
            @else
                @php($adminCount = $users->where('is_admin', true)->count())
                <div class="recording-browser__list">
                    @foreach ($users as $user)
                        <article class="screen-card recording-browser__row">
                            <div class="recording-browser__row-header">
                                <div>
                                    <span class="camera-row__label">{{ $user->is_admin ? 'Admin operator' : 'Standard operator' }}</span>
                                    <strong>{{ $user->name }}</strong>
                                    <p>{{ $user->email }}</p>
                                </div>

                                <div class="badge-row">
                                    <span class="status-pill status-pill--{{ $user->is_admin ? 'good' : 'neutral' }}">{{ $user->is_admin ? 'Admin' : 'Operator' }}</span>
                                    <span class="status-pill">{{ $user->google_id ? 'Google linked' : 'Password only' }}</span>
                                </div>
                            </div>

                            <div class="recording-browser__meta-grid">
                                <div class="camera-row__fact">
                                    <span>Email verified</span>
                                    <strong>{{ $user->email_verified_at ? ($appSettings->formatDateTime($user->email_verified_at, 'Y-m-d H:i:s') ?? 'Verified') : 'No' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Created</span>
                                    <strong>{{ $appSettings->formatDateTime($user->created_at, 'Y-m-d H:i:s') ?? 'Unavailable' }}</strong>
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>Auth source</span>
                                    <strong>{{ $user->google_id ? 'Google OAuth · '.$user->google_id : 'Local password credential' }}</strong>
                                </div>
                            </div>

                            <div class="probe-actions">
                                <form method="POST" action="{{ route('admin.users.admin-role', ['user' => $user]) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_admin" value="{{ $user->is_admin ? '0' : '1' }}">
                                    <button
                                        class="button {{ $user->is_admin ? 'button--soft' : 'button--primary' }}"
                                        type="submit"
                                        @if ($user->is_admin && $adminCount <= 1) disabled aria-disabled="true" @endif
                                    >
                                        {{ $user->is_admin ? 'Remove admin access' : 'Promote to admin' }}
                                    </button>
                                </form>

                                @if ($user->id === auth()->id())
                                    <span class="status-pill status-pill--neutral">Current operator</span>
                                @endif

                                @if ($user->is_admin && $adminCount <= 1)
                                    <span class="status-pill status-pill--warn">Last admin</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection