<section class="screen-card screen-card--spacious">
    <div class="panel-heading">
        <div>
            <h2 class="panel-title">Network camera storage</h2>
            <p class="panel-copy">Route the camera recordings and preview tree to an SMB share instead of the local private cameras directory. Use a path such as <strong>//fileserver/cameras/bigbrotha</strong> or <strong>smb://fileserver/cameras/bigbrotha</strong>.</p>
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
                <button class="button button--primary" type="submit">Save network storage</button>
            </div>
        </div>

        <label class="field-stack field-stack--wide">
            <span>SMB path</span>
            <input class="form-input" type="text" wire:model.blur="networkStoragePath" placeholder="//fileserver/share/cameras">
            <small>Point this at the remote directory that should replace the local cameras tree.</small>
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