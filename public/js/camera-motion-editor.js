(() => {
    if (window.BigBrothaCameraMotionEditorModule) {
        window.BigBrothaCameraMotionEditorModule.bootstrap();

        return;
    }

    const instances = new Map();
    const sharedState = {
        whepPlayerScriptPromise: null,
        whepPlayerScriptUrl: '',
    };

    const loadWhepPlayerScript = (scriptUrl) => {
        if (typeof window.BigBrothaWhepPlayer === 'function') {
            return Promise.resolve();
        }

        if (typeof scriptUrl !== 'string' || scriptUrl.trim() === '') {
            return Promise.resolve();
        }

        const normalizedUrl = new URL(scriptUrl, window.location.href).toString();

        if (sharedState.whepPlayerScriptPromise instanceof Promise && sharedState.whepPlayerScriptUrl === normalizedUrl) {
            return sharedState.whepPlayerScriptPromise;
        }

        const existingScript = Array.from(document.scripts).find((script) => script.src === normalizedUrl);

        sharedState.whepPlayerScriptUrl = normalizedUrl;
        sharedState.whepPlayerScriptPromise = new Promise((resolve, reject) => {
            if (existingScript instanceof HTMLScriptElement) {
                if (existingScript.dataset.loaded === 'true' || typeof window.BigBrothaWhepPlayer === 'function') {
                    existingScript.dataset.loaded = 'true';
                    resolve();

                    return;
                }

                existingScript.addEventListener('load', () => {
                    existingScript.dataset.loaded = 'true';
                    resolve();
                }, { once: true });
                existingScript.addEventListener('error', () => reject(new Error('The shared player script could not be loaded.')), { once: true });

                return;
            }

            const script = document.createElement('script');
            script.src = normalizedUrl;
            script.defer = true;
            script.dataset.bigbrothaWhepPlayerScript = normalizedUrl;
            script.addEventListener('load', () => {
                script.dataset.loaded = 'true';
                resolve();
            }, { once: true });
            script.addEventListener('error', () => reject(new Error('The shared player script could not be loaded.')), { once: true });
            document.head.appendChild(script);
        }).catch((error) => {
            sharedState.whepPlayerScriptPromise = null;
            sharedState.whepPlayerScriptUrl = '';

            throw error;
        });

        return sharedState.whepPlayerScriptPromise;
    };

    class BigBrothaCameraMotionEditor {
        constructor(root) {
            this.root = root;
            this.playerRoot = root.querySelector('[data-role="motion-player"]');
            this.video = root.querySelector('[data-role="video"]');
            this.maskCanvas = root.querySelector('[data-role="mask-canvas"]');
            this.activityCanvas = root.querySelector('[data-role="activity-canvas"]');
            this.maskContext = this.maskCanvas?.getContext('2d');
            this.activityContext = this.activityCanvas?.getContext('2d');
            this.maskPayloadNode = root.querySelector('[data-role="motion-mask-json"]');
            this.statusBadge = root.querySelector('[data-role="motion-status"]');
            this.activityValue = root.querySelector('[data-role="motion-activity-value"]');
            this.triggerPixelsValue = root.querySelector('[data-role="motion-trigger-pixels"]');
            this.pixelsNeededValue = root.querySelector('[data-role="motion-pixels-needed"]');
            this.selectedPixelsValue = root.querySelector('[data-role="motion-selected-pixels"]');
            this.stateValue = root.querySelector('[data-role="motion-state-value"]');
            this.paintButton = root.querySelector('[data-role="paint-button"]');
            this.eraseButton = root.querySelector('[data-role="erase-button"]');
            this.resetButton = root.querySelector('[data-role="reset-button"]');
            this.clearButton = root.querySelector('[data-role="clear-button"]');
            this.brushInput = root.querySelector('[data-role="brush-input"]');
            this.brushValue = root.querySelector('[data-role="brush-value"]');
            this.triggerPixelsInput = null;
            this.triggerPixelsValueLabel = null;
            this.profileInput = null;
            this.saveButton = null;
            this.player = null;
            this.pendingWhepPlayerLoad = null;
            this.playerRestartTimer = null;
            this.playerRestartAttempts = 0;
            this.maxPlayerRestartAttempts = 40;
            this.analysisTimer = null;
            this.analysisAbortController = null;
            this.analysisInFlight = false;
            this.analysisPending = false;
            this.analysisRevision = 0;
            this.analysisStatus = 'waiting';
            this.analysisMessage = 'Waiting for the recorder detector...';
            this.settingsSaved = true;
            this.recordingEventActive = false;
            this.isPainting = false;
            this.isSaving = false;
            this.tool = 'paint';
            this.clusterBonusMultiplier = Math.max(0, Number.parseInt(root.dataset.clusterBonusMultiplier || '2', 10) || 2);
            this.analysisIntervalMs = Math.max(500, Number.parseInt(root.dataset.analysisIntervalMs || '650', 10) || 650);
            this.gridWidth = Math.max(1, Number.parseInt(root.dataset.gridWidth || '160', 10) || 160);
            this.gridHeight = Math.max(1, Number.parseInt(root.dataset.gridHeight || '90', 10) || 90);
            this.totalPixels = this.gridWidth * this.gridHeight;
            this.maskBits = new Uint8Array(this.totalPixels);
            this.changedBits = new Uint8Array(this.totalPixels);
            this.currentChangedPixels = 0;
            this.currentActivityRatio = 0;
            this.isTriggered = false;

            this.handlePointerDown = this.handlePointerDown.bind(this);
            this.handlePointerMove = this.handlePointerMove.bind(this);
            this.handlePointerUp = this.handlePointerUp.bind(this);
            this.handleTriggerPixelsInput = this.handleTriggerPixelsInput.bind(this);
            this.handleProfileChange = this.handleProfileChange.bind(this);
            this.handlePaintModeClick = this.handlePaintModeClick.bind(this);
            this.handleEraseModeClick = this.handleEraseModeClick.bind(this);
            this.handleResetMaskClick = this.handleResetMaskClick.bind(this);
            this.handleClearMaskClick = this.handleClearMaskClick.bind(this);
            this.handleBrushInput = this.handleBrushInput.bind(this);
            this.handleSaveButtonClick = this.handleSaveButtonClick.bind(this);

            this.initializeMask();
            this.refreshComponentBindings();
            this.bindEvents();
            this.refreshToolUi();
            this.refreshTriggerPixelsUi();
            this.refreshMetrics();
            this.renderMask();
            this.renderActivity();
            this.queueAnalysis(0);
        }

        dispose() {
            this.isPainting = false;

            if (this.maskCanvas instanceof HTMLCanvasElement) {
                this.maskCanvas.removeEventListener('pointerdown', this.handlePointerDown);
                this.maskCanvas.removeEventListener('pointermove', this.handlePointerMove);
            }

            document.removeEventListener('pointerup', this.handlePointerUp);
            this.triggerPixelsInput?.removeEventListener('input', this.handleTriggerPixelsInput);
            this.profileInput?.removeEventListener('change', this.handleProfileChange);
            this.paintButton?.removeEventListener('click', this.handlePaintModeClick);
            this.eraseButton?.removeEventListener('click', this.handleEraseModeClick);
            this.resetButton?.removeEventListener('click', this.handleResetMaskClick);
            this.clearButton?.removeEventListener('click', this.handleClearMaskClick);
            this.brushInput?.removeEventListener('input', this.handleBrushInput);
            this.saveButton?.removeEventListener('click', this.handleSaveButtonClick, true);

            if (this.analysisTimer !== null) {
                window.clearTimeout(this.analysisTimer);
                this.analysisTimer = null;
            }

            this.analysisAbortController?.abort();
            this.analysisAbortController = null;

            if (this.playerRestartTimer !== null) {
                window.clearTimeout(this.playerRestartTimer);
                this.playerRestartTimer = null;
            }

            this.pendingWhepPlayerLoad = null;

            this.playerRestartAttempts = 0;

            if (this.player && typeof this.player.close === 'function') {
                this.player.close();
                this.player = null;
            }
        }

        refreshComponentBindings() {
            const nextTriggerPixelsInput = this.findInComponent('[data-role="motion-trigger-pixels-input"]');
            const nextTriggerPixelsValue = this.findInComponent('[data-role="motion-trigger-pixels-value"]');
            const nextProfileInput = this.findInComponent('[data-role="motion-profile-select"]');
            const nextSaveButton = this.findInComponent('[data-role="camera-save-button"]');

            if (this.triggerPixelsInput !== nextTriggerPixelsInput) {
                this.triggerPixelsInput?.removeEventListener('input', this.handleTriggerPixelsInput);
                this.triggerPixelsInput = nextTriggerPixelsInput;
                this.triggerPixelsInput?.addEventListener('input', this.handleTriggerPixelsInput);
            }

            if (this.triggerPixelsValueLabel !== nextTriggerPixelsValue) {
                this.triggerPixelsValueLabel = nextTriggerPixelsValue;
                this.refreshTriggerPixelsUi();
            }

            if (this.profileInput !== nextProfileInput) {
                this.profileInput?.removeEventListener('change', this.handleProfileChange);
                this.profileInput = nextProfileInput;
                this.profileInput?.addEventListener('change', this.handleProfileChange);
            }

            if (this.saveButton !== nextSaveButton) {
                this.saveButton?.removeEventListener('click', this.handleSaveButtonClick, true);
                this.saveButton = nextSaveButton;
                this.saveButton?.addEventListener('click', this.handleSaveButtonClick, true);
            }

            if (this.player === null) {
                this.restartPlayer();
            }
        }

        initializeMask() {
            const payload = this.readMaskPayload();
            const runs = Array.isArray(payload?.runs) ? payload.runs : [[0, this.totalPixels - 1]];

            this.maskBits.fill(0);

            runs.forEach((run) => {
                if (!Array.isArray(run) || run.length < 2) {
                    return;
                }

                const start = Math.max(0, Math.min(this.totalPixels - 1, Number.parseInt(run[0], 10) || 0));
                const end = Math.max(start, Math.min(this.totalPixels - 1, Number.parseInt(run[1], 10) || start));

                for (let index = start; index <= end; index += 1) {
                    this.maskBits[index] = 1;
                }
            });

            if (this.selectedPixels() < 1) {
                this.maskBits.fill(1);
            }
        }

        readMaskPayload() {
            if (!(this.maskPayloadNode instanceof HTMLScriptElement)) {
                return null;
            }

            try {
                return JSON.parse(this.maskPayloadNode.textContent || 'null');
            } catch (error) {
                return null;
            }
        }

        bindEvents() {
            if (this.maskCanvas instanceof HTMLCanvasElement) {
                this.maskCanvas.addEventListener('pointerdown', this.handlePointerDown);
                this.maskCanvas.addEventListener('pointermove', this.handlePointerMove);
            }

            document.addEventListener('pointerup', this.handlePointerUp);
            this.paintButton?.addEventListener('click', this.handlePaintModeClick);
            this.eraseButton?.addEventListener('click', this.handleEraseModeClick);
            this.resetButton?.addEventListener('click', this.handleResetMaskClick);
            this.clearButton?.addEventListener('click', this.handleClearMaskClick);
            this.brushInput?.addEventListener('input', this.handleBrushInput);
        }

        async handleSaveButtonClick(event) {
            if (!(event instanceof MouseEvent)) {
                return;
            }

            const component = this.livewireComponent();

            if (!component || typeof component.call !== 'function') {
                return;
            }

            if (this.isSaving) {
                event.preventDefault();

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            this.isSaving = true;

            if (this.saveButton instanceof HTMLButtonElement) {
                this.saveButton.disabled = true;
            }

            try {
                await component.call('saveCameraFromMotionEditor', this.maskPayload(), this.triggerPixelsThreshold());
            } finally {
                this.isSaving = false;

                if (this.saveButton instanceof HTMLButtonElement) {
                    this.saveButton.disabled = false;
                }
            }
        }

        handlePaintModeClick() {
            this.tool = 'paint';
            this.refreshToolUi();
        }

        handleEraseModeClick() {
            this.tool = 'erase';
            this.refreshToolUi();
        }

        handleResetMaskClick() {
            this.maskBits.fill(1);
            this.markAnalysisDraftChanged();
            this.resetDetectionState();
            this.renderMask();
            this.renderActivity();
            this.refreshMetrics();
            this.queueAnalysis(0);
        }

        handleClearMaskClick() {
            this.maskBits.fill(0);
            this.markAnalysisDraftChanged();
            this.resetDetectionState();
            this.renderMask();
            this.renderActivity();
            this.refreshMetrics();
            this.queueAnalysis(0);
        }

        handleBrushInput() {
            if (this.brushValue instanceof HTMLElement) {
                this.brushValue.textContent = `${this.brushRadius()} px`;
            }
        }

        handleTriggerPixelsInput() {
            this.refreshTriggerPixelsUi();
            this.markAnalysisDraftChanged(150);
        }

        handleProfileChange() {
            this.resetDetectionState();
            this.renderActivity();
            this.refreshMetrics();
            this.restartPlayer();
            this.queueAnalysis(0);
        }

        handlePointerDown(event) {
            if (!(event instanceof PointerEvent) || !(this.maskCanvas instanceof HTMLCanvasElement)) {
                return;
            }

            event.preventDefault();
            this.isPainting = true;
            this.maskCanvas.setPointerCapture?.(event.pointerId);
            this.paintAt(event);
        }

        handlePointerMove(event) {
            if (!(event instanceof PointerEvent) || !this.isPainting) {
                return;
            }

            this.paintAt(event);
        }

        handlePointerUp() {
            if (!this.isPainting) {
                return;
            }

            this.isPainting = false;
            this.queueAnalysis(0);
        }

        paintAt(event) {
            const point = this.gridPointFromEvent(event);

            if (!point) {
                return;
            }

            const radius = this.brushRadius();
            const nextValue = this.tool === 'erase' ? 0 : 1;

            for (let y = point.y - radius; y <= point.y + radius; y += 1) {
                for (let x = point.x - radius; x <= point.x + radius; x += 1) {
                    if (x < 0 || y < 0 || x >= this.gridWidth || y >= this.gridHeight) {
                        continue;
                    }

                    const dx = x - point.x;
                    const dy = y - point.y;

                    if ((dx * dx) + (dy * dy) > radius * radius) {
                        continue;
                    }

                    this.maskBits[(y * this.gridWidth) + x] = nextValue;
                }
            }

            this.renderMask();
            this.refreshMetrics();
            this.markAnalysisDraftChanged(200);
        }

        gridPointFromEvent(event) {
            if (!(this.maskCanvas instanceof HTMLCanvasElement)) {
                return null;
            }

            const rect = this.maskCanvas.getBoundingClientRect();

            if (rect.width <= 0 || rect.height <= 0) {
                return null;
            }

            return {
                x: Math.max(0, Math.min(this.gridWidth - 1, Math.floor(((event.clientX - rect.left) / rect.width) * this.gridWidth))),
                y: Math.max(0, Math.min(this.gridHeight - 1, Math.floor(((event.clientY - rect.top) / rect.height) * this.gridHeight))),
            };
        }

        renderMask() {
            if (!(this.maskCanvas instanceof HTMLCanvasElement) || !(this.maskContext instanceof CanvasRenderingContext2D)) {
                return;
            }

            this.maskCanvas.width = this.gridWidth;
            this.maskCanvas.height = this.gridHeight;
            this.maskContext.imageSmoothingEnabled = false;

            const image = this.maskContext.createImageData(this.gridWidth, this.gridHeight);

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (this.maskBits[index] !== 1) {
                    continue;
                }

                const offset = index * 4;
                image.data[offset] = 41;
                image.data[offset + 1] = 148;
                image.data[offset + 2] = 255;
                image.data[offset + 3] = 78;
            }

            this.maskContext.putImageData(image, 0, 0);
        }

        renderActivity() {
            if (!(this.activityCanvas instanceof HTMLCanvasElement) || !(this.activityContext instanceof CanvasRenderingContext2D)) {
                return;
            }

            this.activityCanvas.width = this.gridWidth;
            this.activityCanvas.height = this.gridHeight;
            this.activityContext.imageSmoothingEnabled = false;
            this.activityContext.clearRect(0, 0, this.gridWidth, this.gridHeight);

            if (this.currentChangedPixels < 1) {
                return;
            }

            this.activityContext.fillStyle = this.isTriggered
                ? 'rgba(255, 88, 88, 0.72)'
                : 'rgba(255, 186, 82, 0.58)';

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (this.changedBits[index] !== 1) {
                    continue;
                }

                const x = index % this.gridWidth;
                const y = Math.floor(index / this.gridWidth);
                this.activityContext.fillRect(x, y, 1, 1);
            }
        }

        refreshToolUi() {
            this.root.dataset.tool = this.tool;

            if (this.paintButton instanceof HTMLButtonElement) {
                this.paintButton.classList.toggle('is-active', this.tool === 'paint');
                this.paintButton.setAttribute('aria-pressed', this.tool === 'paint' ? 'true' : 'false');
            }

            if (this.eraseButton instanceof HTMLButtonElement) {
                this.eraseButton.classList.toggle('is-active', this.tool === 'erase');
                this.eraseButton.setAttribute('aria-pressed', this.tool === 'erase' ? 'true' : 'false');
            }

            this.handleBrushInput();
        }

        refreshTriggerPixelsUi() {
            const selectedPixels = this.selectedPixels();
            const maximum = this.maximumTriggerPixels(selectedPixels);

            if (this.triggerPixelsInput instanceof HTMLInputElement) {
                const nextValue = Math.max(1, Math.min(maximum, Number.parseInt(this.triggerPixelsInput.value || '1', 10) || 1));

                this.triggerPixelsInput.max = String(maximum);
                this.triggerPixelsInput.value = String(nextValue);
            }

            if (this.triggerPixelsValueLabel instanceof HTMLElement) {
                this.triggerPixelsValueLabel.textContent = String(this.triggerPixelsThreshold());
            }
        }

        refreshMetrics() {
            const selectedPixels = this.selectedPixels();
            const pixelsNeeded = this.triggerPixelsNeeded(selectedPixels);

            this.refreshTriggerPixelsUi();

            if (this.activityValue instanceof HTMLElement) {
                this.activityValue.textContent = `${Math.round(this.currentActivityRatio * 100)}%`;
            }

            if (this.triggerPixelsValue instanceof HTMLElement) {
                this.triggerPixelsValue.textContent = String(this.currentChangedPixels);
            }

            if (this.pixelsNeededValue instanceof HTMLElement) {
                this.pixelsNeededValue.textContent = String(pixelsNeeded);
            }

            if (this.selectedPixelsValue instanceof HTMLElement) {
                this.selectedPixelsValue.textContent = String(selectedPixels);
            }

            if (this.stateValue instanceof HTMLElement) {
                if (selectedPixels < 1) {
                    this.stateValue.textContent = 'Mask empty';
                } else if (this.analysisStatus === 'error') {
                    this.stateValue.textContent = 'Unavailable';
                } else if (this.analysisStatus === 'waiting') {
                    this.stateValue.textContent = 'Waiting for buffer';
                } else if (this.isTriggered) {
                    this.stateValue.textContent = this.settingsSaved
                        ? (this.recordingEventActive ? 'Recording event active' : 'Would trigger recorder')
                        : 'Would trigger after save';
                } else if (this.currentChangedPixels > 0) {
                    this.stateValue.textContent = this.settingsSaved ? 'Below threshold' : 'Draft below threshold';
                } else {
                    this.stateValue.textContent = this.settingsSaved ? 'No recorder trigger' : 'Draft has no trigger';
                }
            }

            if (this.statusBadge instanceof HTMLElement) {
                if (selectedPixels < 1) {
                    this.statusBadge.dataset.state = 'empty';
                    this.statusBadge.textContent = 'Mask empty';
                } else if (this.analysisStatus === 'error') {
                    this.statusBadge.dataset.state = 'error';
                    this.statusBadge.textContent = 'Recorder analysis unavailable';
                } else if (this.analysisStatus === 'waiting') {
                    this.statusBadge.dataset.state = 'waiting';
                    this.statusBadge.textContent = 'Waiting for recorder buffer';
                } else if (this.isTriggered) {
                    this.statusBadge.dataset.state = 'triggered';
                    this.statusBadge.textContent = this.settingsSaved
                        ? (this.recordingEventActive ? 'Recording event active' : 'Recorder would trigger')
                        : 'Draft would trigger after save';
                } else if (this.currentChangedPixels > 0) {
                    this.statusBadge.dataset.state = 'active';
                    this.statusBadge.textContent = 'Recorder activity below threshold';
                } else {
                    this.statusBadge.dataset.state = 'watching';
                    this.statusBadge.textContent = 'Recorder sees no trigger';
                }

                this.statusBadge.title = this.analysisMessage;
            }
        }

        restartPlayer() {
            if (!(this.playerRoot instanceof HTMLElement) || this.sessionUrlBase() === '') {
                return;
            }

            if (typeof window.BigBrothaWhepPlayer !== 'function') {
                const scriptUrl = this.whepPlayerScriptUrl();

                if (scriptUrl !== '' && this.pendingWhepPlayerLoad === null) {
                    this.pendingWhepPlayerLoad = loadWhepPlayerScript(scriptUrl)
                        .catch(() => undefined)
                        .finally(() => {
                            this.pendingWhepPlayerLoad = null;

                            if (typeof window.BigBrothaWhepPlayer === 'function' && document.body.contains(this.root)) {
                                this.restartPlayer();
                            }
                        });
                }

                if (this.playerRestartTimer === null && this.playerRestartAttempts < this.maxPlayerRestartAttempts) {
                    this.playerRestartAttempts += 1;
                    this.playerRestartTimer = window.setTimeout(() => {
                        this.playerRestartTimer = null;
                        this.restartPlayer();
                    }, Math.min(1000, 100 * this.playerRestartAttempts));
                }

                return;
            }

            this.playerRestartAttempts = 0;

            if (this.player && typeof this.player.close === 'function') {
                this.player.close();
            }

            this.playerRoot.dataset.sessionUrl = this.sessionUrl();

            try {
                this.player = new window.BigBrothaWhepPlayer(this.playerRoot);
                this.player.start();
            } catch (error) {
                this.player = null;

                if (this.playerRestartTimer === null && this.playerRestartAttempts < this.maxPlayerRestartAttempts) {
                    this.playerRestartAttempts += 1;
                    this.playerRestartTimer = window.setTimeout(() => {
                        this.playerRestartTimer = null;
                        this.restartPlayer();
                    }, Math.min(1000, 100 * this.playerRestartAttempts));
                }
            }
        }

        queueAnalysis(delay = this.analysisIntervalMs) {
            if (this.analysisTimer !== null) {
                window.clearTimeout(this.analysisTimer);
            }

            this.analysisTimer = window.setTimeout(() => {
                this.analysisTimer = null;
                this.requestRecorderAnalysis();
            }, Math.max(0, delay));
        }

        async requestRecorderAnalysis() {
            if (!document.body.contains(this.root) || this.analysisUrl() === '') {
                return;
            }

            if (this.analysisInFlight) {
                this.analysisPending = true;

                return;
            }

            this.analysisInFlight = true;
            this.analysisAbortController = new AbortController();
            const requestedRevision = this.analysisRevision;

            try {
                const response = await fetch(this.analysisUrl(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    body: JSON.stringify({
                        mask: this.maskPayload(),
                        trigger_pixels: this.triggerPixelsThreshold(),
                    }),
                    signal: this.analysisAbortController.signal,
                });
                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload || typeof payload !== 'object') {
                    throw new Error(typeof payload?.message === 'string' ? payload.message : 'Recorder analysis failed.');
                }

                if (requestedRevision === this.analysisRevision) {
                    this.applyRecorderAnalysis(payload);
                } else {
                    this.analysisPending = true;
                }
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.analysisStatus = 'error';
                    this.analysisMessage = error instanceof Error ? error.message : 'Recorder analysis failed.';
                    this.resetDetectionState(false);
                    this.renderActivity();
                    this.refreshMetrics();
                }
            } finally {
                this.analysisInFlight = false;
                this.analysisAbortController = null;

                if (document.body.contains(this.root)) {
                    if (this.analysisPending) {
                        this.analysisPending = false;
                        this.queueAnalysis(0);
                    } else {
                        this.queueAnalysis();
                    }
                }
            }
        }

        markAnalysisDraftChanged(delay = 0) {
            this.analysisRevision += 1;
            this.settingsSaved = false;

            if (this.analysisInFlight) {
                this.analysisPending = true;
            }

            this.queueAnalysis(delay);
        }

        applyRecorderAnalysis(payload) {
            const decision = payload.decision && typeof payload.decision === 'object' ? payload.decision : {};
            const changedIndexes = Array.isArray(decision.changed_indexes) ? decision.changed_indexes : [];

            if (payload.status === 'waiting' && this.analysisStatus === 'ready') {
                this.analysisMessage = typeof payload.message === 'string' ? payload.message : this.analysisMessage;
                this.settingsSaved = payload.settings_saved === true;
                this.recordingEventActive = payload.recording_event_active === true;
                this.refreshMetrics();

                return;
            }

            this.changedBits.fill(0);

            changedIndexes.forEach((value) => {
                const index = Number.parseInt(value, 10);

                if (Number.isInteger(index) && index >= 0 && index < this.totalPixels && this.maskBits[index] === 1) {
                    this.changedBits[index] = 1;
                }
            });

            this.analysisStatus = typeof payload.status === 'string' ? payload.status : 'ready';
            this.analysisMessage = typeof payload.message === 'string' ? payload.message : '';
            this.settingsSaved = payload.settings_saved === true;
            this.recordingEventActive = payload.recording_event_active === true;
            this.currentChangedPixels = Math.max(0, Number.parseInt(decision.effective_trigger_pixels || '0', 10) || 0);
            this.currentActivityRatio = Math.max(0, Math.min(1, Number.parseFloat(decision.activity_ratio || '0') || 0));
            this.isTriggered = decision.detected === true;
            this.renderActivity();
            this.refreshMetrics();
        }

        maskPayload() {
            const runs = [];
            let runStart = null;

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (this.maskBits[index] === 1 && runStart === null) {
                    runStart = index;

                    continue;
                }

                if (this.maskBits[index] !== 1 && runStart !== null) {
                    runs.push([runStart, index - 1]);
                    runStart = null;
                }
            }

            if (runStart !== null) {
                runs.push([runStart, this.totalPixels - 1]);
            }

            return {
                version: 1,
                grid_width: this.gridWidth,
                grid_height: this.gridHeight,
                selected_pixels: this.selectedPixels(),
                runs,
            };
        }

        triggerPixelsThreshold() {
            if (!(this.triggerPixelsInput instanceof HTMLInputElement)) {
                return 1;
            }

            const selectedPixels = this.selectedPixels();
            const maximum = this.maximumTriggerPixels(selectedPixels);

            return Math.max(1, Math.min(maximum, Number.parseInt(this.triggerPixelsInput.value || '1', 10) || 1));
        }

        brushRadius() {
            if (!(this.brushInput instanceof HTMLInputElement)) {
                return 3;
            }

            return Math.max(1, Math.min(12, Number.parseInt(this.brushInput.value || '3', 10) || 3));
        }

        selectedPixels() {
            let count = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                count += this.maskBits[index] === 1 ? 1 : 0;
            }

            return count;
        }

        triggerPixelsNeeded(selectedPixels = this.selectedPixels()) {
            if (selectedPixels < 1) {
                return 0;
            }

            return Math.max(1, Math.min(this.maximumTriggerPixels(selectedPixels), this.triggerPixelsThreshold()));
        }

        maximumTriggerPixels(selectedPixels = this.selectedPixels()) {
            if (selectedPixels < 1) {
                return 1;
            }

            return Math.max(1, selectedPixels + (selectedPixels * this.clusterBonusMultiplier));
        }

        resetDetectionState(resetStatus = true) {
            this.changedBits.fill(0);
            this.currentChangedPixels = 0;
            this.currentActivityRatio = 0;
            this.isTriggered = false;

            if (resetStatus) {
                this.analysisStatus = 'waiting';
                this.analysisMessage = 'Waiting for the recorder detector...';
            }
        }

        analysisUrl() {
            return this.root.dataset.analysisUrl || '';
        }

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        }

        sessionUrlBase() {
            return this.root.dataset.sessionUrlBase || '';
        }

        whepPlayerScriptUrl() {
            return this.root.dataset.whepPlayerScriptUrl || '';
        }

        sessionUrl() {
            const url = new URL(this.sessionUrlBase(), window.location.origin);
            const profileValue = this.profileInput instanceof HTMLSelectElement ? this.profileInput.value : '';

            if (profileValue !== '') {
                url.searchParams.set('profileIndex', profileValue);
            }

            return url.toString();
        }

        livewireComponent() {
            if (!window.Livewire || typeof window.Livewire.find !== 'function') {
                return null;
            }

            const componentRoot = this.root.closest('[wire\\:id]');

            if (!(componentRoot instanceof HTMLElement)) {
                return null;
            }

            const componentId = componentRoot.getAttribute('wire:id');

            return componentId ? window.Livewire.find(componentId) : null;
        }

        findInComponent(selector) {
            const componentRoot = this.root.closest('[wire\\:id]');

            return componentRoot instanceof HTMLElement ? componentRoot.querySelector(selector) : null;
        }
    }

    const syncInstances = () => {
        const roots = Array.from(document.querySelectorAll('[data-motion-editor]'));
        const liveRoots = new Set(roots);

        instances.forEach((instance, root) => {
            if (!liveRoots.has(root)) {
                instance.dispose();
                instances.delete(root);

                return;
            }

            instance.refreshComponentBindings();
        });

        roots.forEach((root) => {
            if (!(root instanceof HTMLElement) || instances.has(root)) {
                return;
            }

            instances.set(root, new BigBrothaCameraMotionEditor(root));
        });
    };

    const initialize = () => {
        syncInstances();

        const observer = new MutationObserver(() => {
            syncInstances();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        document.addEventListener('livewire:navigated', syncInstances);
    };

    window.BigBrothaCameraMotionEditorModule = {
        bootstrap: syncInstances,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });

        return;
    }

    initialize();
})();
