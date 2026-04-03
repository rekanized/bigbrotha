<div class="fleet-manager">
    <section class="screen-card screen-card--accent fleet-manager__workflow-strip">
        <div class="fleet-manager__workflow-header">
            <div>
                <span class="eyebrow">Fleet workflow</span>
                <p class="fleet-manager__workflow-copy">Save the camera, refresh RTSP profiles, capture a preview, then apply a recording policy before operators rely on the feed.</p>
            </div>
        </div>

        <div class="fleet-manager__workflow-steps" aria-label="Camera fleet workflow">
            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">01</span>
                <strong>Save verified device</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">02</span>
                <strong>Refresh RTSP profiles</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">03</span>
                <strong>Test and capture proof</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">04</span>
                <strong>Apply recording policy</strong>
            </article>
        </div>
    </section>

    <div class="dashboard-stats">
        <article class="metric-card metric-card--blue">
            <div class="metric-card__icon">CF</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['total'] }}</p>
                <p class="metric-card__label">Saved cameras</p>
                <p class="metric-card__detail">Inventory records currently managed through the operator workspace.</p>
            </div>
        </article>

        <article class="metric-card metric-card--green">
            <div class="metric-card__icon">EN</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['enabled'] }}</p>
                <p class="metric-card__label">Enabled</p>
                <p class="metric-card__detail">Camera records that remain available to the wall and operator tools.</p>
            </div>
        </article>

        <article class="metric-card metric-card--violet">
            <div class="metric-card__icon">ON</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['onvif'] }}</p>
                <p class="metric-card__label">ONVIF capable</p>
                <p class="metric-card__detail">Fleet records with ONVIF connectivity configured and ready to interrogate.</p>
            </div>
        </article>

        <article class="metric-card metric-card--amber">
            <div class="metric-card__icon">RT</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['rtsp'] }}</p>
                <p class="metric-card__label">RTSP ready</p>
                <p class="metric-card__detail">Camera records with at least one retrieved RTSP stream URL saved.</p>
            </div>
        </article>
    </div>

    <section class="screen-card screen-card--spacious">
        <div class="panel-heading">
            <div>
                <h2 class="panel-title">Fleet inventory</h2>
                <p class="panel-copy">Review every saved camera, inspect the latest preview thumbnail, and open the editor for RTSP retrieval, preview testing, and configuration changes.</p>
            </div>

            <div class="probe-actions">
                <a class="button button--soft" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Run ONVIF sweep</a>
                <button class="button button--primary" type="button" wire:click="newCamera">Add camera</button>
            </div>
        </div>

        <div class="notice-stack" aria-live="polite">
            @if ($statusMessage)
                <div class="notice notice--success">{{ $statusMessage }}</div>
            @endif

            @if ($errorMessage)
                <div class="notice notice--danger">{{ $errorMessage }}</div>
            @endif

            @if ($rtspStatusMessage)
                <div class="notice notice--success">{{ $rtspStatusMessage }}</div>
            @endif

            @if ($rtspErrorMessage)
                <div class="notice notice--danger">{{ $rtspErrorMessage }}</div>
            @endif
        </div>

        @if ($cameras->isEmpty())
            <div class="empty-state">
                <strong>No cameras are in the fleet yet.</strong>
                <p>Create one here or save a verified device from the ONVIF sweep.</p>
                <div class="probe-actions">
                    <button class="button button--primary" type="button" wire:click="newCamera">Create camera</button>
                    <a class="button button--soft" href="{{ route('discovery.onvif-sweep') }}" wire:navigate>Open ONVIF sweep</a>
                </div>
            </div>
        @else
            <div class="camera-list">
                @foreach ($cameras as $camera)
                    @php($latestPreview = $camera->latestRtspPreview())
                    @php($serialNumber = is_string($camera->serial_number) && trim(strtolower($camera->serial_number)) !== 'null' ? trim($camera->serial_number) : null)
                    @php($recordingModeLabel = $camera->recording_mode === 'continuous' ? 'Constantly recording' : ($camera->recording_mode === 'motion' ? 'Record on movement' : 'Recording off'))
                    <article class="camera-row{{ $editingCameraId === $camera->id ? ' camera-row--selected' : '' }}">
                        <div class="camera-row__header">
                            <div class="camera-row__identity">
                                <strong>{{ $camera->name }}</strong>
                                <p>{{ $camera->manufacturer ?: 'Unknown maker' }}{{ $camera->model ? ' · '.$camera->model : '' }}</p>
                            </div>

                            <div class="badge-row camera-row__status">
                                <span class="status-pill status-pill--{{ $camera->is_enabled ? 'good' : 'warn' }}">{{ $camera->is_enabled ? 'Enabled' : 'Disabled' }}</span>
                                <span class="status-pill status-pill--neutral">{{ $camera->last_seen_at?->diffForHumans() ?? 'Never seen' }}</span>
                            </div>
                        </div>

                        <div class="camera-row__layout">
                            <div class="camera-row__media">
                                @if ($latestPreview)
                                    <div class="camera-row__preview-frame">
                                        <img class="camera-row__preview-image" src="{{ route('camera-fleet.preview', ['camera' => $camera->id, 'profileIndex' => $latestPreview['index'], 'v' => $latestPreview['profile']['preview_generated_at'] ?? '']) }}" alt="Latest preview for {{ $camera->name }}">
                                    </div>

                                    <div class="camera-row__preview-meta">
                                        <span class="camera-row__label">Latest preview</span>
                                        <strong>{{ $latestPreview['profile']['name'] ?? 'Latest preview' }}</strong>
                                        <p>{{ $latestPreview['profile']['preview_generated_at'] ?? 'Preview captured' }}</p>
                                    </div>
                                @else
                                    <div class="camera-row__preview-empty">
                                        <strong>No preview yet</strong>
                                        <p>Run a stream test to capture one.</p>
                                    </div>
                                @endif
                            </div>

                            <div class="camera-row__content">
                                <div class="camera-row__fact-grid">
                                    <div class="camera-row__fact">
                                        <span>Network</span>
                                        <strong>{{ $camera->local_ip }}{{ $camera->hostname ? ' · '.$camera->hostname : '' }}</strong>
                                    </div>

                                    <div class="camera-row__fact">
                                        <span>Latest check</span>
                                        <strong>{{ $latestPreview['profile']['probe_status'] ?? 'Not tested yet' }}</strong>
                                    </div>

                                    <div class="camera-row__fact">
                                        <span>Recording</span>
                                        <strong>{{ $recordingModeLabel }}</strong>
                                    </div>

                                    <div class="camera-row__fact">
                                        <span>Last recording</span>
                                        <strong>{{ $camera->recording_last_recorded_at?->diffForHumans() ?? 'No saved segment yet' }}</strong>
                                    </div>

                                    <div class="camera-row__fact camera-row__fact--wide">
                                        <span>ONVIF</span>
                                        <strong>{{ $camera->onvifEndpoint() ?? 'Not configured' }}</strong>
                                    </div>

                                    <div class="camera-row__fact camera-row__fact--wide">
                                        <span>RTSP</span>
                                        <strong>{{ $camera->rtspEndpoint() ?? 'No primary stream saved' }}</strong>
                                    </div>
                                </div>

                                <div class="badge-row camera-row__meta-tags">
                                    <span class="status-pill status-pill--{{ $camera->supports_onvif ? 'good' : 'neutral' }}">{{ $camera->supports_onvif ? 'ONVIF' : 'No ONVIF' }}</span>
                                    <span class="status-pill status-pill--{{ $camera->supports_rtsp ? 'good' : 'warn' }}">{{ $camera->supports_rtsp ? 'RTSP' : 'No RTSP' }}</span>
                                    <span class="status-pill status-pill--{{ $camera->recording_mode === 'off' ? 'neutral' : 'good' }}">{{ $recordingModeLabel }}</span>
                                    @if ($camera->recording_mode !== 'off')
                                        <span class="status-pill">Retention {{ $camera->recording_retention_days }} day{{ $camera->recording_retention_days === 1 ? '' : 's' }}</span>
                                    @endif
                                    @if ($serialNumber)
                                        <span class="status-pill">SN {{ $serialNumber }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="camera-row__footer">
                            <div class="camera-row__actions">
                                <button class="button button--primary" type="button" wire:click="editCamera({{ $camera->id }})">Edit camera</button>
                                <button class="button button--soft" type="button" wire:click="toggleEnabled({{ $camera->id }})">{{ $camera->is_enabled ? 'Disable' : 'Enable' }}</button>
                                <button class="button button--soft" type="button" wire:click="deleteCamera({{ $camera->id }})">Delete</button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if ($isEditorModalOpen)
        <div class="fleet-modal" role="dialog" aria-modal="true" aria-labelledby="camera-editor-title">
            <button class="fleet-modal__backdrop" type="button" wire:click="closeEditorModal" aria-label="Close camera editor"></button>

            <section class="fleet-modal__panel screen-card screen-card--spacious" wire:click.stop>
                <div class="panel-heading">
                    <div>
                        <h2 id="camera-editor-title" class="panel-title">{{ $editingCameraId ? 'Edit camera' : 'Add camera' }}</h2>
                        <p class="panel-copy">Maintain network identity, ONVIF endpoint, credentials, RTSP defaults, and recording policy for the selected camera record.</p>
                    </div>

                    <div class="probe-actions">
                        @if ($editingCameraId)
                            <button class="button button--soft" type="button" wire:click="fetchRtspProfiles" wire:loading.attr="disabled" wire:target="fetchRtspProfiles">Refresh streams</button>
                        @endif

                        <button class="button button--soft" type="button" wire:click="closeEditorModal">Close</button>
                    </div>
                </div>

                <div class="notice-stack" aria-live="polite">
                    @if ($statusMessage)
                        <div class="notice notice--success">{{ $statusMessage }}</div>
                    @endif

                    @if ($errorMessage)
                        <div class="notice notice--danger">{{ $errorMessage }}</div>
                    @endif

                    @if ($rtspStatusMessage)
                        <div class="notice notice--success">{{ $rtspStatusMessage }}</div>
                    @endif

                    @if ($rtspErrorMessage)
                        <div class="notice notice--danger">{{ $rtspErrorMessage }}</div>
                    @endif
                </div>

                @if ($selectedCamera)
                    <div class="detail-grid">
                        <article class="detail-card">
                            <span class="detail-card__label">Saved ONVIF endpoint</span>
                            <strong>{{ $selectedCamera->onvifEndpoint() ?? 'Unavailable' }}</strong>
                        </article>

                        <article class="detail-card">
                            <span class="detail-card__label">Primary RTSP endpoint</span>
                            <strong>{{ $selectedCamera->rtspEndpoint() ?? 'No primary RTSP stream saved' }}</strong>
                        </article>

                        <article class="detail-card">
                            <span class="detail-card__label">Stored credentials</span>
                            <strong>{{ $hasStoredPassword ? 'Username and password saved' : 'No saved password' }}</strong>
                        </article>

                        <article class="detail-card">
                            <span class="detail-card__label">Recording policy</span>
                            <strong>{{ $recordingModes[$selectedCamera->recording_mode] ?? 'Off' }}</strong>
                        </article>

                        <article class="detail-card">
                            <span class="detail-card__label">Latest recorded segment</span>
                            <strong>{{ $selectedCamera->recording_last_recorded_at?->diffForHumans() ?? 'No segment saved yet' }}</strong>
                        </article>
                    </div>
                @endif

                <section class="form-section">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Identity and network</h3>
                            <p class="panel-copy">Record the camera identity and the HTTP or ONVIF network details Laravel should persist.</p>
                        </div>
                    </div>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>Name</span>
                            <input class="form-input" type="text" wire:model="form.name">
                            @error('form.name')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Local IP</span>
                            <input class="form-input" type="text" placeholder="192.168.1.67" wire:model="form.local_ip">
                            @error('form.local_ip')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Hostname</span>
                            <input class="form-input" type="text" placeholder="camera-frontdoor" wire:model="form.hostname">
                            @error('form.hostname')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Manufacturer</span>
                            <input class="form-input" type="text" wire:model="form.manufacturer">
                            @error('form.manufacturer')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Model</span>
                            <input class="form-input" type="text" wire:model="form.model">
                            @error('form.model')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Serial number</span>
                            <input class="form-input" type="text" wire:model="form.serial_number">
                            @error('form.serial_number')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>HTTP port</span>
                            <input class="form-input" type="number" min="1" max="65535" wire:model="form.http_port">
                            @error('form.http_port')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>ONVIF port</span>
                            <input class="form-input" type="number" min="1" max="65535" wire:model="form.onvif_port">
                            @error('form.onvif_port')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack field-stack--wide">
                            <span>ONVIF path</span>
                            <input class="form-input" type="text" placeholder="/onvif/device_service" wire:model="form.onvif_path">
                            @error('form.onvif_path')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>
                </section>

                <section class="form-section">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Stream defaults and access</h3>
                            <p class="panel-copy">These values become the baseline for stream retrieval, playback selection, and backend checks.</p>
                        </div>
                    </div>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>RTSP port</span>
                            <input class="form-input" type="number" min="1" max="65535" wire:model="form.rtsp_port">
                            @error('form.rtsp_port')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack field-stack--wide">
                            <span>RTSP path</span>
                            <input class="form-input" type="text" placeholder="/stream1" wire:model="form.rtsp_path">
                            @error('form.rtsp_path')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>RTSP transport</span>
                            <select class="form-select" wire:model="form.rtsp_transport">
                                @foreach ($transportOptions as $transportOption)
                                    <option value="{{ $transportOption }}">{{ strtoupper($transportOption) }}</option>
                                @endforeach
                            </select>
                            @error('form.rtsp_transport')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Username</span>
                            <input class="form-input" type="text" autocomplete="username" wire:model="form.username">
                            @error('form.username')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>{{ $editingCameraId ? 'New password' : 'Password' }}</span>
                            <input class="form-input" type="password" autocomplete="current-password" wire:model="form.password">
                            @error('form.password')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>

                    @if ($editingCameraId && $hasStoredPassword)
                        <p class="probe-note">Leave the password blank to keep the saved credential unchanged.</p>
                    @endif
                </section>

                <section class="form-section">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Recording policy</h3>
                            <p class="panel-copy">Choose whether this feed stays off, records continuously, or records only when the selected motion region changes enough to cross the threshold.</p>
                        </div>
                    </div>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>Recording mode</span>
                            <select class="form-select" wire:model="form.recording_mode">
                                @foreach ($recordingModes as $recordingModeValue => $recordingModeLabel)
                                    <option value="{{ $recordingModeValue }}">{{ $recordingModeLabel }}</option>
                                @endforeach
                            </select>
                            @error('form.recording_mode')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Recording source profile</span>
                            <select class="form-select" wire:model="form.recording_profile_index">
                                <option value="">Automatic primary profile</option>
                                @foreach ($rtspProfiles as $profile)
                                    <option value="{{ $loop->index }}">{{ $profile['name'] ?? 'Profile '.($loop->index + 1) }}</option>
                                @endforeach
                            </select>
                            @error('form.recording_profile_index')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Retention days</span>
                            <input class="form-input" type="number" min="1" max="365" wire:model="form.recording_retention_days">
                            @error('form.recording_retention_days')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Motion sensitivity</span>
                            <input class="form-input" type="number" min="1" max="100" wire:model="form.motion_sensitivity">
                            @error('form.motion_sensitivity')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>

                    <p class="probe-note">Higher sensitivity reacts to smaller pixel changes. Recordings are captured as short disk-backed segments through queued jobs, and retention cleanup removes expired footage automatically.</p>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>Motion area left %</span>
                            <input class="form-input" type="number" min="0" max="95" wire:model="form.recording_motion_x">
                            @error('form.recording_motion_x')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Motion area top %</span>
                            <input class="form-input" type="number" min="0" max="95" wire:model="form.recording_motion_y">
                            @error('form.recording_motion_y')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Motion area width %</span>
                            <input class="form-input" type="number" min="5" max="100" wire:model="form.recording_motion_width">
                            @error('form.recording_motion_width')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Motion area height %</span>
                            <input class="form-input" type="number" min="5" max="100" wire:model="form.recording_motion_height">
                            @error('form.recording_motion_height')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>
                </section>

                <section class="form-section">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Camera state</h3>
                            <p class="panel-copy">Toggle supported protocols and whether the camera stays active for operators.</p>
                        </div>
                    </div>

                    <div class="checkbox-grid">
                        <label class="checkbox-field">
                            <input type="checkbox" wire:model="form.supports_onvif">
                            <span>ONVIF enabled</span>
                        </label>

                        <label class="checkbox-field">
                            <input type="checkbox" wire:model="form.supports_rtsp">
                            <span>RTSP enabled</span>
                        </label>

                        <label class="checkbox-field">
                            <input type="checkbox" wire:model="form.is_enabled">
                            <span>Camera enabled</span>
                        </label>
                    </div>

                    <div class="probe-actions">
                        <button class="button button--primary" type="button" wire:click="saveCamera" wire:loading.attr="disabled" wire:target="saveCamera">
                            <span wire:loading.remove wire:target="saveCamera">{{ $editingCameraId ? 'Save changes' : 'Create camera' }}</span>
                            <span wire:loading wire:target="saveCamera">Saving camera...</span>
                        </button>

                        <button class="button button--soft" type="button" wire:click="newCamera">Reset</button>
                    </div>
                </section>

                <section class="form-section">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-title">RTSP stream URLs</h3>
                            <p class="panel-copy">Fetch ONVIF media profiles when they are available, or register the saved RTSP endpoint directly when the camera is RTSP-only.</p>
                        </div>
                    </div>

                    @if ($editingCameraId === null)
                        <div class="empty-state empty-state--compact">
                            <strong>Create or save a camera first.</strong>
                            <p>Profile retrieval depends on a saved ONVIF endpoint and saved credentials.</p>
                        </div>
                    @elseif ($rtspProfiles === [])
                        <div class="empty-state empty-state--compact">
                            <strong>No RTSP stream URLs have been saved for this camera yet.</strong>
                            <p>Use the refresh control above to query ONVIF media profiles or save the configured RTSP endpoint as a manual stream when ONVIF is unavailable.</p>
                        </div>
                    @else
                        <div class="stream-list">
                            @foreach ($rtspProfiles as $profile)
                                <article class="stream-card">
                                    <div class="stream-card__header">
                                        <div>
                                            <strong>{{ $profile['name'] ?? 'Profile' }}</strong>
                                            <p>{{ $profile['token'] ?? 'No token returned' }}</p>
                                        </div>

                                        <div class="stream-card__status">
                                            @if (!empty($profile['resolution']))
                                                <span class="status-pill status-pill--neutral">{{ $profile['resolution'] }}</span>
                                            @endif

                                            @if (($profile['probe_status'] ?? null) === 'Healthy')
                                                <span class="status-pill status-pill--good">Healthy</span>
                                            @elseif (($profile['probe_status'] ?? null) === 'Failed')
                                                <span class="status-pill status-pill--warn">Failed</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="stream-preview-shell">
                                        @if (!empty($profile['preview_path']) && $selectedCameraId)
                                            <img class="stream-preview-image" src="{{ route('camera-fleet.preview', ['camera' => $selectedCameraId, 'profileIndex' => $loop->index, 'v' => $profile['preview_generated_at'] ?? '']) }}" alt="Preview snapshot for {{ $profile['name'] ?? 'RTSP profile' }}">
                                        @else
                                            <div class="stream-preview-empty">
                                                <strong>No preview image yet.</strong>
                                                <p>Run the stream test to capture a current frame.</p>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="key-value-list key-value-list--dense">
                                        <div class="key-value-row">
                                            <span>Stream URL</span>
                                            <strong>{{ $profile['uri'] ?? 'Unavailable' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Encoding</span>
                                            <strong>{{ $profile['encoding'] ?? 'Unknown' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Primary path</span>
                                            <strong>{{ $profile['path'] ?? 'Unavailable' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Connection test</span>
                                            <strong>{{ $profile['probe_message'] ?? 'Not tested yet' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Checked at</span>
                                            <strong>{{ $profile['probe_checked_at'] ?? 'Not tested yet' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Video details</span>
                                            <strong>{{ trim(($profile['video_codec'] ?? '').(!empty($profile['video_resolution']) ? ' · '.$profile['video_resolution'] : '')) ?: 'Unavailable' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Preview status</span>
                                            <strong>{{ $profile['preview_message'] ?? 'No preview generated yet' }}</strong>
                                        </div>

                                        <div class="key-value-row">
                                            <span>Transport</span>
                                            <strong>{{ $profile['transport'] ?? strtoupper($form['rtsp_transport']) }}</strong>
                                        </div>
                                    </div>

                                    <div class="probe-actions">
                                        <button class="button button--soft" type="button" wire:click="testRtspProfile({{ $loop->index }})" wire:loading.attr="disabled" wire:target="testRtspProfile">
                                            <span wire:loading.remove wire:target="testRtspProfile">Run stream test</span>
                                            <span wire:loading wire:target="testRtspProfile">Testing stream...</span>
                                        </button>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="form-section">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-title">Recent recording activity</h3>
                            <p class="panel-copy">This is the latest queue and retention outcome for saved recording segments on the selected camera.</p>
                        </div>
                    </div>

                    @if ($selectedCamera === null)
                        <div class="empty-state empty-state--compact">
                            <strong>Save a camera before recording activity appears.</strong>
                            <p>The scheduler only queues recording work for saved camera records.</p>
                        </div>
                    @elseif ($selectedCameraRecentRecordings->isEmpty())
                        <div class="empty-state empty-state--compact">
                            <strong>No recording segments have been queued for this camera yet.</strong>
                            <p>Enable a recording mode, then let the scheduler and queue worker process the first segment.</p>
                        </div>
                    @else
                        <div class="key-value-list">
                            @foreach ($selectedCameraRecentRecordings as $recentRecording)
                                <div class="key-value-row">
                                    <span>{{ $recentRecording->scheduled_for?->format('Y-m-d H:i') ?? 'Pending' }} UTC · {{ ucfirst($recentRecording->capture_mode) }}</span>
                                    <strong>
                                        {{ ucfirst($recentRecording->status) }}
                                        @if ($recentRecording->file_size_bytes)
                                            · {{ number_format($recentRecording->file_size_bytes / 1048576, 2) }} MB
                                        @endif
                                        @if ($recentRecording->motion_score !== null)
                                            · motion {{ $recentRecording->motion_score }}
                                        @endif
                                    </strong>
                                </div>
                                <p class="probe-note">{{ $recentRecording->message ?? 'No recorder status message yet.' }}</p>
                            @endforeach
                        </div>
                    @endif
                </section>
            </section>
        </div>
    @endif
</div>