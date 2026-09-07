@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Operator access')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Admin')

@section('page_title', 'Operator access')

@section('page_lead', 'Review local accounts, approved Google sign-in emails, authenticated operators, and admin access from one admin screen.')

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
                    <p class="screen-summary-strip__copy">Use this screen to control local operator passwords, Google sign-in allowlisting, and which stored operators hold admin access.</p>
                </div>

                <span class="status-pill status-pill--neutral">{{ $allowedLoginEmails->count() }} Google allowed · {{ $users->where('local_auth_enabled', true)->count() }} local · {{ $users->count() }} user{{ $users->count() === 1 ? '' : 's' }}</span>
            </div>
        </section>

        @if ($authSettings->manualAuthEnabled())
        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Create or update a local operator</h2>
                    <p class="panel-copy">Use this form to create a manual local account or add a local password to an existing operator record with the same email address.</p>
                </div>
            </div>

            <form class="operator-access__local-form" method="POST" action="{{ route('admin.users.local-accounts.store') }}">
                @csrf

                <div class="camera-form-grid operator-access__local-fields">
                    <label class="field-stack field-stack--wide">
                        <span>Operator name</span>
                        <input class="form-input" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required>
                        @error('name', 'localUser')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Operator email</span>
                        <input class="form-input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
                        @error('email', 'localUser')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Password</span>
                        <input class="form-input" type="password" name="password" autocomplete="new-password" required>
                        @error('password', 'localUser')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Confirm password</span>
                        <input class="form-input" type="password" name="password_confirmation" autocomplete="new-password" required>
                    </label>
                </div>

                <div class="inline-action-form-row operator-access__local-actions">
                    <label class="auth-checkbox">
                        <input type="checkbox" name="is_admin" value="1" @checked(old('is_admin'))>
                        <span>Grant admin access to this operator</span>
                    </label>

                    <div class="probe-form-grid__actions">
                        <button class="button button--primary" type="submit">Save local operator</button>
                    </div>
                </div>
            </form>
        </section>
        @endif

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
                        @error('email', 'allowedEmail')
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
                    <p class="panel-copy">Accounts can be local-only, Google-only, or dual-auth. Manage admin privileges, Google allowlisting, and local-password resets here for operators who already exist in the system.</p>
                </div>
            </div>

            @if ($users->isEmpty())
                <div class="empty-state">
                    <strong>No users are stored yet.</strong>
                    <p>Create a local operator above or complete the first Google sign-in to populate the operator list.</p>
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
                                    <span class="status-pill status-pill--{{ $user->hasLocalAuth() ? 'good' : 'neutral' }}">{{ $user->hasLocalAuth() ? 'Local password enabled' : 'No local password' }}</span>
                                    <span class="status-pill status-pill--{{ $user->google_id ? 'good' : 'neutral' }}">{{ $user->google_id ? 'Google linked' : 'No Google link' }}</span>
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
                                    <span>Authentication methods</span>
                                    <strong>{{ $user->hasLocalAuth() ? 'Local password enabled' : 'Local password not set' }} · {{ $user->google_id ? 'Google OAuth linked' : 'Google OAuth not linked' }}</strong>
                                </div>

                                <div class="camera-row__fact">
                                    <span>Sign-in access</span>
                                    <strong>{{ $allowedLoginEmail ? 'Allowed' : 'Blocked until re-approved' }}</strong>
                                </div>
                            </div>

                            <div class="probe-actions">
                                <form method="POST" action="{{ route('admin.users.local-password', ['user' => $user]) }}" class="inline-auth-form">
                                    @csrf
                                    @method('PUT')
                                    <label class="field-stack"><span>New local password</span><input class="form-input" type="password" name="password" autocomplete="new-password" required></label>
                                    <label class="field-stack"><span>Confirm password</span><input class="form-input" type="password" name="password_confirmation" autocomplete="new-password" required></label>
                                    <button class="button button--soft" type="submit">{{ $user->hasLocalAuth() ? 'Reset local password' : 'Enable local password' }}</button>
                                </form>

                                @if ($errors->localPassword->isNotEmpty())
                                    <span class="field-error">{{ $errors->localPassword->first('password') }}</span>
                                @endif

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
