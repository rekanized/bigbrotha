<section class="screen-card screen-card--spacious">
    <div class="panel-heading">
        <div>
            <h2 class="panel-title">Network camera storage</h2>
            <p class="panel-copy">Store finished recording clips on an SMB share. Previews, review assets, and working files stay on local private storage. Use a dedicated path such as <strong>//fileserver/share/cameras</strong> or <strong>smb://fileserver/share/cameras</strong>.</p>
        </div>
    </div>

    <div class="notice-stack" aria-live="polite">
        @if ($statusMessage)
            <div class="notice notice--success">{{ $statusMessage }}</div>
        @endif
    </div>

    <form wire:submit="save">
        <div class="inline-action-form-row">
            <label class="field-stack field-stack--wide">
                <span>Enable SMB network storage</span>
                <select class="form-select" wire:model.live="networkStorageEnabled">
                    <option value="0">Disabled</option>
                    <option value="1">Enabled</option>
                </select>
            </label>

            <div class="probe-form-grid__actions">
                <button class="button button--primary" type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span class="button__content">
                        <span class="button__icon-slot" aria-hidden="true">
                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 5h11l3 3v11H5z"></path>
                                <path d="M9 5v6h6"></path>
                                <path d="M9 19v-5h6v5"></path>
                            </svg>
                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                            </svg>
                        </span>
                        <span>Save network storage</span>
                    </span>
                </button>
            </div>
        </div>

        <label class="field-stack field-stack--wide">
            <span>SMB path</span>
            <input class="form-input" type="text" wire:model.blur="networkStoragePath" placeholder="//fileserver/share/cameras">
            <small>Point this at the dedicated cameras directory. Saving settings does not check share access; monitor recording jobs after enabling it.</small>
            @error('networkStoragePath')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </label>

        <div class="camera-form-grid">
            <label class="field-stack field-stack--wide">
                <span>Username</span>
                <input class="form-input" type="text" wire:model.blur="networkStorageUsername" placeholder="DOMAIN\\operator">
                <small>Use either a plain account name or a domain-prefixed login.</small>
                @error('networkStorageUsername')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>

            <label class="field-stack field-stack--wide">
                <span>Password</span>
                <input class="form-input" type="password" wire:model.blur="networkStoragePassword" autocomplete="new-password">
                <small>{{ $hasStoredPassword ? 'Leave blank to keep the stored password.' : 'The password is stored encrypted in the application settings table.' }}</small>
                @error('networkStoragePassword')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>
        </div>
    </form>
</section>
