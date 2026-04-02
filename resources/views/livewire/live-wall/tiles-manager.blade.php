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
                <p class="metric-card__label">Enabled tiles</p>
                <p class="metric-card__detail">Configured wall positions currently eligible to render a live camera feed.</p>
            </div>
        </article>

        <article class="metric-card metric-card--amber">
            <div class="metric-card__icon">CM</div>
            <div class="metric-card__body">
                <p class="metric-card__value">{{ $summary['assigned_cameras'] }}</p>
                <p class="metric-card__label">Assigned cameras</p>
                <p class="metric-card__detail">Distinct cameras already connected to at least one enabled wall tile.</p>
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
                                <span>{{ $wall->configured_tiles_count }} enabled tile{{ $wall->configured_tiles_count === 1 ? '' : 's' }}</span>
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
                        <select class="form-select" wire:model="wallForm.grid_columns">
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
                        <select class="form-select" wire:model="wallForm.default_tile_orientation">
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
                        <p class="panel-copy">These rows define the live monitor grid. Drag rows to reorder how cameras are packed from top to bottom.</p>
                    </div>

                    <button class="button button--soft" type="button" wire:click="addTile">Add tile</button>
                </div>

                <section class="wall-grid-preview">
                    <div class="wall-grid-preview__header">
                        <div>
                            <h4 class="panel-title">Monitor preview</h4>
                            <p class="panel-copy">This preview mirrors the wall grid style used on Live Wall.</p>
                        </div>
                        <span class="wall-grid-preview__badge">{{ $wallForm['grid_columns'] ?? 0 }} columns</span>
                    </div>

                    @php
                        $previewTiles = collect($tileForms)->filter(fn (array $tile): bool => !empty($tile['camera_id']) && !empty($tile['is_enabled']));
                    @endphp

                    @if ($previewTiles->isEmpty())
                        <div class="empty-state empty-state--compact">
                            <strong>No enabled tiles to preview yet.</strong>
                            <p>Add enabled tile rows below to see how the monitoring grid will pack them.</p>
                        </div>
                    @else
                        <div class="wall-grid-preview__canvas" style="--wall-grid-columns: {{ max(1, (int) ($wallForm['grid_columns'] ?? 1)) }};">
                            @foreach ($previewTiles as $index => $tileForm)
                                @php
                                    $previewCamera = $cameras->firstWhere('id', (int) ($tileForm['camera_id'] ?? 0));
                                    $previewOrientation = $tileForm['orientation'] ?? 'landscape';
                                @endphp
                                <article class="wall-grid-preview__tile wall-grid-preview__tile--{{ $previewOrientation }}" style="grid-column: span {{ max(1, min((int) ($wallForm['grid_columns'] ?? 1), (int) ($tileForm['column_span'] ?? 1))) }}; grid-row: span {{ max(1, (int) ($tileForm['row_span'] ?? 1)) }};">
                                    <span class="wall-grid-preview__tile-label">{{ $previewCamera?->name ?? 'Unassigned camera' }}</span>
                                    <span class="wall-grid-preview__tile-meta">Tile {{ $index + 1 }} · {{ ucfirst($previewOrientation) }} · {{ $tileForm['column_span'] ?? 1 }}x{{ $tileForm['row_span'] ?? 1 }}</span>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                @error('tileForms.*.camera_id')
                    <small class="field-error">{{ $message }}</small>
                @enderror

                @if ($tileForms === [])
                    <div class="empty-state empty-state--compact">
                        <strong>No tiles are configured yet.</strong>
                        <p>Add a tile, choose a camera, and save the wall before it can appear on the monitoring screen.</p>
                    </div>
                @else
                    <div class="tile-builder-list" data-wall-tiles-builder data-sortable-list>
                        @foreach ($tileForms as $index => $tileForm)
                            @php
                                $selectedCamera = $cameras->firstWhere('id', (int) ($tileForm['camera_id'] ?? 0));
                                $latestPreview = $selectedCamera?->latestRtspPreview();
                            @endphp
                            <article class="tile-builder-row" wire:key="tile-row-{{ $index }}" data-tile-draggable data-tile-index="{{ $index }}" draggable="true">
                                <div class="tile-builder-row__header">
                                    <div>
                                        <div class="tile-builder-row__title">
                                            <button class="tile-builder-row__drag-handle" type="button" data-drag-handle aria-label="Drag tile {{ $index + 1 }} to reorder">
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                            </button>
                                            <strong>Tile {{ $index + 1 }}</strong>
                                        </div>
                                        <p>{{ $selectedCamera?->name ?? 'Choose a camera feed for this tile.' }}</p>
                                    </div>

                                    <button class="button button--soft" type="button" wire:click="removeTile({{ $index }})">Remove</button>
                                </div>

                                <div class="tile-builder-row__grid">
                                    <label class="field-stack field-stack--wide">
                                        <span>Camera</span>
                                        <select class="form-select" wire:model="tileForms.{{ $index }}.camera_id">
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
                                        <select class="form-select" wire:model="tileForms.{{ $index }}.orientation">
                                            @foreach ($orientationOptions as $orientationOption)
                                                <option value="{{ $orientationOption }}">{{ ucfirst($orientationOption) }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="field-stack">
                                        <span>Column span</span>
                                        <select class="form-select" wire:model="tileForms.{{ $index }}.column_span">
                                            @foreach ($spanOptions as $spanOption)
                                                <option value="{{ $spanOption }}">{{ $spanOption }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="field-stack">
                                        <span>Row span</span>
                                        <select class="form-select" wire:model="tileForms.{{ $index }}.row_span">
                                            @foreach ($spanOptions as $spanOption)
                                                <option value="{{ $spanOption }}">{{ $spanOption }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="checkbox-field tile-builder-row__toggle">
                                        <input type="checkbox" wire:model="tileForms.{{ $index }}.is_enabled">
                                        <span>Enabled</span>
                                    </label>
                                </div>

                                @if ($selectedCamera)
                                    <div class="tile-builder-preview">
                                        @if ($latestPreview)
                                            <img
                                                class="tile-builder-preview__image"
                                                src="{{ route('camera-fleet.preview', ['camera' => $selectedCamera, 'profileIndex' => $latestPreview['index'], 'v' => $latestPreview['profile']['preview_generated_at'] ?? '']) }}"
                                                alt="Latest preview for {{ $selectedCamera->name }}"
                                            >
                                        @else
                                            <div class="tile-builder-preview__empty">No preview</div>
                                        @endif

                                        <div class="tile-builder-preview__meta">
                                            <strong>{{ $selectedCamera->manufacturer ?: 'Unknown vendor' }}{{ $selectedCamera->model ? ' · '.$selectedCamera->model : '' }}</strong>
                                            <p>{{ $selectedCamera->local_ip }} · {{ $selectedCamera->is_enabled ? 'Enabled camera' : 'Disabled camera' }}</p>
                                            <p>{{ $selectedCamera->rtspEndpoint() ?? 'No primary RTSP endpoint saved yet.' }}</p>
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </section>
    </div>
</div>