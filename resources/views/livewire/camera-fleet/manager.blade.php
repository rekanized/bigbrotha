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
                    @php($cameraPreviewFingerprint = $latestPreview ? (($latestPreview['index'] ?? 'profile').'-'.($latestPreview['profile']['preview_generated_at'] ?? 'fresh').'-'.md5((string) ($latestPreview['profile']['preview_path'] ?? ''))) : 'empty')
                    @php($serialNumber = is_string($camera->serial_number) && trim(strtolower($camera->serial_number)) !== 'null' ? trim($camera->serial_number) : null)
                    @php($recordingModeLabel = $camera->recording_mode === 'continuous' ? 'Constantly recording' : ($camera->recording_mode === 'motion' ? 'Record on movement' : 'Recording off'))
                    <article class="camera-row{{ $editingCameraId === $camera->id ? ' camera-row--selected' : '' }}" wire:key="camera-row-{{ $camera->id }}">
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
                            <div class="camera-row__media" wire:key="camera-row-preview-{{ $camera->id }}-{{ $cameraPreviewFingerprint }}">
                                @if ($latestPreview)
                                    <div class="camera-row__preview-frame">
                                        <img class="camera-row__preview-image" src="{{ route('camera-fleet.preview', ['camera' => $camera->id, 'profileIndex' => $latestPreview['index'], 'v' => $latestPreview['profile']['preview_generated_at'] ?? '']) }}" alt="Latest preview for {{ $camera->name }}" loading="lazy" decoding="async">
                                    </div>

                                    <div class="camera-row__preview-meta">
                                        <span class="camera-row__label">Latest preview</span>
                                        <strong>{{ $latestPreview['profile']['name'] ?? 'Latest preview' }}</strong>
                                        <p>{{ $appSettings->formatStoredTimestamp($latestPreview['profile']['preview_generated_at'] ?? null) ?? ($latestPreview['profile']['preview_generated_at'] ?? 'Preview captured') }}</p>
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
                                        <span>Camera ID</span>
                                        <strong>#{{ $camera->id }}</strong>
                                    </div>

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
                            <span class="detail-card__label">Camera ID</span>
                            <strong>#{{ $selectedCamera->id }}</strong>
                        </article>

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
                            <select class="form-select" wire:model="form.recording_profile_index" data-role="motion-profile-select">
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

                    </div>

                    @if (($form['recording_mode'] ?? null) === App\Models\Camera::RECORDING_MODE_MOTION)
                        @php($motionMask = is_array($form['recording_motion_mask'] ?? null) ? $form['recording_motion_mask'] : app(App\Services\RecordingMotionMaskService::class)->fullFrameMask())
                        @php($motionThreshold = max(1, min(100, (int) ($form['motion_sensitivity'] ?? 35))))
                        @php($motionPreRollSeconds = max(0, min(30, (int) ($form['recording_motion_pre_roll_seconds'] ?? config('recording.motion.pre_roll_seconds', 8)))))
                        @php($motionPostTriggerSeconds = max(1, min(60, (int) ($form['recording_motion_post_trigger_seconds'] ?? config('recording.motion.post_trigger_seconds', 20)))))
                        @php($motionSessionUrlBase = $selectedCameraId ? route('camera-fleet.motion-editor-session', ['camera' => $selectedCameraId]) : '')

                        <div class="camera-form-grid">
                            <label class="field-stack">
                                <span>Pre-roll buffer seconds</span>
                                <input class="form-input" type="number" min="0" max="30" wire:model="form.recording_motion_pre_roll_seconds">
                                <small class="probe-note">Saved as context before the monitored span begins.</small>
                                @error('form.recording_motion_pre_roll_seconds')
                                    <small class="field-error">{{ $message }}</small>
                                @enderror
                            </label>

                            <label class="field-stack">
                                <span>Post-trigger seconds</span>
                                <input class="form-input" type="number" min="1" max="60" wire:model="form.recording_motion_post_trigger_seconds">
                                <small class="probe-note">Extra clip length kept after monitoring starts and motion qualifies.</small>
                                @error('form.recording_motion_post_trigger_seconds')
                                    <small class="field-error">{{ $message }}</small>
                                @enderror
                            </label>

                            <article class="detail-card detail-card--inline">
                                <span class="detail-card__label">Buffered clip length</span>
                                <strong>{{ $motionPreRollSeconds + max(3, (int) config('recording.motion.analysis_seconds', 5)) + $motionPostTriggerSeconds }} seconds</strong>
                            </article>
                        </div>

                        <div class="motion-threshold-card">
                            <div>
                                <h4 class="panel-title">Motion activity threshold</h4>
                                <p class="panel-copy">This uses the same activity percentage shown below. Recording starts when current activity reaches this threshold.</p>
                            </div>

                            <div class="motion-threshold-card__control">
                                <label class="field-stack">
                                    <span>Activity percentage needed</span>
                                    <input class="form-input motion-threshold-card__slider" type="range" min="1" max="100" value="{{ $motionThreshold }}" data-role="motion-threshold-input">
                                </label>
                                <strong class="motion-threshold-card__value" data-role="motion-threshold-value">{{ $motionThreshold }}%</strong>
                            </div>
                        </div>

                        @error('form.motion_sensitivity')
                            <small class="field-error">{{ $message }}</small>
                        @enderror

                        @error('form.recording_motion_mask')
                            <small class="field-error">{{ $message }}</small>
                        @enderror

                        <p class="probe-note">The painter starts with the full viewport selected. Paint to keep areas active, erase to ignore noisy zones, and use the live preview to confirm when the selected area crosses the threshold strongly enough to start recording.</p>

                        <div class="motion-editor-shell">
                            @if ($editingCameraId === null)
                                <div class="empty-state empty-state--compact">
                                    <strong>Save a camera before opening the live motion painter.</strong>
                                    <p>The editor needs a saved camera so Laravel can request a secure relay session for the chosen recording profile.</p>
                                </div>
                            @elseif (!($form['supports_rtsp'] ?? false))
                                <div class="empty-state empty-state--compact">
                                    <strong>RTSP support is required for motion editing.</strong>
                                    <p>Enable RTSP for this camera, save the record, and then return to the painter.</p>
                                </div>
                            @else
                                <div
                                    class="motion-editor"
                                    data-motion-editor
                                    data-session-url-base="{{ $motionSessionUrlBase }}"
                                    data-grid-width="{{ $motionMask['grid_width'] ?? 160 }}"
                                    data-grid-height="{{ $motionMask['grid_height'] ?? 90 }}"
                                    data-pixel-delta-threshold="{{ config('recording.motion.pixel_delta_threshold', 18) }}"
                                    wire:key="motion-editor-{{ $selectedCameraId ?? 'new' }}-{{ $form['recording_profile_index'] ?? 'auto' }}"
                                    wire:ignore
                                >
                                    <script type="application/json" data-role="motion-mask-json">@json($motionMask)</script>

                                    <div class="motion-editor__stage wall-tile__stream">
                                        <div class="webrtc-player webrtc-player--single motion-editor__player" data-role="motion-player" data-webrtc-player data-webrtc-player-skip-auto="true" data-player-label="{{ $selectedCamera?->name ?? 'camera' }}">
                                            <video class="webrtc-player__video motion-editor__video" data-role="video" autoplay muted playsinline></video>
                                            <div class="webrtc-player__message motion-editor__message" data-role="message" aria-live="polite">Connecting to the selected recording stream...</div>
                                        </div>

                                        <canvas class="motion-editor__canvas motion-editor__canvas--mask" data-role="mask-canvas"></canvas>
                                        <canvas class="motion-editor__canvas motion-editor__canvas--activity" data-role="activity-canvas"></canvas>
                                        <span class="motion-editor__status-badge" data-role="motion-status" data-state="watching">Armed and watching</span>
                                    </div>

                                    <div class="motion-editor__toolbar">
                                        <div class="motion-editor__tool-group">
                                            <button class="button button--soft motion-editor__tool is-active" type="button" data-role="paint-button" aria-pressed="true">Paint mask</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="erase-button" aria-pressed="false">Erase mask</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="reset-button">Reset full frame</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="clear-button">Clear all</button>
                                        </div>

                                        <label class="field-stack motion-editor__brush-field">
                                            <span>Brush radius</span>
                                            <input class="form-input" type="range" min="1" max="12" value="3" data-role="brush-input">
                                            <strong class="motion-editor__brush-value" data-role="brush-value">3 px</strong>
                                        </label>
                                    </div>

                                    <div class="motion-editor__stats">
                                        <article class="motion-editor__stat-card">
                                            <span>Current activity</span>
                                            <strong data-role="motion-activity-value">0%</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Changed masked pixels</span>
                                            <strong data-role="motion-changed-pixels">0</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Selected mask pixels</span>
                                            <strong data-role="motion-selected-pixels">{{ $motionMask['selected_pixels'] ?? 0 }}</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Recorder state</span>
                                            <strong data-role="motion-state-value">Armed</strong>
                                        </article>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
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
                        <button class="button button--primary" type="button" wire:click="saveCamera" wire:loading.attr="disabled" wire:target="saveCamera" data-role="camera-save-button">
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
                                @php($streamPreviewFingerprint = !empty($profile['preview_path']) ? (($selectedCameraId ?? 'camera').'-'.$loop->index.'-'.($profile['preview_generated_at'] ?? 'fresh').'-'.md5((string) ($profile['preview_path'] ?? ''))) : (($selectedCameraId ?? 'camera').'-'.$loop->index.'-empty'))
                                <article class="stream-card" wire:key="stream-card-{{ $selectedCameraId ?? 'new' }}-{{ $profile['token'] ?? $loop->index }}">
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

                                    <div class="stream-preview-shell" wire:key="stream-preview-{{ $streamPreviewFingerprint }}">
                                        @if (!empty($profile['preview_path']) && $selectedCameraId)
                                            <img class="stream-preview-image" src="{{ route('camera-fleet.preview', ['camera' => $selectedCameraId, 'profileIndex' => $loop->index, 'v' => $profile['preview_generated_at'] ?? '']) }}" alt="Preview snapshot for {{ $profile['name'] ?? 'RTSP profile' }}" loading="lazy" decoding="async">
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
                                            <strong>{{ $appSettings->formatStoredTimestamp($profile['probe_checked_at'] ?? null) ?? ($profile['probe_checked_at'] ?? 'Not tested yet') }}</strong>
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
                                    <span>{{ $appSettings->formatDateTime($recentRecording->scheduled_for, 'Y-m-d H:i') ?? 'Pending' }} · {{ ucfirst($recentRecording->capture_mode) }}</span>
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

@once
    @push('scripts')
        <script src="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}" defer data-navigate-once></script>
        <script src="{{ asset('js/camera-motion-editor.js').'?v='.filemtime(public_path('js/camera-motion-editor.js')) }}" defer data-navigate-once></script>
    @endpush
@endonce