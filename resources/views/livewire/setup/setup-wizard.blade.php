<section class="screen-grid">
    <section class="screen-card screen-card--accent screen-summary-strip">
        <div class="screen-summary-strip__body">
            <div>
                <h1 class="setup-title">Set up {{ config('app.name', 'Bigbrotha') }}</h1>
                <p class="screen-summary-strip__copy">Choose how operators sign in and create your first administrator.</p>
            </div>

        </div>
    </section>

    <div class="notice-stack" aria-live="polite">
        @if (session('setup_error'))
            <div class="notice notice--error">{{ session('setup_error') }}</div>
        @endif

        @if ($statusMessage)
            <div class="notice {{ $statusType === 'success' ? 'notice--success' : 'notice--error' }}">{{ $statusMessage }}</div>
        @endif
    </div>

    <form class="screen-grid" wire:submit="save">
        <section class="screen-card screen-card--spacious">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Authentication methods</h2>
                    <p class="panel-copy">Manual local passwords and Google OAuth can run together. Google sign-in cannot be enabled until the credentials complete a successful validation round-trip.</p>
                </div>
            </div>

            <div class="auth-method-grid">
                <label class="workflow-card workflow-card--static auth-method-card">
                    <span class="workflow-card__step">PW</span>
                    <span class="workflow-card__body">
                        <strong>Manual local sign-in</strong>
                        <p>Operators authenticate with an email address and local password stored in the application database.</p>
                        <select class="form-select" wire:model.live="manualAuthEnabled">
                            <option value="1">Enabled</option>
                            <option value="0">Disabled</option>
                        </select>
                    </span>
                </label>

                <label class="workflow-card workflow-card--static auth-method-card">
                    <span class="workflow-card__step">GO</span>
                    <span class="workflow-card__body">
                        <strong>Google OAuth sign-in</strong>
                        <p>Operators authenticate with Google and are still subject to the operator allowlist.</p>
                        <select class="form-select" wire:model.live="googleAuthEnabled">
                            <option value="0">Disabled</option>
                            <option value="1">Enabled</option>
                        </select>
                    </span>
                </label>
            </div>

            @error('manualAuthEnabled')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </section>

        @if ($manualAuthEnabled === '1')
            <section class="screen-card screen-card--spacious">
                <div class="panel-heading">
                    <div>
                        <h2 class="panel-title">Initial local administrator</h2>
                        <p class="panel-copy">This account is required when local sign-in is enabled. Use the same email later with Google if you want one operator to have both local and Google access.</p>
                    </div>
                    <span class="status-pill status-pill--good">Required</span>
                </div>

                <div class="camera-form-grid">
                    <label class="field-stack field-stack--wide">
                        <span>Administrator name</span>
                        <input class="form-input" type="text" wire:model.blur="adminName" autocomplete="name">
                        @error('adminName')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Administrator email</span>
                        <input class="form-input" type="email" wire:model.blur="adminEmail" autocomplete="email">
                        @error('adminEmail')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Password</span>
                        <input class="form-input" type="password" wire:model.blur="adminPassword" autocomplete="new-password">
                        @error('adminPassword')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Confirm password</span>
                        <input class="form-input" type="password" wire:model.blur="adminPasswordConfirmation" autocomplete="new-password">
                        @error('adminPasswordConfirmation')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </section>
        @endif

        @if ($googleAuthEnabled === '1')
            <section class="screen-card screen-card--spacious">
                <div class="panel-heading">
                    <div>
                        <h2 class="panel-title">Google OAuth configuration</h2>
                        <p class="panel-copy">Enter the Google OAuth credentials and run a live validation flow before enabling Google sign-in. The redirect URI must be authorized in the Google console exactly as entered here.</p>
                    </div>
                    <span class="status-pill status-pill--{{ $this->googleVerificationIsCurrent ? 'good' : 'warn' }}">{{ $this->googleVerificationIsCurrent ? 'Validated' : 'Not validated' }}</span>
                </div>

                <div class="camera-form-grid">
                    <label class="field-stack field-stack--wide">
                        <span>Client ID</span>
                        <input class="form-input" type="text" wire:model.blur="googleClientId" autocomplete="off">
                        @error('googleClientId')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide">
                        <span>Client secret</span>
                        <input class="form-input" type="password" wire:model.blur="googleClientSecret" autocomplete="off">
                        @error('googleClientSecret')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide field-stack--full">
                        <span>Redirect URI</span>
                        <input class="form-input" type="url" wire:model.blur="googleRedirectUri" autocomplete="off">
                        <small>Google must list this exact callback URI as an authorized redirect.</small>
                        @error('googleRedirectUri')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="inline-action-form-row">
                    <div class="auth-validation-note">
                        @if ($this->googleVerificationIsCurrent)
                            <strong>Last verified account:</strong>
                            <span>{{ $googleVerifiedEmail ?? 'Unavailable' }}</span>
                        @else
                            <strong>Validation required:</strong>
                            <span>Google sign-in will remain disabled until this credential set completes a successful Google round-trip.</span>
                        @endif
                    </div>

                    <div class="probe-form-grid__actions">
                        <button class="button button--soft" type="button" wire:click="beginGoogleTest">Validate Google OAuth</button>
                    </div>
                </div>
            </section>
        @endif

        <section class="screen-card screen-card--spacious">
            <div class="inline-action-form-row">
                <div class="auth-validation-note">
                    <strong>Finish setup</strong>
                    <span>The application will remain in onboarding mode until at least one authentication method is configured successfully.</span>
                </div>

                <div class="probe-form-grid__actions">
                    <button class="button button--primary" type="submit">Complete setup</button>
                </div>
            </div>
        </section>
    </form>
</section>