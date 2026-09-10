<div class="fleet-manager">
    <details class="screen-card setup-guide">
        <summary class="setup-guide__toggle">Camera setup guide</summary>
        <div class="fleet-manager__workflow-strip">
        <div class="fleet-manager__workflow-header">
            <div>
                <p class="fleet-manager__workflow-copy">Connect your camera, review its details, then test the stream and choose how to record.</p>
            </div>
        </div>

        <div class="fleet-manager__workflow-steps" aria-label="Camera fleet workflow">
            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">01</span>
                <strong>Probe endpoint</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">02</span>
                <strong>Save camera</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">03</span>
                <strong>Test stream</strong>
            </article>

            <article class="fleet-manager__workflow-step">
                <span class="fleet-manager__workflow-step-number">04</span>
                <strong>Apply recording policy</strong>
            </article>
        </div>
        </div>
    </details>

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
                <p class="panel-copy">Review every saved camera, inspect the latest preview thumbnail, and open the editor for direct ONVIF probing, RTSP retrieval, preview testing, and configuration changes.</p>
            </div>

            <div class="probe-actions">
                <button class="button button--primary" type="button" wire:click="newCamera" data-camera-editor-trigger="new-camera">
                    <span class="button__content">
                        <span class="button__icon-slot" aria-hidden="true">
                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                            </svg>
                        </span>
                        <span>Add camera</span>
                    </span>
                </button>
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
                <p>Start with a direct ONVIF probe, let Camera Fleet hydrate the draft, then save the verified camera here.</p>
                <div class="probe-actions">
                    <button class="button button--primary" type="button" wire:click="newCamera" data-camera-editor-trigger="new-camera">
                        <span class="button__content">
                            <span class="button__icon-slot" aria-hidden="true">
                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14"></path>
                                    <path d="M5 12h14"></path>
                                </svg>
                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                </svg>
                            </span>
                            <span>Create camera</span>
                        </span>
                    </button>
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
                                <button class="button button--primary" type="button" wire:click="editCamera({{ $camera->id }})" wire:loading.attr="disabled" wire:target="editCamera({{ $camera->id }})" data-camera-editor-trigger="camera-{{ $camera->id }}">
                                    <span class="button__content">
                                        <span class="button__icon-slot" aria-hidden="true">
                                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 20h9"></path>
                                                <path d="M16.5 3.5l4 4L7 21H3v-4z"></path>
                                            </svg>
                                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                                            </svg>
                                        </span>
                                        <span>Edit camera</span>
                                    </span>
                                </button>
                                <button class="button button--soft" type="button" wire:click="toggleEnabled({{ $camera->id }})" wire:loading.attr="disabled" wire:target="toggleEnabled({{ $camera->id }})">
                                    <span class="button__content">
                                        <span class="button__icon-slot" aria-hidden="true">
                                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 2v10"></path>
                                                <path d="M7.05 4.93a7 7 0 1 0 9.9 0"></path>
                                            </svg>
                                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                                            </svg>
                                        </span>
                                        <span>{{ $camera->is_enabled ? 'Disable' : 'Enable' }}</span>
                                    </span>
                                </button>
                                @if ($pendingDeleteCameraId === $camera->id)
                                    <button class="button button--primary" type="button" wire:click="deleteCamera({{ $camera->id }})" wire:loading.attr="disabled" wire:target="deleteCamera({{ $camera->id }})">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M4 7h16"></path>
                                                    <path d="M10 11v6"></path>
                                                    <path d="M14 11v6"></path>
                                                    <path d="M6 7l1 12h10l1-12"></path>
                                                    <path d="M9 7V5h6v2"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Confirm delete</span>
                                        </span>
                                    </button>
                                    <button class="button button--soft" type="button" wire:click="cancelDeleteCamera">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M6 6l12 12"></path>
                                                    <path d="M18 6L6 18"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Cancel</span>
                                        </span>
                                    </button>
                                @else
                                    <button class="button button--soft" type="button" wire:click="requestDeleteCamera({{ $camera->id }})" wire:loading.attr="disabled" wire:target="requestDeleteCamera({{ $camera->id }})">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M4 7h16"></path>
                                                    <path d="M10 11v6"></path>
                                                    <path d="M14 11v6"></path>
                                                    <path d="M6 7l1 12h10l1-12"></path>
                                                    <path d="M9 7V5h6v2"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Delete</span>
                                        </span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if ($isEditorModalOpen)
        <div class="fleet-modal" data-camera-editor-modal>
            <button class="fleet-modal__backdrop" type="button" tabindex="-1" wire:click="closeEditorModal" aria-label="Close camera editor"></button>

            <section class="fleet-modal__panel" role="dialog" aria-modal="true" aria-labelledby="camera-editor-title" aria-describedby="camera-editor-description" tabindex="-1" wire:click.stop>
                <header class="camera-editor__masthead">
                <div class="panel-heading camera-editor__heading">
                    <div>
                        <span class="eyebrow">{{ $editingCameraId ? 'Camera settings' : 'New camera' }}</span>
                        <h2 id="camera-editor-title" class="panel-title">{{ $editingCameraId ? ($form['name'] ?: 'Unnamed camera') : 'Add a camera' }}</h2>
                        <p id="camera-editor-description" class="panel-copy">{{ $editingCameraId ? 'Update connection, live view, and recording settings. Changes take effect after you save.' : 'Verify an ONVIF camera first, or choose RTSP-only setup for a direct stream.' }}</p>
                    </div>

                    <div class="probe-actions">
                        @if ($editingCameraId)
                            <button class="button button--soft" type="button" wire:click="fetchRtspProfiles" wire:loading.attr="disabled" wire:target="fetchRtspProfiles">
                                <span class="button__content">
                                    <span class="button__icon-slot" aria-hidden="true">
                                        <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 12a9 9 0 0 1 15.3-6.36L21 8"></path>
                                            <path d="M21 3v5h-5"></path>
                                            <path d="M21 12a9 9 0 0 1-15.3 6.36L3 16"></path>
                                            <path d="M3 21v-5h5"></path>
                                        </svg>
                                        <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                            <path d="M20 12a8 8 0 0 0-8-8"></path>
                                        </svg>
                                    </span>
                                    <span>Refresh streams</span>
                                </span>
                            </button>
                        @endif

                        <button class="button button--soft camera-editor__close" type="button" wire:click="closeEditorModal" data-camera-editor-close aria-label="Close without saving">
                            <span class="button__content">
                                <span class="button__icon-slot" aria-hidden="true">
                                    <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 6l12 12"></path>
                                        <path d="M18 6L6 18"></path>
                                    </svg>
                                    <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                        <path d="M20 12a8 8 0 0 0-8-8"></path>
                                    </svg>
                                </span>
                                <span>Cancel</span>
                            </span>
                        </button>
                    </div>
                </div>

                <nav class="camera-editor__nav" aria-label="Camera settings sections">
                    @if (!$editingCameraId)
                        <a href="#camera-editor-intake">Setup</a>
                    @endif
                    @if ($selectedCamera || $probeResponse !== [] || !($form['supports_onvif'] ?? true))
                        <a href="#camera-editor-identity">Identity</a>
                        <a href="#camera-editor-stream-access">Stream access</a>
                        <a href="#camera-editor-live-relay">Live relay</a>
                        <a href="#camera-editor-recording">Recording</a>
                        <a href="#camera-editor-state">Availability</a>
                        <a href="#camera-editor-streams">Diagnostics</a>
                        @if ($editingCameraId)
                            <a href="#camera-editor-activity">Activity</a>
                        @endif
                    @endif
                </nav>
                </header>

                <div class="notice-stack" aria-live="polite">
                    @if ($statusMessage)
                        <div class="notice notice--success">{{ $statusMessage }}</div>
                    @endif

                    @if ($errorMessage)
                        <div class="notice notice--danger">{{ $errorMessage }}</div>
                    @endif

                    @if ($probeStatusMessage)
                        <div class="notice notice--success">{{ $probeStatusMessage }}</div>
                    @endif

                    @if ($probeWarningMessage)
                        <div class="notice notice--danger">{{ $probeWarningMessage }}</div>
                    @endif

                    @if ($probeErrorMessage)
                        <div class="notice notice--danger">{{ $probeErrorMessage }}</div>
                    @endif

                    @if ($rtspStatusMessage)
                        <div class="notice notice--success">{{ $rtspStatusMessage }}</div>
                    @endif

                    @if ($rtspErrorMessage)
                        <div class="notice notice--danger">{{ $rtspErrorMessage }}</div>
                    @endif
                </div>

                @if (!$editingCameraId)
                    <section class="form-section" id="camera-editor-intake">
                        <div class="form-section__header">
                            <div>
                                <h3 class="panel-title">Camera intake</h3>
                                <p class="panel-copy">Use the ONVIF probe for cameras that expose device services, or bypass it for RTSP-only cameras that need a manual stream definition.</p>
                            </div>
                        </div>

                        @if ($form['supports_onvif'] ?? true)
                            <div class="camera-form-grid">
                                <label class="field-stack field-stack--wide">
                                    <span>ONVIF endpoint URL</span>
                                    <input class="form-input" type="url" placeholder="http://192.168.1.90/onvif/device_service" wire:model="probeEndpointUrl">
                                    @error('probeEndpointUrl')
                                        <small class="field-error">{{ $message }}</small>
                                    @enderror
                                </label>

                                <label class="field-stack">
                                    <span>Probe username</span>
                                    <input class="form-input" type="text" autocomplete="username" wire:model="form.username">
                                    @error('form.username')
                                        <small class="field-error">{{ $message }}</small>
                                    @enderror
                                </label>

                                <label class="field-stack">
                                    <span>Probe password</span>
                                    <input class="form-input" type="password" autocomplete="current-password" wire:model="form.password">
                                    @error('form.password')
                                        <small class="field-error">{{ $message }}</small>
                                    @enderror
                                </label>
                            </div>

                            <div class="probe-actions">
                                <button class="button button--primary" type="button" wire:click="probeEndpoint" wire:loading.attr="disabled" wire:target="probeEndpoint">
                                    <span class="button__content">
                                        <span class="button__icon-slot" aria-hidden="true">
                                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="6"></circle>
                                                <path d="M20 20l-4-4"></path>
                                            </svg>
                                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                                            </svg>
                                        </span>
                                        <span>Probe endpoint</span>
                                    </span>
                                </button>

                                <button class="button button--soft" type="button" wire:click="enableRtspOnlyMode">
                                    <span class="button__content">
                                        <span class="button__icon-slot" aria-hidden="true">
                                            <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="6" width="18" height="12" rx="2"></rect>
                                                <path d="M10 10l5 2-5 2z"></path>
                                            </svg>
                                            <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                <path d="M20 12a8 8 0 0 0-8-8"></path>
                                            </svg>
                                        </span>
                                        <span>This is an RTSP-only camera</span>
                                    </span>
                                </button>
                            </div>

                            <div class="probe-meta-row">
                                <p class="probe-note">The probe verifies ONVIF device information, attempts to read interface details such as MAC address, and then requests ONVIF media profiles to prefill RTSP stream URLs.</p>
                                @if ($probeLastCheckedAt)
                                    <span class="status-pill">Last probe {{ $probeLastCheckedAt }}</span>
                                @endif
                                @if ($probeResponse !== [])
                                    <span class="status-pill status-pill--good">{{ $probeResponse['rtsp_profile_count'] ?? count($rtspProfiles) }} RTSP profile{{ (int) ($probeResponse['rtsp_profile_count'] ?? count($rtspProfiles)) === 1 ? '' : 's' }} discovered</span>
                                @endif
                            </div>

                            @if ($probeResponse !== [])
                                <div class="detail-grid">
                                    <article class="detail-card">
                                        <span class="detail-card__label">ONVIF endpoint</span>
                                        <strong>{{ $probeResponse['service_url'] }}</strong>
                                    </article>

                                    <article class="detail-card">
                                        <span class="detail-card__label">Identity</span>
                                        <strong>{{ trim(($probeResponse['manufacturer'] ?? '').' '.($probeResponse['model'] ?? '')) ?: 'Camera responded' }}</strong>
                                    </article>

                                    <article class="detail-card">
                                        <span class="detail-card__label">Local IP</span>
                                        <strong>{{ $probeResponse['ipv4_address'] ?? $form['local_ip'] }}</strong>
                                    </article>

                                    <article class="detail-card">
                                        <span class="detail-card__label">MAC address</span>
                                        <strong>{{ $probeResponse['mac_address'] ?? 'Not returned' }}</strong>
                                    </article>

                                    <article class="detail-card">
                                        <span class="detail-card__label">Serial number</span>
                                        <strong>{{ $probeResponse['serial_number'] ?? 'Not returned' }}</strong>
                                    </article>

                                    <article class="detail-card">
                                        <span class="detail-card__label">Primary RTSP stream</span>
                                        <strong>{{ $probeResponse['primary_rtsp_uri'] ?? 'No media URI returned' }}</strong>
                                    </article>
                                </div>
                            @endif
                        @else
                            <div class="empty-state empty-state--compact">
                                <strong>RTSP-only mode is enabled.</strong>
                                <p>Configure the manual RTSP endpoint below, save the camera, and then use Camera Fleet to register and test the saved stream.</p>
                                <div class="probe-actions">
                                    <button class="button button--soft" type="button" wire:click="enableOnvifProbeMode">
                                        <span class="button__content">
                                            <span class="button__icon-slot" aria-hidden="true">
                                                <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="11" cy="11" r="6"></circle>
                                                    <path d="M20 20l-4-4"></path>
                                                </svg>
                                                <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                    <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                </svg>
                                            </span>
                                            <span>Use ONVIF probe instead</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </section>
                @endif

                @if ($selectedCamera || $probeResponse !== [] || !($form['supports_onvif'] ?? true))
                    @if ($selectedCamera)
                        <details class="screen-card setup-guide camera-editor__summary">
                            <summary class="setup-guide__toggle">Saved camera details</summary>
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
                        </details>
                    @endif

                <section class="form-section" id="camera-editor-identity">
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
                            <span>MAC address</span>
                            <input class="form-input" type="text" placeholder="AA:BB:CC:DD:EE:FF" wire:model="form.mac_address">
                            @error('form.mac_address')
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

                <section class="form-section" id="camera-editor-stream-access">
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
                            <span>Live feed path</span>
                            <input class="form-input" type="text" placeholder="/stream1" list="camera-live-path-options" wire:model="form.rtsp_path">
                            @error('form.rtsp_path')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                            <small class="probe-note">Choose the exact RTSP path operators should watch live.</small>
                        </label>

                        <label class="field-stack field-stack--wide">
                            <span>Recording path</span>
                            <input class="form-input" type="text" placeholder="/stream1" list="camera-recording-path-options" wire:model="form.recording_rtsp_path">
                            @error('form.recording_rtsp_path')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                            <small class="probe-note">Use the exact RTSP path the recorder should capture. This can be the same as the live feed path.</small>
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

                        <div class="field-stack field-stack--password">
                            <label for="camera-password-field">
                                <span>Password</span>
                            </label>
                            <div class="field-stack__control-row">
                                <input id="camera-password-field" class="form-input" type="{{ $showCameraPassword ? 'text' : 'password' }}" autocomplete="current-password" wire:model="form.password">
                                <button class="button button--soft field-stack__inline-action" type="button" wire:click="toggleCameraPasswordVisibility">
                                    <span class="button__content">
                                        <span>{{ $showCameraPassword ? 'Hide' : 'Show' }}</span>
                                    </span>
                                </button>
                            </div>
                            @error('form.password')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                            @if ($editingCameraId && $hasStoredPassword)
                                <small class="probe-note">The saved password is loaded here. Use Show to inspect it, edit it directly, or clear it before saving.</small>
                            @endif
                        </div>
                    </div>

                    <datalist id="camera-live-path-options">
                        @foreach ($rtspProfiles as $profile)
                            @if (!empty($profile['path']))
                                <option value="{{ $profile['path'] }}">{{ $profile['name'] ?? 'RTSP profile '.($loop->index + 1) }}</option>
                            @endif
                        @endforeach
                    </datalist>

                    <datalist id="camera-recording-path-options">
                        @foreach ($rtspProfiles as $profile)
                            @if (!empty($profile['path']))
                                <option value="{{ $profile['path'] }}">{{ $profile['name'] ?? 'RTSP profile '.($loop->index + 1) }}</option>
                            @endif
                        @endforeach
                    </datalist>
                </section>

                <section class="form-section" id="camera-editor-live-relay">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Live relay transcoding</h3>
                            <p class="panel-copy">Override the browser-facing live relay only for this camera when a feed needs different transcode behavior. Quality profiles are shown by their actual preset and CRF names, with a quick CPU cost hint.</p>
                        </div>
                    </div>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>Preset and quality target</span>
                            <select class="form-select" wire:model="form.live_transcode_quality">
                                @foreach ($liveTranscodeQualityOptions as $qualityValue => $qualityLabel)
                                    <option value="{{ $qualityValue }}">{{ $qualityLabel }}</option>
                                @endforeach
                            </select>
                            <small class="probe-note">Lower preset speeds improve detail but cost more CPU. CRF is ignored if you switch rate control to CBR.</small>
                            @error('form.live_transcode_quality')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Rate control</span>
                            <select class="form-select" wire:model.live="form.live_transcode_rate_control">
                                @foreach ($liveTranscodeRateControlOptions as $rateControlValue => $rateControlLabel)
                                    <option value="{{ $rateControlValue }}">{{ $rateControlLabel }}</option>
                                @endforeach
                            </select>
                            @error('form.live_transcode_rate_control')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>CBR target bitrate</span>
                            <input class="form-input" type="number" min="250" max="20000" step="50" wire:model="form.live_transcode_bitrate_kbps" {{ ($form['live_transcode_rate_control'] ?? App\Models\Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT) === App\Models\Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR ? '' : 'disabled' }}>
                            <small class="probe-note">Enter kilobits per second. Only used when constant bitrate mode is selected.</small>
                            @error('form.live_transcode_bitrate_kbps')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="field-stack">
                            <span>Video compatibility</span>
                            <span><input type="checkbox" wire:model="form.live_transcode_force_video"> Always normalize video through H.264</span>
                            <small class="probe-note">Use for camera bitstreams that are nominally H.264 but still freeze or lose keyframes in browsers. This costs additional relay CPU.</small>
                            @error('form.live_transcode_force_video')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>

                    <p class="probe-note">These settings affect only the live-wall relay. Recordings and the raw RTSP source path stay untouched.</p>
                </section>

                <section class="form-section" id="camera-editor-recording">
                    <div class="form-section__header">
                        <div>
                            <h3 class="panel-title">Recording policy</h3>
                            <p class="panel-copy">Choose whether this feed stays off, records continuously, or records only when the selected motion region changes enough to cross the threshold.</p>
                        </div>
                    </div>

                    <div class="camera-form-grid">
                        <label class="field-stack">
                            <span>Recording mode</span>
                            <select class="form-select" wire:model.live="form.recording_mode">
                                @foreach ($recordingModes as $recordingModeValue => $recordingModeLabel)
                                    <option value="{{ $recordingModeValue }}">{{ $recordingModeLabel }}</option>
                                @endforeach
                            </select>
                            @error('form.recording_mode')
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
                        @php($selectedMotionPixels = max(0, (int) ($motionMask['selected_pixels'] ?? 0)))
                        @php($motionTriggerPixels = max(1, (int) ($form['recording_motion_trigger_pixels'] ?? max(1, (int) ceil(max(1, $selectedMotionPixels) * 0.35)))))
                        @php($motionTriggerPixelLimit = max(1, $selectedMotionPixels + ($selectedMotionPixels * max(0, (int) config('recording.motion.cluster_bonus_multiplier', 2)))))
                        @php($motionPreRollSeconds = max(0, min(30, (int) ($form['recording_motion_pre_roll_seconds'] ?? config('recording.motion.pre_roll_seconds', 8)))))
                        @php($motionPostTriggerSeconds = max(1, min(60, (int) ($form['recording_motion_post_trigger_seconds'] ?? config('recording.motion.post_trigger_seconds', 20)))))
                        @php($motionSessionUrlBase = $selectedCameraId ? route('camera-fleet.motion-editor-session', ['camera' => $selectedCameraId]) : '')
                        @php($motionAnalysisUrl = $selectedCameraId ? route('camera-fleet.motion-editor-analysis', ['camera' => $selectedCameraId]) : '')

                        <div class="camera-form-grid">
                            <label class="field-stack">
                                <span>Pre-roll buffer seconds</span>
                                <input class="form-input" type="number" min="0" max="30" wire:model="form.recording_motion_pre_roll_seconds">
                                <small class="probe-note">Footage kept before the first detected movement.</small>
                                @error('form.recording_motion_pre_roll_seconds')
                                    <small class="field-error">{{ $message }}</small>
                                @enderror
                            </label>

                            <label class="field-stack">
                                <span>Post-trigger seconds</span>
                                <input class="form-input" type="number" min="1" max="60" wire:model="form.recording_motion_post_trigger_seconds">
                                <small class="probe-note">Keep recording this long after the last detected movement. New movement restarts this timer.</small>
                                @error('form.recording_motion_post_trigger_seconds')
                                    <small class="field-error">{{ $message }}</small>
                                @enderror
                            </label>

                            <article class="detail-card detail-card--inline">
                                <span class="detail-card__label">Event length</span>
                                <strong>Extends while movement continues</strong>
                                <small class="probe-note">Includes {{ $motionPreRollSeconds }} seconds before movement and {{ $motionPostTriggerSeconds }} quiet seconds afterward. Timing follows recorder segment boundaries.</small>
                            </article>
                        </div>

                        <div class="motion-threshold-card" wire:key="motion-threshold-{{ $selectedCameraId ?? 'new' }}" wire:ignore>
                            <div>
                                <h4 class="panel-title">Motion trigger pixels</h4>
                                <p class="panel-copy">Lower values trigger on smaller movements; higher values require more movement. Recording starts when the effective trigger pixels reach this number. Dense clusters add bonus effective pixels so solid moving objects count more than scattered speckles.</p>
                            </div>

                            <div class="motion-threshold-card__control">
                                <label class="field-stack">
                                    <span>Trigger pixels needed</span>
                                    <input class="form-input motion-threshold-card__input" type="number" min="1" max="{{ $motionTriggerPixelLimit }}" step="1" value="{{ min($motionTriggerPixelLimit, $motionTriggerPixels) }}" wire:model="form.recording_motion_trigger_pixels" data-role="motion-trigger-pixels-input">
                                    <small class="probe-note">Current mask supports up to <span data-role="motion-trigger-limit">{{ $motionTriggerPixelLimit }}</span> effective trigger pixels with cluster weighting.</small>
                                </label>
                                <strong class="motion-threshold-card__value" data-role="motion-trigger-pixels-value">{{ min($motionTriggerPixelLimit, $motionTriggerPixels) }}</strong>
                            </div>
                        </div>

                        @error('form.recording_motion_trigger_pixels')
                            <small class="field-error">{{ $message }}</small>
                        @enderror

                        @error('form.recording_motion_mask')
                            <small class="field-error">{{ $message }}</small>
                        @enderror

                        <p class="probe-note">New masks start with the full image selected; saved masks keep your chosen areas. Paint to keep areas active, erase to ignore noisy zones, and use the live preview to see exactly which cells are active before the recorder trips on effective trigger pixels.</p>

                        <div class="motion-editor-shell">
                            @if ($editingCameraId === null)
                                <div class="empty-state empty-state--compact">
                                    <strong>Save a camera before opening the live motion painter.</strong>
                                    <p>The editor needs a saved camera so Laravel can request a secure relay session for the chosen recording path.</p>
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
                                    data-analysis-url="{{ $motionAnalysisUrl }}"
                                    data-analysis-interval-ms="{{ (int) config('recording.motion.editor_poll_interval_ms', 150) }}"
                                    data-whep-player-script-url="{{ asset('js/live-wall-player.js').'?v='.filemtime(public_path('js/live-wall-player.js')) }}"
                                    data-grid-width="{{ $motionMask['grid_width'] ?? 160 }}"
                                    data-grid-height="{{ $motionMask['grid_height'] ?? 90 }}"
                                    data-cluster-bonus-multiplier="{{ config('recording.motion.cluster_bonus_multiplier', 2) }}"
                                    wire:key="motion-editor-{{ $selectedCameraId ?? 'new' }}-{{ md5((string) ($selectedCamera?->recording_rtsp_path ?? '')) }}"
                                    wire:ignore
                                >
                                    <script type="application/json" data-role="motion-mask-json">@json($motionMask)</script>

                                    <div class="motion-editor__stage wall-tile__stream">
                                        <div class="webrtc-player webrtc-player--single motion-editor__player" data-role="motion-player" data-webrtc-player data-webrtc-player-skip-auto="true" data-player-label="{{ $selectedCamera?->name ?? 'camera' }}">
                                            <video class="webrtc-player__video motion-editor__video" data-role="video" autoplay muted playsinline></video>
                                            <div class="webrtc-player__message motion-editor__message" data-role="message" aria-live="polite">Connecting to the configured recording path...</div>
                                        </div>

                                        <canvas class="motion-editor__canvas motion-editor__canvas--mask" data-role="mask-canvas"></canvas>
                                        <canvas class="motion-editor__canvas motion-editor__canvas--activity" data-role="activity-canvas"></canvas>
                                        <span class="motion-editor__status-badge" data-role="motion-status" data-state="waiting">Starting live motion analysis</span>
                                    </div>

                                    <div class="motion-editor__toolbar">
                                        <div class="motion-editor__tool-group">
                                            <button class="button button--soft motion-editor__tool is-active" type="button" data-role="paint-button" aria-pressed="true">Paint mask</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="erase-button" aria-pressed="false">Erase mask</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="reset-button">Reset full frame</button>
                                            <button class="button button--soft motion-editor__tool" type="button" data-role="clear-button">Clear all</button>
                                            <button class="button button--soft" type="button" data-role="preview-retry">Reconnect preview</button>
                                        </div>

                                        <label class="field-stack motion-editor__brush-field">
                                            <span>Brush radius</span>
                                            <input class="form-input" type="range" min="1" max="12" value="3" data-role="brush-input">
                                            <strong class="motion-editor__brush-value" data-role="brush-value">3 px</strong>
                                        </label>
                                    </div>

                                    <p class="probe-note motion-editor__hint">Blue cells select where motion counts. Amber cells show activity below the threshold; red cells meet it. Activity is sampled live from the same source as the recorder; a short confirmation delay filters out image refreshes. Moving cells are counted once; connected groups also earn bonus trigger pixels. These settings control BigBrotha recording, not the camera’s built-in motion alarm. This preview uses the saved recording path; save stream changes to apply them.</p>
                                    <p class="probe-note" data-role="motion-analysis-note">Starting live motion analysis…</p>
                                    <p class="probe-note" data-role="motion-sample-age">Waiting for sample</p>
                                    <p class="probe-note" data-role="motion-draft-note">Checking saved settings…</p>
                                    <label class="motion-editor__meter">
                                        <span>Movement toward trigger threshold</span>
                                        <meter data-role="motion-trigger-meter" min="0" max="{{ $motionTriggerPixels }}" value="0">Waiting for movement data</meter>
                                    </label>

                                    <div class="motion-editor__stats">
                                        <article class="motion-editor__stat-card">
                                            <span>Moving cells</span>
                                            <strong data-role="motion-moving-pixels">—</strong>
                                            <small><span data-role="motion-activity-value">—</span> of selected area</small>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Effective trigger pixels</span>
                                            <strong data-role="motion-trigger-pixels">—</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Pixels needed</span>
                                            <strong data-role="motion-pixels-needed">0</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Selected mask pixels</span>
                                            <strong data-role="motion-selected-pixels">{{ $motionMask['selected_pixels'] ?? 0 }}</strong>
                                        </article>

                                        <article class="motion-editor__stat-card">
                                            <span>Saved recording event</span>
                                            <strong data-role="motion-state-value">Starting motion analysis</strong>
                                        </article>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </section>

                <section class="form-section" id="camera-editor-state">
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
                            <input type="checkbox" wire:model.live="form.supports_rtsp">
                            <span>RTSP enabled</span>
                        </label>

                        <label class="checkbox-field">
                            <input type="checkbox" wire:model="form.is_enabled">
                            <span>Camera enabled</span>
                        </label>
                    </div>

                </section>

                <section class="form-section" id="camera-editor-streams">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-title">RTSP stream URLs</h3>
                            <p class="panel-copy">Fetch ONVIF media profiles when they are available, or review the stream URLs already hydrated from the create-time probe before the first save.</p>
                        </div>
                    </div>

                    @if ($editingCameraId === null && ($form['supports_onvif'] ?? true) && $probeResponse === [])
                        <div class="empty-state empty-state--compact">
                            <strong>Probe a camera first.</strong>
                            <p>The create flow now hydrates RTSP stream URLs from the direct ONVIF probe before the first save.</p>
                        </div>
                    @elseif ($editingCameraId === null && !($form['supports_onvif'] ?? true) && $rtspProfiles === [])
                        <div class="empty-state empty-state--compact">
                            <strong>This RTSP-only draft has no saved profile list yet.</strong>
                            <p>Save the camera first. After that, Camera Fleet can register the configured RTSP endpoint as a manual profile and run a stream test.</p>
                        </div>
                    @elseif ($rtspProfiles === [])
                        <div class="empty-state empty-state--compact">
                            <strong>No RTSP stream URLs are available yet.</strong>
                            <p>{{ $editingCameraId ? 'Use the refresh control above to query ONVIF media profiles or save the configured RTSP endpoint as a manual stream when ONVIF is unavailable.' : 'The endpoint responded to ONVIF, but no media profiles were returned during the create-time hydration step.' }}</p>
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
                                        @if ($editingCameraId)
                                            <button class="button button--soft" type="button" wire:click="testRtspProfile({{ $loop->index }})" wire:loading.attr="disabled" wire:target="testRtspProfile({{ $loop->index }})">
                                                <span class="button__content">
                                                    <span class="button__icon-slot" aria-hidden="true">
                                                        <svg class="button__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <rect x="3" y="6" width="18" height="12" rx="2"></rect>
                                                            <path d="M10 10l5 2-5 2z"></path>
                                                        </svg>
                                                        <svg class="button__spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                            <circle cx="12" cy="12" r="8" opacity="0.28"></circle>
                                                            <path d="M20 12a8 8 0 0 0-8-8"></path>
                                                        </svg>
                                                    </span>
                                                    <span>Run stream test</span>
                                                </span>
                                            </button>
                                        @else
                                            <span class="probe-note">Save the camera before preview capture and live stream tests are available.</span>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="form-section" id="camera-editor-activity">
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
                @else
                    <div class="empty-state empty-state--compact">
                        <strong>Probe a reachable ONVIF endpoint or switch to RTSP-only mode.</strong>
                        <p>ONVIF-capable devices should be verified first so Camera Fleet can prefill identity, network, and stream values before the record is inserted.</p>
                    </div>
                @endif

                @if ($selectedCamera || $probeResponse !== [] || !($form['supports_onvif'] ?? true))
                    @php($usesMotionEditorSave = (($form['recording_mode'] ?? null) === App\Models\Camera::RECORDING_MODE_MOTION) && $editingCameraId !== null && ($form['supports_rtsp'] ?? false))
                    <footer class="camera-editor__actions">
                        <p>
                            <strong>{{ $editingCameraId ? 'Ready to apply your camera changes?' : 'Ready to add this camera?' }}</strong>
                            <span>{{ $usesMotionEditorSave ? 'The current motion mask and all settings will be saved together.' : 'Review the highlighted validation messages if saving fails.' }}</span>
                        </p>

                        <div class="probe-actions">
                            <button class="button button--soft" type="button" wire:click="closeEditorModal" data-camera-editor-close>Cancel</button>
                            <button
                                class="button button--primary"
                                type="button"
                                wire:click="saveCamera"
                                wire:loading.attr="disabled"
                                wire:target="saveCamera,saveCameraFromMotionEditor"
                                data-role="camera-save-button"
                            >
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
                                    <span>{{ $editingCameraId ? 'Save changes' : 'Create camera' }}</span>
                                </span>
                            </button>
                        </div>
                    </footer>
                @endif
            </section>
        </div>
    @endif
</div>
