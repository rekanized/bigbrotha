<div class="discovery-stack">
    <section class="screen-card screen-card--accent screen-summary-strip">
        <div class="screen-summary-strip__body">
            <div>
                <span class="eyebrow">Discovery paths</span>
                <p class="screen-summary-strip__copy">Sweep first, probe directly when multicast or network layout gets in the way.</p>
            </div>

            <div class="badge-row">
                <span class="status-pill status-pill--neutral">{{ $deviceCount }} device{{ $deviceCount === 1 ? '' : 's' }} listed</span>
                @if ($lastSweepAt)
                    <span class="status-pill">Last sweep {{ $lastSweepAt }}</span>
                @endif
            </div>
        </div>

        <div class="screen-summary-strip__steps" aria-label="Discovery workflow">
            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">01</span>
                <strong>Run WS-Discovery</strong>
            </article>

            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">02</span>
                <strong>Probe known endpoint</strong>
            </article>

            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">03</span>
                <strong>Save verified result</strong>
            </article>
        </div>
    </section>

    <div class="sweep-toolbar">
        <label class="field-stack" for="timeoutMs">
            <span>Listen window</span>
            <select id="timeoutMs" class="form-select" wire:model.live="timeoutMs">
                @foreach ($timeoutOptions as $timeoutOption)
                    <option value="{{ $timeoutOption }}">{{ number_format($timeoutOption / 1000, 1) }} seconds</option>
                @endforeach
            </select>
        </label>

        <div class="sweep-toolbar__actions">
            <button class="button button--primary" type="button" wire:click="discover" wire:loading.attr="disabled" wire:target="discover">
                <span wire:loading.remove wire:target="discover">Run WS-Discovery sweep</span>
                    <span wire:loading wire:target="discover">Sweeping network...</span>
            </button>
        </div>
    </div>

    <section class="manual-probe-panel">
        <div class="panel-heading">
            <div>
                <h3 class="panel-title">Manual ONVIF authentication test</h3>
                <p class="panel-copy">Paste a device service URL, add credentials, and send a GetDeviceInformation request directly to the endpoint. This bypasses multicast discovery and confirms whether the camera answers ONVIF requests from this host.</p>
            </div>
        </div>

        <div class="probe-form-grid">
            <label class="field-stack field-stack--wide" for="manualServiceUrl">
                <span>ONVIF service URL</span>
                <input id="manualServiceUrl" class="form-input" type="url" placeholder="http://192.168.1.90/onvif/device_service" wire:model="manualServiceUrl">
                @error('manualServiceUrl')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="field-stack" for="manualUsername">
                <span>Username</span>
                <input id="manualUsername" class="form-input" type="text" autocomplete="username" wire:model="manualUsername">
                @error('manualUsername')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="field-stack" for="manualPassword">
                <span>Password</span>
                <input id="manualPassword" class="form-input" type="password" autocomplete="current-password" wire:model="manualPassword">
                @error('manualPassword')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>

            <div class="probe-form-grid__actions">
                <button class="button button--primary" type="button" wire:click="probeDeviceService" wire:loading.attr="disabled" wire:target="probeDeviceService">
                    <span wire:loading.remove wire:target="probeDeviceService">Probe endpoint</span>
                    <span wire:loading wire:target="probeDeviceService">Probing endpoint...</span>
                </button>
            </div>
        </div>

        <div class="probe-meta-row">
            <p class="probe-note">The probe sends an ONVIF GetDeviceInformation SOAP request with WS-Security credentials when username and password are provided.</p>
            @if ($manualProbeLastCheckedAt)
                <span class="status-pill">Last probe {{ $manualProbeLastCheckedAt }}</span>
            @endif
        </div>

        <div class="notice-stack" aria-live="polite">
            @if ($manualProbeError)
                <div class="notice notice--danger">{{ $manualProbeError }}</div>
            @endif

            @if ($manualProbeSavedMessage)
                <div class="notice notice--success">{{ $manualProbeSavedMessage }}</div>
            @endif
        </div>

        @if ($manualProbeResponse !== [])
            <article class="device-card">
                <div class="device-card__header">
                    <div>
                        <strong>{{ $manualProbeResponse['model'] ?? 'ONVIF endpoint responded' }}</strong>
                        <p>{{ $manualProbeResponse['service_url'] }}</p>
                    </div>

                    <span class="status-pill status-pill--good">HTTP {{ $manualProbeResponse['http_status'] }}</span>
                </div>

                <div class="key-value-list key-value-list--dense">
                    <div class="key-value-row">
                        <span>Suggested setup</span>
                        <strong>{{ $manualProbeResponse['service_url'] }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Authentication</span>
                        <strong>{{ !empty($manualProbeResponse['authenticated']) ? 'Username token sent' : 'No credentials used' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Response time</span>
                        <strong>{{ $manualProbeResponse['response_time_ms'] }} ms</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Manufacturer</span>
                        <strong>{{ $manualProbeResponse['manufacturer'] ?? 'Not returned' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Model</span>
                        <strong>{{ $manualProbeResponse['model'] ?? 'Not returned' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Firmware</span>
                        <strong>{{ $manualProbeResponse['firmware_version'] ?? 'Not returned' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Serial number</span>
                        <strong>{{ $manualProbeResponse['serial_number'] ?? 'Not returned' }}</strong>
                    </div>

                    <div class="key-value-row">
                        <span>Hardware ID</span>
                        <strong>{{ $manualProbeResponse['hardware_id'] ?? 'Not returned' }}</strong>
                    </div>

                    @if (!empty($manualProbeResponse['response_excerpt']))
                        <div class="key-value-row">
                            <span>Response excerpt</span>
                            <strong>{{ $manualProbeResponse['response_excerpt'] }}</strong>
                        </div>
                    @endif
                </div>

                <div class="probe-actions">
                    <button class="button button--soft" type="button" wire:click="saveManualProbeToFleet" wire:loading.attr="disabled" wire:target="saveManualProbeToFleet">
                        <span wire:loading.remove wire:target="saveManualProbeToFleet">Save to fleet</span>
                        <span wire:loading wire:target="saveManualProbeToFleet">Saving camera...</span>
                    </button>

                    <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Open fleet</a>
                </div>
            </article>
        @endif
    </section>

    @if ($error)
        <div class="notice notice--danger">{{ $error }}</div>
    @endif

    @if ($devices === [])
        <div class="empty-state empty-state--compact">
            <strong>No ONVIF devices discovered yet.</strong>
            <p>Run the sweep across the local network. If nothing appears, confirm broadcast-domain reachability, then switch to the manual probe path above.</p>
        </div>
    @else
        <div class="device-grid">
            @foreach ($devices as $device)
                <article class="device-card" wire:key="{{ $device['endpoint_reference'] ?? $device['remote_ip'] }}">
                    <div class="device-card__header">
                        <div>
                            <strong>{{ $device['name'] }}</strong>
                            <p>{{ $device['remote_ip'] }}{{ !empty($device['port']) ? ' · '.$device['port'] : '' }}</p>
                        </div>

                        <span class="status-pill status-pill--good">ONVIF</span>
                    </div>

                    <div class="key-value-list key-value-list--dense">
                        <div class="key-value-row">
                            <span>Service URL</span>
                            <strong>{{ $device['service_url'] ?? 'Unavailable' }}</strong>
                        </div>

                        @if (!empty($device['manufacturer']) || !empty($device['model']))
                            <div class="key-value-row">
                                <span>Identity</span>
                                <strong>{{ trim(($device['manufacturer'] ?? '').' '.($device['model'] ?? '')) ?: 'Unavailable' }}</strong>
                            </div>
                        @endif

                        @if (!empty($device['serial_number']))
                            <div class="key-value-row">
                                <span>Serial</span>
                                <strong>{{ $device['serial_number'] }}</strong>
                            </div>
                        @endif

                        <div class="key-value-row">
                            <span>Hardware</span>
                            <strong>{{ $device['hardware'] ?? 'Unknown' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Location</span>
                            <strong>{{ $device['location'] ?? 'Not advertised' }}</strong>
                        </div>
                    </div>

                    @if (!empty($device['types']))
                        <div class="tag-list">
                            @foreach ($device['types'] as $type)
                                <span class="tag">{{ $type }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if (($device['source'] ?? null) === 'manual-probe')
                        <div class="tag-list">
                            <span class="tag">Direct ONVIF response</span>
                            @if (!empty($device['saved_to_fleet']))
                                <span class="tag">Saved to fleet</span>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>