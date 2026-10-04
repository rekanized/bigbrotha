<section class="screen-card screen-card--spacious">
    <div class="panel-heading">
        <div>
            <h2 class="panel-title">Authentication settings</h2>
            <p class="panel-copy">Manage local password login and Google OAuth from one panel. Google sign-in can be configured ahead of time, but it cannot be enabled until the entered credential set passes a live validation flow.</p>
        </div>
        <div class="badge-row">
            <span class="status-pill status-pill--{{ $manualAuthEnabled === '1' ? 'good' : 'neutral' }}">Local {{ $manualAuthEnabled === '1' ? 'enabled' : 'disabled' }}</span>
            <span class="status-pill status-pill--{{ $googleAuthEnabled === '1' ? 'good' : 'neutral' }}">Google {{ $googleAuthEnabled === '1' ? 'enabled' : 'disabled' }}</span>
        </div>
    </div>

    <div class="notice-stack" aria-live="polite">
        @if ($statusMessage)
            <div class="notice {{ $statusType === 'success' ? 'notice--success' : 'notice--error' }}">{{ $statusMessage }}</div>
        @endif
    </div>

    <form class="auth-settings-panel__form" wire:submit="save">
        <div class="workflow-grid workflow-grid--compact auth-method-grid">
            <label class="workflow-card workflow-card--static auth-method-card">
                <span class="workflow-card__step">PW</span>
                <span class="workflow-card__body">
                    <strong>Manual local sign-in</strong>
                    <p>Active local admin available: {{ $localAdminExists ? 'Yes' : 'No' }}</p>
                    <select class="form-select" wire:model.live="manualAuthEnabled">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </span>
            </label>

            <label class="workflow-card workflow-card--static auth-method-card">
                <span class="workflow-card__step">GO</span>
                <span class="workflow-card__body">
                    <strong>Google OAuth</strong>
                    <p>Linked Google admin available: {{ $googleAdminExists ? 'Yes' : 'No' }}</p>
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

        <div class="camera-form-grid auth-settings-panel__fields">
            <label class="field-stack field-stack--wide">
                <span>Google client ID</span>
                <input class="form-input" type="text" wire:model.blur="googleClientId" autocomplete="off">
                @error('googleClientId')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>

            <label class="field-stack field-stack--wide">
                <span>Google client secret</span>
                <input class="form-input" type="password" wire:model.blur="googleClientSecret" autocomplete="new-password" placeholder="{{ $hasStoredGoogleSecret ? 'Saved secret retained when blank' : 'Enter client secret' }}">
                @error('googleClientSecret')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>

            <label class="field-stack field-stack--wide field-stack--full">
                <span>Google redirect URI</span>
                <input class="form-input" type="url" wire:model.blur="googleRedirectUri" autocomplete="off">
                <small>Use the public callback URL that Google will redirect operators back to after sign-in.</small>
                @error('googleRedirectUri')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>
        </div>

        <div class="inline-action-form-row auth-settings-panel__footer">
            <div class="auth-validation-note">
                @if ($this->googleVerificationIsCurrent)
                    <strong>Validated credential set</strong>
                    <span>Last verified against Google as {{ $googleVerifiedEmail ?? 'an operator account' }}.</span>
                @else
                    <strong>Google validation required</strong>
                    <span>Run the test flow after editing the Google client ID, secret, or redirect URI.</span>
                @endif
            </div>

            <div class="probe-form-grid__actions auth-settings-actions">
                <button class="button button--soft" type="button" wire:click="beginGoogleTest">Validate Google OAuth</button>
                <button class="button button--primary" type="submit">Save authentication settings</button>
            </div>
        </div>
    </form>
</section>