<div class="screen-grid">
    <section class="screen-card screen-card--accent screen-summary-strip">
        <div class="screen-summary-strip__body">
            <div>
                <span class="eyebrow">Wall builder</span>
                <p class="screen-summary-strip__copy">Create the wall, assign cameras, then set tile shape and span.</p>
            </div>

            <span class="status-pill status-pill--neutral">{{ $summary['assigned_cameras'] }} cameras assigned</span>
        </div>

        <div class="screen-summary-strip__steps" aria-label="Wall builder workflow">
            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">01</span>
                <strong>Create wall</strong>
            </article>

            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">02</span>
                <strong>Assign cameras</strong>
            </article>

            <article class="screen-summary-strip__step">
                <span class="screen-summary-strip__step-number">03</span>
                <strong>Set layout</strong>
            </article>
        </div>
    </section>

    <div class="dashboard-stats">
        <article class="metric-card metric-card--blue">
            <div class="metric-card__icon">WL</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['walls'] }}</p>
                <p class="metric-card__label">Saved walls</p>
                <p class="metric-card__detail">Named layouts that operators can switch between from the live wall.</p>
            </div>
        </article>

        <article class="metric-card metric-card--green">
            <div class="metric-card__icon">AC</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['active_walls'] }}</p>
                <p class="metric-card__label">Active walls</p>
                <p class="metric-card__detail">Walls that are available from the monitoring screen right now.</p>
            </div>
        </article>

        <article class="metric-card metric-card--violet">
            <div class="metric-card__icon">TL</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['tiles'] }}</p>
                <p class="metric-card__label">Configured tiles</p>
                <p class="metric-card__detail">Saved wall positions that will render on the live wall when assigned to a camera.</p>
            </div>
        </article>

        <article class="metric-card metric-card--amber">
            <div class="metric-card__icon">CM</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['assigned_cameras'] }}</p>
                <p class="metric-card__label">Assigned cameras</p>
                <p class="metric-card__detail">Distinct cameras already connected to at least one wall tile.</p>
            </div>
        </article>
    </div>

    <div class="notice-stack" aria-live="polite">
        @if ($statusMessage)
            <div class="notice notice--success">{{ $statusMessage }}</div>
        @endif

        @if ($errorMessage)
            <div class="notice notice--danger">{{ $errorMessage }}</div>
        @endif
    </div>

    <div class="wall-builder-layout">
        <section class="screen-card wall-builder-sidebar">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">Saved walls</h2>
                    <p class="panel-copy">Choose a wall to edit, or start a new one.</p>
                </div>

                <div class="probe-actions">
                    <button class="button button--primary" type="button" wire:click="createWall">New wall</button>
                    @if ($selectedWall)
                        <a class="button button--soft" href="{{ route('live-wall.index', ['wall' => $selectedWall->slug]) }}" wire:navigate>Open selected wall</a>
                    @endif
                </div>
            </div>

            @if ($walls->isEmpty())
                <div class="empty-state empty-state--compact">
                    <strong>No wall layouts exist yet.</strong>
                    <p>Create one to decide which cameras show up on the monitoring wall.</p>
                </div>
            @else
                <div class="wall-record-list">
                    @foreach ($walls as $wall)
                        <button
                            class="wall-record{{ $selectedWall?->id === $wall->id ? ' wall-record--active' : '' }}"
                            type="button"
                            wire:click="selectWall({{ $wall->id }})"
                        >
                            <span class="wall-record__header">
                                <strong>{{ $wall->name }}</strong>
                                <span class="badge-row">
                                    @if ($wall->is_default)
                                        <span class="status-pill status-pill--good">Default</span>
                                    @endif

                                    <span class="status-pill status-pill--{{ $wall->is_active ? 'neutral' : 'warn' }}">{{ $wall->is_active ? 'Active' : 'Hidden' }}</span>
                                </span>
                            </span>
                            <span class="wall-record__meta">
                                <span>{{ $wall->configured_tiles_count }} tile{{ $wall->configured_tiles_count === 1 ? '' : 's' }}</span>
                                <span>{{ $wall->grid_columns }} column{{ $wall->grid_columns === 1 ? '' : 's' }}</span>
                            </span>
                            <span class="wall-record__description">{{ $wall->description ?: 'No wall description saved yet.' }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="screen-card screen-card--spacious wall-builder-editor">
            <div class="panel-heading">
                <div>
                    <h2 class="panel-title">{{ $selectedWall ? 'Edit wall' : 'Create wall' }}</h2>
                    <p class="panel-copy">Configure the wall identity first, then place the camera tiles in the order operators should scan them.</p>
                </div>

                <div class="probe-actions">
                    @if ($selectedWall)
                        <button class="button button--soft" type="button" wire:click="deleteWall({{ $selectedWall->id }})">Delete wall</button>
                    @endif

                    <button class="button button--primary" type="button" wire:click="saveWall">Save wall</button>
                </div>
            </div>

            <section class="form-section">
                <div class="form-section__header">
                    <div>
                        <h3 class="panel-title">Wall details</h3>
                        <p class="panel-copy">The slug is used in the live-wall switcher query string, while columns and default orientation guide the tile grid.</p>
                    </div>
                </div>

                <div class="camera-form-grid">
                    <label class="field-stack">
                        <span>Name</span>
                        <input class="form-input" type="text" wire:model="wallForm.name">
                        @error('wallForm.name')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="field-stack">
                        <span>Slug</span>
                        <input class="form-input" type="text" placeholder="front-desk-wall" wire:model="wallForm.slug">
                        @error('wallForm.slug')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="field-stack field-stack--wide" style="grid-column: 1 / -1;">
                        <span>Description</span>
                        <input class="form-input" type="text" placeholder="Primary monitoring wall for day-shift operators." wire:model="wallForm.description">
                        @error('wallForm.description')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="field-stack">
                        <span>Grid columns</span>
                        <select class="form-select" wire:model.live="wallForm.grid_columns">
                            @foreach ([1, 2, 3, 4, 5, 6] as $columnCount)
                                <option value="{{ $columnCount }}">{{ $columnCount }}</option>
                            @endforeach
                        </select>
                        @error('wallForm.grid_columns')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="field-stack">
                        <span>Default orientation</span>
                        <select class="form-select" wire:model.live="wallForm.default_tile_orientation">
                            @foreach ($orientationOptions as $orientationOption)
                                <option value="{{ $orientationOption }}">{{ ucfirst($orientationOption) }}</option>
                            @endforeach
                        </select>
                        @error('wallForm.default_tile_orientation')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <div class="checkbox-grid">
                    <label class="checkbox-field">
                        <input type="checkbox" wire:model="wallForm.is_default">
                        <span>Use this as the default live wall</span>
                    </label>

                    <label class="checkbox-field">
                        <input type="checkbox" wire:model="wallForm.is_active">
                        <span>Show this wall in the monitoring switcher</span>
                    </label>
                </div>
            </section>

            <section class="form-section">
                <div class="form-section__header">
                    <div>
                        <h3 class="panel-title">Grid builder</h3>
                        <p class="panel-copy">This grid is the wall builder. Drag tiles to reorder them, edit each tile in place, and remove any tile you do not want shown on the live wall.</p>
                    </div>

                    <button class="button button--soft" type="button" wire:click="addTile">Add tile</button>
                </div>

                @php
                    $gridColumns = max(1, (int) ($wallForm['grid_columns'] ?? 1));
                @endphp

                @if ($tileForms === [])
                    <div class="empty-state empty-state--compact">
                        <strong>No tiles are configured yet.</strong>
                        <p>Add a tile, choose a camera, and save the wall before it can appear on the monitoring screen.</p>
                    </div>
                @else
                    <div class="wall-grid-builder-shell">
                        <div class="wall-grid-builder__toolbar">
                            <p class="panel-copy">Drag from the handle to change sequence. Span changes update the layout immediately, and removing a tile takes it off the live wall.</p>
                            <span class="wall-grid-preview__badge">{{ $gridColumns }} columns</span>
                        </div>

                        @error('tileForms.*.camera_id')
                            <small class="field-error">{{ $message }}</small>
                        @enderror

                        <div class="wall-grid-builder" data-wall-tiles-builder data-sortable-list style="--wall-grid-columns: {{ $gridColumns }};">
                        @foreach ($tileForms as $index => $tileForm)
                            @php
                                $selectedCamera = $cameras->firstWhere('id', (int) ($tileForm['camera_id'] ?? 0));
                                $latestPreview = $selectedCamera?->latestRtspPreview();
                                $tileOrientation = $tileForm['orientation'] ?? 'landscape';
                                $tileColumnSpan = max(1, min($gridColumns, (int) ($tileForm['column_span'] ?? 1)));
                                $tileRowSpan = max(1, (int) ($tileForm['row_span'] ?? 1));
                            @endphp
                            <article
                                class="wall-grid-builder__tile wall-grid-builder__tile--{{ $tileOrientation }}"
                                wire:key="tile-{{ $tileForm['client_key'] ?? $index }}"
                                data-tile-draggable
                                data-tile-index="{{ $index }}"
                                style="grid-column: span {{ $tileColumnSpan }}; grid-row: span {{ $tileRowSpan }};"
                            >
                                <div class="wall-grid-builder__tile-frame">
                                    <div class="wall-grid-builder__tile-header">
                                        <div class="wall-grid-builder__tile-title">
                                            <button class="tile-builder-row__drag-handle" type="button" data-drag-handle aria-label="Drag tile {{ $index + 1 }} to reorder">
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                            </button>
                                            <div class="wall-grid-builder__tile-identity">
                                                <span class="wall-grid-builder__tile-number">Tile {{ $index + 1 }}</span>
                                                <strong class="wall-grid-builder__tile-name">{{ $selectedCamera?->name ?? 'Choose a camera feed for this tile.' }}</strong>
                                                <p class="wall-grid-builder__tile-subtitle">
                                                    @if ($selectedCamera)
                                                        {{ $selectedCamera->manufacturer ?: 'Saved camera' }}{{ $selectedCamera->model ? ' · '.$selectedCamera->model : '' }}
                                                    @else
                                                        Unassigned feed
                                                    @endif
                                                </p>
                                            </div>
                                        </div>

                                        <button class="button button--soft" type="button" wire:click="removeTile({{ $index }})">Remove</button>
                                    </div>

                                    <div class="wall-grid-builder__tile-stage">
                                        @if ($latestPreview)
                                            <img
                                                class="wall-grid-builder__tile-image"
                                                src="{{ route('camera-fleet.preview', ['camera' => $selectedCamera, 'profileIndex' => $latestPreview['index'], 'v' => $latestPreview['profile']['preview_generated_at'] ?? '']) }}"
                                                alt="Latest preview for {{ $selectedCamera->name }}"
                                            >
                                        @else
                                            <div class="wall-grid-builder__tile-image wall-grid-builder__tile-image--empty">No preview</div>
                                        @endif

                                        <div class="wall-grid-builder__tile-stage-label">
                                            <strong>{{ $selectedCamera?->name ?? 'Unassigned tile' }}</strong>
                                            <span>{{ $selectedCamera?->local_ip ?? 'Select a camera source to preview this tile.' }}</span>
                                        </div>

                                        <div class="wall-grid-builder__tile-stage-meta">
                                            <span>{{ ucfirst($tileOrientation) }}</span>
                                            <span>{{ $tileColumnSpan }}x{{ $tileRowSpan }}</span>
                                        </div>
                                    </div>

                                    <div class="wall-grid-builder__tile-summary">
                                        <div class="wall-grid-builder__tile-summary-item">
                                            <span>Stream source</span>
                                            <strong>{{ $selectedCamera?->rtspEndpoint() ?? 'No primary RTSP endpoint saved yet.' }}</strong>
                                        </div>

                                        <div class="wall-grid-builder__tile-summary-item">
                                            <span>Camera status</span>
                                            <strong>
                                                @if ($selectedCamera)
                                                    {{ $selectedCamera->is_enabled ? 'Camera enabled and ready for the wall' : 'Camera saved but currently disabled' }}
                                                @else
                                                    Select a camera to attach this tile to a saved feed
                                                @endif
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="wall-grid-builder__tile-controls">
                                        <label class="field-stack field-stack--wide">
                                            <span>Camera source</span>
                                            <select class="form-select" wire:model.live="tileForms.{{ $index }}.camera_id">
                                                <option value="">Select a camera</option>
                                                @foreach ($cameras as $camera)
                                                    <option value="{{ $camera->id }}">{{ $camera->name }}{{ $camera->is_enabled ? '' : ' (disabled)' }}</option>
                                                @endforeach
                                            </select>
                                            @error('tileForms.'.$index.'.camera_id')
                                                <small class="field-error">{{ $message }}</small>
                                            @enderror
                                        </label>

                                        <label class="field-stack">
                                            <span>Orientation</span>
                                            <select class="form-select" wire:model.live="tileForms.{{ $index }}.orientation">
                                                @foreach ($orientationOptions as $orientationOption)
                                                    <option value="{{ $orientationOption }}">{{ ucfirst($orientationOption) }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="field-stack">
                                            <span>Column span</span>
                                            <select class="form-select" wire:model.live="tileForms.{{ $index }}.column_span">
                                                @foreach ($spanOptions as $spanOption)
                                                    <option value="{{ $spanOption }}">{{ $spanOption }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="field-stack">
                                            <span>Row span</span>
                                            <select class="form-select" wire:model.live="tileForms.{{ $index }}.row_span">
                                                @foreach ($spanOptions as $spanOption)
                                                    <option value="{{ $spanOption }}">{{ $spanOption }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                        </div>
                    </div>
                @endif
            </section>
        </section>
    </div>
</div>