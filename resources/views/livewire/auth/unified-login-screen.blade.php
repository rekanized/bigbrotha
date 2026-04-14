@php
    $authMethodCount = ($manualAuthAvailable ? 1 : 0) + ($googleAuthAvailable ? 1 : 0);
@endphp

<section class="auth-card page-card auth-card--wide">
    <div class="auth-brand">
        <div class="auth-card__mark" aria-hidden="true">
            <img class="sidebar-brand__logo" src="{{ rtrim(request()->getBaseUrl(), '/') }}/img/bigbrotha-logo.svg" alt="{{ config('app.name', 'Bigbrotha') }} logo">
        </div>
        <div class="auth-brand__copy">
            <h1 class="auth-brand__name">{{ config('app.name', 'Bigbrotha') }}</h1>
            <p class="auth-brand__text">Sign in with the authentication methods currently enabled for this control room.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="notice notice--success">{{ session('status') }}</div>
    @endif

    @if (session('auth_error'))
        <div class="auth-card__alert">{{ session('auth_error') }}</div>
    @endif

    @if (!$manualAuthAvailable && !$googleAuthAvailable)
        <div class="notice notice--error">No authentication methods are currently enabled. Ask an administrator to restore sign-in access from the admin settings page.</div>
    @endif

    <div class="auth-login-grid{{ $authMethodCount === 1 ? ' auth-login-grid--single' : '' }}">
        @if ($manualAuthAvailable)
            <form class="auth-card__section" wire:submit="login">
                <div>
                    <h2 class="panel-title">Local sign-in</h2>
                    <p class="panel-copy">Use the email address and password assigned to your local operator account.</p>
                </div>

                <label class="field-stack field-stack--wide">
                    <span>Email</span>
                    <input class="form-input" type="email" wire:model.blur="email" autocomplete="email">
                    @error('email')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="field-stack field-stack--wide">
                    <span>Password</span>
                    <input class="form-input" type="password" wire:model.blur="password" autocomplete="current-password">
                    @error('password')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="auth-checkbox">
                    <input type="checkbox" wire:model="remember">
                    <span>Keep this workstation signed in</span>
                </label>

                <button class="button button--primary auth-card__button" type="submit">Sign in with local account</button>
            </form>
        @endif

        @if ($manualAuthAvailable && $googleAuthAvailable)
            <div class="auth-divider" aria-hidden="true">or</div>
        @endif

        @if ($googleAuthAvailable)
            <div class="auth-card__section">
                <div>
                    <h2 class="panel-title">Google OAuth</h2>
                    <p class="panel-copy">Use the approved Google account assigned to your operator profile.</p>
                </div>

                <a class="button button--soft auth-card__button" href="{{ route('auth.google.redirect') }}">
                    Continue with Google
                </a>
            </div>
        @endif
    </div>

    <p class="auth-card__footnote">Operator access controls live viewing, recordings, camera management, and admin tooling.</p>
</section>