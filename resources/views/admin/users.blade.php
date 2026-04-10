@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Operator access')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Admin')

@section('page_title', 'Operator access')

@section('page_lead', 'Review approved Google sign-in emails, authenticated operators, and admin access from one admin screen.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('admin.audit-logs.index') }}" wire:navigate>Audit log</a>
    <a class="button button--soft" href="{{ route('admin.settings.index') }}" wire:navigate>Application settings</a>
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
                    <p class="screen-summary-strip__copy">Use this screen to control which Google email addresses can sign in and which stored operators hold admin access.</p>
                </div>

                <span class="status-pill status-pill--neutral">{{ $allowedLoginEmails->count() }} allowed · {{ $users->count() }} user{{ $users->count() === 1 ? '' : 's' }}</span>
            </div>
        </section>

        <section class="screen-card screen-card--spacious">
            @php($usersByEmail = $users->keyBy(fn ($user) => \App\Models\AllowedLoginEmail::normalizeEmail($user->email)))
            @php($allowedLoginEmailsByEmail = $allowedLoginEmails->keyBy('email'))
            @php($pendingAllowedLoginEmails = $allowedLoginEmails->filter(fn ($allowedLoginEmail) => !$usersByEmail->has($allowedLoginEmail->email))->values())
            @php($adminCount = $users->where('is_admin', true)->count())

            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Pending Google sign-in emails</h2>
                    <p class="panel-copy">Use this list to pre-approve operators before their first Google sign-in. Once an approved email has signed in, manage its access from the saved operator card below instead of duplicating it here.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.users.allowed-emails.store') }}">
                @csrf

                <div class="inline-action-form-row">
                    <label class="field-stack field-stack--wide">
                        <span>Google email address</span>
                        <input
                            class="form-input"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            placeholder="operator@example.com"
                            autocomplete="email"
                            required
                        >
                        @error('email')
                            <small>{{ $message }}</small>
                        @enderror
                    </label>

                    <div class="probe-form-grid__actions">
                        <button class="button button--primary" type="submit">Allow sign-in email</button>
                    </div>
                </div>
            </form>

            @if ($pendingAllowedLoginEmails->isEmpty())
                <div class="empty-state">
                    <strong>No pending Google sign-in emails.</strong>
                    <p>Approved operators move into the saved list below after their first login. Use the form above when you need to pre-approve someone who has not signed in yet.</p>
                </div>
            @else
                <div class="recording-browser__list">
                    @foreach ($pendingAllowedLoginEmails as $allowedLoginEmail)
                        <article class="screen-card recording-browser__row">
                            <div class="recording-browser__row-header">
                                <div>
                                    <span class="camera-row__label">Pending Google account</span>
                                    <strong>{{ $allowedLoginEmail->email }}</strong>
                                    <p>No stored operator has used this email yet.</p>
                                </div>

                                <div class="badge-row">
                                    <span class="status-pill status-pill--neutral">Awaiting first login</span>
                                    <span class="status-pill">Google allowlist</span>
                                </div>
                            </div>

                            <div class="recording-browser__meta-grid">
                                <div class="camera-row__fact">
                                    <span>Added</span>
                                    <strong>{{ $appSettings->formatDateTime($allowedLoginEmail->created_at, 'Y-m-d H:i:s') ?? 'Unavailable' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Added by</span>
                                    <strong>{{ $allowedLoginEmail->addedBy?->name ?? 'Bootstrap or migration' }}</strong>
                                </div>

                                <div class="camera-row__fact camera-row__fact--wide">
                                    <span>Status</span>
                                    <strong>Waiting for first successful Google sign-in</strong>
                                </div>
                            </div>

                            <div class="probe-actions">
                                <form method="POST" action="{{ route('admin.users.allowed-emails.destroy', ['allowedLoginEmail' => $allowedLoginEmail]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button button--soft" type="submit">Remove sign-in access</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Saved operators</h2>
                    <p class="panel-copy">Accounts are created or updated through Google sign-in. Manage admin privileges and ongoing sign-in access here for operators who have already authenticated at least once.</p>
                </div>
            </div>

            @if ($users->isEmpty())
                <div class="empty-state">
                    <strong>No users are stored yet.</strong>
                    <p>Once an operator signs in with Google, their account will appear here.</p>
                </div>
            @else
                <div class="recording-browser__list">
                    @foreach ($users as $user)
                        @php($normalizedUserEmail = \App\Models\AllowedLoginEmail::normalizeEmail($user->email))
                        @php($allowedLoginEmail = $allowedLoginEmailsByEmail->get($normalizedUserEmail))
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

                                <div class="camera-row__fact">
                                    <span>Sign-in access</span>
                                    <strong>{{ $allowedLoginEmail ? 'Allowed' : 'Blocked until re-approved' }}</strong>
                                </div>
                            </div>

                            <div class="probe-actions">
                                @if ($allowedLoginEmail)
                                    <form method="POST" action="{{ route('admin.users.allowed-emails.destroy', ['allowedLoginEmail' => $allowedLoginEmail]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            class="button button--soft"
                                            type="submit"
                                            @if ($user->is_admin && $adminCount <= 1) disabled aria-disabled="true" @endif
                                        >
                                            Remove sign-in access
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.users.allowed-emails.store') }}">
                                        @csrf
                                        <input type="hidden" name="email" value="{{ $normalizedUserEmail }}">
                                        <button class="button button--soft" type="submit">Allow sign-in access</button>
                                    </form>
                                @endif

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

                                <span class="status-pill status-pill--{{ $allowedLoginEmail ? 'good' : 'warn' }}">{{ $allowedLoginEmail ? 'Google sign-in allowed' : 'Google sign-in blocked' }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection