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

        const script = Array.from(document.scripts).find((candidate) => candidate.src === normalizedUrl)
            || document.createElement('script');
        sharedState.whepPlayerScriptUrl = normalizedUrl;
        sharedState.whepPlayerScriptPromise = new Promise((resolve, reject) => {
            const finish = (error) => {
                window.clearTimeout(timeout);
                script.removeEventListener('load', loaded);
                script.removeEventListener('error', failed);
                if (error) {
                    script.remove();
                    reject(error);
                } else {
                    script.dataset.loaded = 'true';
                    resolve();
                }
            };
            const failed = () => finish(new Error('The preview player could not be loaded.'));
            const loaded = () => typeof window.BigBrothaWhepPlayer === 'function' ? finish() : failed();
            const timeout = window.setTimeout(failed, 15000);
            script.addEventListener('load', loaded, { once: true });
            script.addEventListener('error', failed, { once: true });
            if (!script.isConnected) {
                script.src = normalizedUrl;
                document.head.appendChild(script);
            } else if (script.dataset.loaded === 'true') {
                loaded();
            }
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
            this.disposed = false;
            this.lastPaintPoint = null;
            this.activePointerId = null;
            this.lastReadyAt = 0;
            this.lastSampleId = null;
            this.analysisTimeout = null;
            this.analysisFailures = 0;
            this.draftTriggerPixels = null;
            this.analysisNote = root.querySelector('[data-role="motion-analysis-note"]');
            this.sampleAge = root.querySelector('[data-role="motion-sample-age"]');
            this.retryButton = root.querySelector('[data-role="preview-retry"]');
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
            this.clusterBonusMultiplier = Math.max(0, Number.parseInt(root.dataset.clusterBonusMultiplier ?? '2', 10));
            this.analysisIntervalMs = Math.max(250, Number.parseInt(root.dataset.analysisIntervalMs || '650', 10) || 650);
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

            this.handleVisibilityChange = () => {
                if (document.hidden) {
                    window.clearTimeout(this.analysisTimer);
                    this.analysisTimer = null;
                    this.analysisAbortController?.abort();
                } else {
                    this.resetDetectionState();
                    this.renderActivity();
                    this.queueAnalysis(0);
                }
            };
            this.handleRetry = () => {
                this.restartPlayer();
                this.queueAnalysis(0);
            };
            this.resizeOverlay = this.resizeOverlay.bind(this);
            this.initializeMask();
            this.refreshComponentBindings();
            this.bindEvents();
            this.refreshToolUi();
            this.refreshTriggerPixelsUi();
            this.refreshMetrics();
            this.renderMask();
            this.renderActivity();
            this.queueAnalysis(0);
            this.resizeObserver = new ResizeObserver(this.resizeOverlay);
            this.resizeObserver.observe(this.playerRoot);
            this.video?.addEventListener('resize', this.resizeOverlay);
            this.video?.addEventListener('loadedmetadata', this.resizeOverlay);
            this.freshnessTimer = window.setInterval(() => this.refreshFreshness(), 1000);
            this.resizeOverlay();
        }

        dispose() {
            this.disposed = true;
            this.isPainting = false;
            this.resizeObserver?.disconnect();
            this.video?.removeEventListener('resize', this.resizeOverlay);
            this.video?.removeEventListener('loadedmetadata', this.resizeOverlay);
            this.retryButton?.removeEventListener('click', this.handleRetry);
            document.removeEventListener('visibilitychange', this.handleVisibilityChange);
            document.removeEventListener('pointercancel', this.handlePointerUp);
            this.maskCanvas?.removeEventListener('lostpointercapture', this.handlePointerUp);
            window.clearInterval(this.freshnessTimer);
            window.clearTimeout(this.analysisTimeout);

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
                if (this.triggerPixelsInput && this.draftTriggerPixels !== null) {
                    this.triggerPixelsInput.value = String(this.draftTriggerPixels);
                }
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

            if (this.player === null && this.playerRestartTimer === null) {
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
            document.addEventListener('pointercancel', this.handlePointerUp);
            this.maskCanvas?.addEventListener('lostpointercapture', this.handlePointerUp);
            this.retryButton?.addEventListener('click', this.handleRetry);
            document.addEventListener('visibilitychange', this.handleVisibilityChange);
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
                event.stopImmediatePropagation();
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
            } catch (error) {
                this.analysisNote.textContent = 'Saving failed. Your draft is still here; retry Save changes.';
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
            this.refreshMetrics();
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

            if (event.button !== 0 || this.isPainting) return;
            event.preventDefault();
            this.activePointerId = event.pointerId;
            this.lastPaintPoint = null;
            this.isPainting = true;
            this.maskCanvas.setPointerCapture?.(event.pointerId);
            this.paintAt(event);
        }

        handlePointerMove(event) {
            if (!(event instanceof PointerEvent) || !this.isPainting || event.pointerId !== this.activePointerId) {
                return;
            }

            this.paintAt(event);
        }

        handlePointerUp(event) {
            if (event && event.pointerId !== this.activePointerId) return;
            if (!this.isPainting) {
                return;
            }

            this.isPainting = false;
            this.lastPaintPoint = null;
            this.activePointerId = null;
            this.syncDraft();
            this.queueAnalysis(0);
        }

        paintAt(event) {
            const point = this.gridPointFromEvent(event);

            if (!point) {
                return;
            }

            const radius = this.brushRadius();
            const nextValue = this.tool === 'erase' ? 0 : 1;

            const previous = this.lastPaintPoint || point;
            const steps = Math.max(1, Math.ceil(Math.hypot(point.x - previous.x, point.y - previous.y)));
            for (let step = 0; step <= steps; step += 1) {
                const sample = {
                    x: Math.round(previous.x + (point.x - previous.x) * step / steps),
                    y: Math.round(previous.y + (point.y - previous.y) * step / steps),
                };
                for (let y = sample.y - radius; y <= sample.y + radius; y += 1) {
                    for (let x = sample.x - radius; x <= sample.x + radius; x += 1) {
                        if (x < 0 || y < 0 || x >= this.gridWidth || y >= this.gridHeight) continue;
                        const dx = x - sample.x;
                        const dy = y - sample.y;
                        if ((dx * dx) + (dy * dy) <= radius * radius) {
                            this.maskBits[(y * this.gridWidth) + x] = nextValue;
                        }
                    }
                }
            }
            this.lastPaintPoint = point;

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

            if (this.maskCanvas.width !== this.gridWidth) this.maskCanvas.width = this.gridWidth;
            if (this.maskCanvas.height !== this.gridHeight) this.maskCanvas.height = this.gridHeight;
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

            if (this.activityCanvas.width !== this.gridWidth) this.activityCanvas.width = this.gridWidth;
            if (this.activityCanvas.height !== this.gridHeight) this.activityCanvas.height = this.gridHeight;
            this.activityContext.imageSmoothingEnabled = false;
            this.activityContext.clearRect(0, 0, this.gridWidth, this.gridHeight);

            if (this.currentChangedPixels < 1) {
                return;
            }

            const image = this.activityContext.createImageData(this.gridWidth, this.gridHeight);
            for (let index = 0; index < this.totalPixels; index += 1) {
                if (this.changedBits[index] !== 1 || this.maskBits[index] !== 1) continue;
                const offset = index * 4;
                image.data[offset] = 255;
                image.data[offset + 1] = this.isTriggered ? 88 : 186;
                image.data[offset + 2] = this.isTriggered ? 88 : 82;
                image.data[offset + 3] = this.isTriggered ? 184 : 148;
            }
            this.activityContext.putImageData(image, 0, 0);
        }

        resizeOverlay() {
            if (!this.video || !this.playerRoot) return;
            const width = this.playerRoot.clientWidth;
            const height = this.playerRoot.clientHeight;
            const ratio = this.video.videoWidth / this.video.videoHeight;
            const renderedWidth = Number.isFinite(ratio) ? Math.min(width, height * ratio) : width;
            const renderedHeight = Number.isFinite(ratio) ? Math.min(height, width / ratio) : height;
            [this.maskCanvas, this.activityCanvas].forEach(canvas => {
                if (!canvas) return;
                canvas.style.width = `${renderedWidth}px`;
                canvas.style.height = `${renderedHeight}px`;
                canvas.style.left = `${(width - renderedWidth) / 2}px`;
                canvas.style.top = `${(height - renderedHeight) / 2}px`;
            });
        }

        syncDraft() {
            const component = this.livewireComponent();
            component?.$set?.('form.recording_motion_mask', this.maskPayload(), false);
            component?.$set?.('form.recording_motion_trigger_pixels', this.triggerPixelsThreshold(), false);
        }

        refreshFreshness() {
            const age = this.lastReadyAt ? Date.now() - this.lastReadyAt : null;
            if (this.sampleAge) this.sampleAge.textContent = age === null ? 'Waiting for sample' : `${Math.floor(age / 1000)}s since new sample`;
            if (age !== null && age > 3000 && this.analysisStatus === 'ready') {
                this.resetDetectionState();
                this.analysisMessage = 'Waiting for fresh recorder frames. The previous overlay has expired.';
                this.renderActivity();
                this.refreshMetrics();
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
                this.draftTriggerPixels = nextValue;
            }

            if (this.triggerPixelsValueLabel instanceof HTMLElement) {
                this.triggerPixelsValueLabel.textContent = String(this.triggerPixelsThreshold());
            }
        }

        refreshMetrics() {
            const selectedPixels = this.selectedPixels();
            this.refreshTriggerPixelsUi();
            const pixelsNeeded = this.triggerPixelsNeeded(selectedPixels);
            if (this.analysisNote && this.analysisNote.textContent !== this.analysisMessage) this.analysisNote.textContent = this.analysisMessage;
            const limit = this.findInComponent('[data-role="motion-trigger-limit"]');
            if (limit && limit.textContent !== String(this.maximumTriggerPixels())) limit.textContent = String(this.maximumTriggerPixels());

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
                } else if (this.recordingEventActive && this.settingsSaved) {
                    this.stateValue.textContent = 'Recording event active';
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

        async restartPlayer() {
            if (this.disposed || !this.root.isConnected || !this.playerRoot || this.sessionUrlBase() === '' || this.pendingWhepPlayerLoad) return;
            window.clearTimeout(this.playerRestartTimer);
            this.playerRestartTimer = null;
            this.player?.close();
            this.player = null;
            const message = this.playerRoot.querySelector('[data-role="message"]');
            if (message) message.textContent = 'Starting recording preview…';
            try {
                this.pendingWhepPlayerLoad = loadWhepPlayerScript(this.whepPlayerScriptUrl());
                await this.pendingWhepPlayerLoad;
                if (this.disposed || !this.root.isConnected) return;
                this.playerRoot.dataset.sessionUrl = this.sessionUrl();
                this.player = new window.BigBrothaWhepPlayer(this.playerRoot);
                this.player.start();
                this.playerRestartAttempts = 0;
            } catch (error) {
                this.player?.close();
                this.player = null;
                if (this.disposed) return;
                if (message) message.textContent = 'Preview could not start. Retrying automatically…';
                this.playerRestartAttempts += 1;
                this.playerRestartTimer = window.setTimeout(() => this.restartPlayer(), Math.min(10000, 1000 * this.playerRestartAttempts));
            } finally {
                this.pendingWhepPlayerLoad = null;
            }
        }

        queueAnalysis(delay = this.analysisIntervalMs) {
            if (this.disposed || document.hidden) return;
            if (this.analysisTimer !== null) {
                window.clearTimeout(this.analysisTimer);
            }

            this.analysisTimer = window.setTimeout(() => {
                this.analysisTimer = null;
                this.requestRecorderAnalysis();
            }, Math.max(0, delay));
        }

        async requestRecorderAnalysis() {
            if (this.disposed || document.hidden || !document.body.contains(this.root) || this.analysisUrl() === '') {
                return;
            }

            if (this.analysisInFlight) {
                this.analysisPending = true;

                return;
            }

            this.analysisInFlight = true;
            this.analysisAbortController = new AbortController();
            const requestedRevision = this.analysisRevision;
            const startedAt = performance.now();
            let timedOut = false;
            this.analysisTimeout = window.setTimeout(() => {
                timedOut = true;
                this.analysisAbortController?.abort();
            }, 10000);

            try {
                const response = await fetch(this.analysisUrl(), {
                    method: 'POST',
                    cache: 'no-store',
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

                if (this.disposed || document.hidden) return;
                this.analysisFailures = 0;
                if (requestedRevision === this.analysisRevision) {
                    this.applyRecorderAnalysis(payload);
                } else {
                    this.analysisPending = true;
                }
            } catch (error) {
                if (!this.disposed && requestedRevision === this.analysisRevision && (timedOut || error?.name !== 'AbortError')) {
                    this.analysisFailures += 1;
                    this.analysisStatus = 'error';
                    this.analysisMessage = timedOut ? 'Recorder analysis timed out. Retrying…' : (error instanceof Error ? error.message : 'Recorder analysis failed.');
                    this.resetDetectionState(false);
                    this.renderActivity();
                    this.refreshMetrics();
                }
            } finally {
                window.clearTimeout(this.analysisTimeout);
                this.analysisTimeout = null;
                this.analysisInFlight = false;
                this.analysisAbortController = null;

                if (document.body.contains(this.root)) {
                    if (this.analysisPending) {
                        this.analysisPending = false;
                        this.queueAnalysis(0);
                    } else {
                        const interval = this.analysisFailures ? Math.min(10000, 1000 * 2 ** this.analysisFailures) : this.analysisIntervalMs;
                        this.queueAnalysis(Math.max(100, interval - (performance.now() - startedAt)));
                    }
                }
            }
        }

        markAnalysisDraftChanged(delay = 0) {
            this.analysisRevision += 1;
            this.settingsSaved = false;
            this.draftTriggerPixels = this.triggerPixelsThreshold();
            this.resetDetectionState();
            this.renderActivity();
            this.syncDraft();

            if (this.analysisInFlight) {
                this.analysisPending = true;
            }

            this.queueAnalysis(delay);
        }

        applyRecorderAnalysis(payload) {
            const decision = payload.activity || payload.decision || {};
            const changedIndexes = Array.isArray(decision.changed_indexes) ? decision.changed_indexes : [];

            if (payload.status === 'waiting' && this.analysisStatus === 'ready' && Date.now() - this.lastReadyAt <= 1500) {
                this.analysisMessage = typeof payload.message === 'string' ? payload.message : this.analysisMessage;
                this.settingsSaved = payload.settings_saved === true;
                this.recordingEventActive = payload.recording_event_active === true;
                this.refreshMetrics();

                return;
            }

            const sampleId = payload.segment?.sample_id ?? payload.segment?.sampled_at;
            if (payload.status === 'ready' && (sampleId === undefined || sampleId !== this.lastSampleId)) {
                this.lastReadyAt = Date.now();
                this.lastSampleId = sampleId;
            } else if (payload.status === 'ready' && Date.now() - this.lastReadyAt > 3000) {
                this.refreshFreshness();
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

        let syncQueued = false;
        const observer = new MutationObserver((records) => {
            if (syncQueued || records.every(record => record.target instanceof Element && record.target.closest('[data-motion-editor]'))) return;
            syncQueued = true;
            queueMicrotask(() => {
                syncQueued = false;
                syncInstances();
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        document.addEventListener('livewire:navigated', syncInstances);
        const disposeAll = () => {
            instances.forEach(instance => instance.dispose());
            instances.clear();
        };
        document.addEventListener('livewire:navigating', disposeAll);
        window.addEventListener('pagehide', disposeAll);
        window.addEventListener('pageshow', syncInstances);
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
