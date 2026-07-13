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
            this.sampleFrameHandle = null;
            this.lastSampleAt = 0;
            this.isPainting = false;
            this.isSaving = false;
            this.tool = 'paint';
            this.sampleFps = 4;
            this.pixelDeltaThreshold = Math.max(1, Number.parseInt(root.dataset.pixelDeltaThreshold || '18', 10) || 18);
            this.isolatedPixelRadius = Math.max(1, Number.parseInt(root.dataset.isolatedPixelRadius || '1', 10) || 1);
            this.minimumClusterPixels = Math.max(3, Number.parseInt(root.dataset.minimumClusterPixels || '3', 10) || 3);
            this.clusterBonusMinSize = Math.max(3, Number.parseInt(root.dataset.clusterBonusMinSize || '3', 10) || 3);
            this.clusterBonusMultiplier = Math.max(0, Number.parseInt(root.dataset.clusterBonusMultiplier || '2', 10) || 2);
            this.artifactWidespreadActivityRatio = Math.max(0.1, Math.min(1, Number.parseFloat(root.dataset.artifactWidespreadActivityRatio || '0.55') || 0.55));
            this.artifactLuminanceMeanDelta = Math.max(1, Math.min(255, Number.parseFloat(root.dataset.artifactLuminanceMeanDelta || '6') || 6));
            this.artifactLuminanceDirectionRatio = Math.max(0.5, Math.min(1, Number.parseFloat(root.dataset.artifactLuminanceDirectionRatio || '0.9') || 0.9));
            this.artifactLuminanceCoverageRatio = Math.max(0.1, Math.min(1, Number.parseFloat(root.dataset.artifactLuminanceCoverageRatio || '0.5') || 0.5));
            this.artifactLuminancePixelDelta = Math.max(1, Math.min(this.pixelDeltaThreshold, Number.parseInt(root.dataset.artifactLuminancePixelDelta || '4', 10) || 4));
            this.refreshSpikeWindowFrames = Math.max(1, Number.parseInt(root.dataset.refreshSpikeWindowFrames || '2', 10) || 2);
            this.refreshSpikeActivityRatio = Math.max(0.5, Math.min(1, Number.parseFloat(root.dataset.refreshSpikeActivityRatio || '0.85') || 0.85));
            this.gridWidth = Math.max(1, Number.parseInt(root.dataset.gridWidth || '160', 10) || 160);
            this.gridHeight = Math.max(1, Number.parseInt(root.dataset.gridHeight || '90', 10) || 90);
            this.totalPixels = this.gridWidth * this.gridHeight;
            this.offscreenCanvas = document.createElement('canvas');
            this.offscreenCanvas.width = this.gridWidth;
            this.offscreenCanvas.height = this.gridHeight;
            this.offscreenContext = this.offscreenCanvas.getContext('2d', { willReadFrequently: true });
            this.maskBits = new Uint8Array(this.totalPixels);
            this.changedBits = new Uint8Array(this.totalPixels);
            this.frameHistory = [];
            this.previousFrame = null;
            this.currentChangedPixels = 0;
            this.currentRawChangedPixels = 0;
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
            this.startSampling();
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

            if (this.sampleFrameHandle !== null) {
                window.cancelAnimationFrame(this.sampleFrameHandle);
            }

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
            this.resetDetectionState();
            this.renderMask();
            this.renderActivity();
            this.refreshMetrics();
        }

        handleClearMaskClick() {
            this.maskBits.fill(0);
            this.resetDetectionState();
            this.renderMask();
            this.renderActivity();
            this.refreshMetrics();
        }

        handleBrushInput() {
            if (this.brushValue instanceof HTMLElement) {
                this.brushValue.textContent = `${this.brushRadius()} px`;
            }
        }

        handleTriggerPixelsInput() {
            this.refreshTriggerPixelsUi();
        }

        handleProfileChange() {
            this.resetDetectionState();
            this.renderActivity();
            this.refreshMetrics();
            this.restartPlayer();
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
                } else if (this.isTriggered) {
                    this.stateValue.textContent = 'Recording';
                } else if (this.currentChangedPixels > 0) {
                    this.stateValue.textContent = 'Tracking';
                } else {
                    this.stateValue.textContent = 'Watching';
                }
            }

            if (this.statusBadge instanceof HTMLElement) {
                this.statusBadge.dataset.state = selectedPixels < 1
                    ? 'empty'
                    : (this.isTriggered ? 'triggered' : (this.currentChangedPixels > 0 ? 'active' : 'watching'));
                this.statusBadge.textContent = selectedPixels < 1
                    ? 'Mask empty'
                    : (this.isTriggered
                        ? 'Recording trigger active'
                        : (this.currentChangedPixels > 0 ? 'Trigger pixels visible' : 'Armed and watching'));
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

        startSampling() {
            const step = (timestamp) => {
                if (!document.body.contains(this.root)) {
                    return;
                }

                if ((timestamp - this.lastSampleAt) >= (1000 / this.sampleFps)) {
                    this.lastSampleAt = timestamp;
                    this.sampleFrame();
                }

                this.sampleFrameHandle = window.requestAnimationFrame(step);
            };

            this.sampleFrameHandle = window.requestAnimationFrame(step);
        }

        sampleFrame() {
            if (!(this.video instanceof HTMLVideoElement) || !(this.offscreenContext instanceof CanvasRenderingContext2D)) {
                return;
            }

            if (this.video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || this.video.videoWidth < 1 || this.video.videoHeight < 1) {
                return;
            }

            this.offscreenContext.drawImage(this.video, 0, 0, this.gridWidth, this.gridHeight);
            const imageData = this.offscreenContext.getImageData(0, 0, this.gridWidth, this.gridHeight).data;
            const currentFrame = new Uint8Array(this.totalPixels);

            for (let index = 0; index < this.totalPixels; index += 1) {
                const offset = index * 4;
                currentFrame[index] = Math.round(
                    (imageData[offset] * 0.299)
                    + (imageData[offset + 1] * 0.587)
                    + (imageData[offset + 2] * 0.114),
                );
            }

            this.frameHistory.push(currentFrame);

            if (this.frameHistory.length > Math.max(2, this.refreshSpikeWindowFrames + 2)) {
                this.frameHistory.shift();
            }

            if (this.frameHistory.length < 2) {
                this.previousFrame = currentFrame;

                return;
            }

            const transition = this.currentTransition();
            const selectedPixels = this.selectedPixels();

            this.previousFrame = currentFrame;

            if (transition === null) {
                return;
            }

            this.changedBits.fill(0);
            this.changedBits.set(transition.bits);
            this.currentChangedPixels = transition.changedPixels;
            this.currentRawChangedPixels = transition.rawChangedPixels;
            this.currentActivityRatio = selectedPixels > 0 ? Math.min(1, (transition.changedPixels / selectedPixels)) : 0;
            this.isTriggered = selectedPixels > 0 && transition.changedPixels >= this.triggerPixelsNeeded(selectedPixels);
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

        currentTransition() {
            const currentIndex = this.frameHistory.length - 1;

            if (currentIndex < 1) {
                return null;
            }

            const previousFrame = this.frameHistory[currentIndex - 1];
            const currentFrame = this.frameHistory[currentIndex];
            const bits = new Uint8Array(this.totalPixels);
            let changedPixels = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (this.maskBits[index] !== 1) {
                    continue;
                }

                if (Math.abs(currentFrame[index] - previousFrame[index]) >= this.pixelDeltaThreshold) {
                    bits[index] = 1;
                    changedPixels += 1;
                }
            }

            if (changedPixels < 1) {
                return {
                    bits,
                    changedPixels: 0,
                    rawChangedPixels: 0,
                };
            }

            const filteredBits = this.filterIsolatedChangedBits(bits);
            const filteredChangedPixels = this.countChangedBits(filteredBits);

            if (filteredChangedPixels < 1) {
                return {
                    bits: filteredBits,
                    changedPixels: 0,
                    rawChangedPixels: 0,
                };
            }

            if (this.isFrameRefreshArtifact(previousFrame, currentFrame) || this.isIsolatedRefreshSpike(currentIndex)) {
                return {
                    bits: new Uint8Array(this.totalPixels),
                    changedPixels: 0,
                    rawChangedPixels: 0,
                };
            }

            const weightedTriggerPixels = this.weightedTriggerPixels(filteredBits);

            return {
                bits: filteredBits,
                changedPixels: weightedTriggerPixels,
                rawChangedPixels: filteredChangedPixels,
            };
        }

        filterIsolatedChangedBits(bits) {
            if (!(bits instanceof Uint8Array)) {
                return new Uint8Array(this.totalPixels);
            }

            const filteredBits = new Uint8Array(this.totalPixels);
            const visited = new Uint8Array(this.totalPixels);

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (bits[index] !== 1 || visited[index] === 1) {
                    continue;
                }

                const cluster = this.connectedClusterIndexes(bits, index, visited, this.isolatedPixelRadius);

                if (cluster.length < this.minimumClusterPixels) {
                    continue;
                }

                for (const clusterIndex of cluster) {
                    filteredBits[clusterIndex] = 1;
                }
            }

            return filteredBits;
        }

        connectedClusterIndexes(bits, startingIndex, visited, radius = 1) {
            const stack = [startingIndex];
            const cluster = [];
            visited[startingIndex] = 1;

            while (stack.length > 0) {
                const index = stack.pop();

                if (typeof index !== 'number') {
                    continue;
                }

                cluster.push(index);

                const x = index % this.gridWidth;
                const y = Math.floor(index / this.gridWidth);

                for (let neighborY = Math.max(0, y - radius); neighborY <= Math.min(this.gridHeight - 1, y + radius); neighborY += 1) {
                    for (let neighborX = Math.max(0, x - radius); neighborX <= Math.min(this.gridWidth - 1, x + radius); neighborX += 1) {
                        if (neighborX === x && neighborY === y) {
                            continue;
                        }

                        const neighborIndex = (neighborY * this.gridWidth) + neighborX;

                        if (bits[neighborIndex] !== 1 || visited[neighborIndex] === 1) {
                            continue;
                        }

                        visited[neighborIndex] = 1;
                        stack.push(neighborIndex);
                    }
                }
            }

            return cluster;
        }

        countChangedBits(bits) {
            let changedPixels = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                changedPixels += bits[index] === 1 ? 1 : 0;
            }

            return changedPixels;
        }

        weightedTriggerPixels(bits) {
            const visited = new Uint8Array(this.totalPixels);
            let triggerPixels = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (bits[index] !== 1 || visited[index] === 1) {
                    continue;
                }

                const clusterSize = this.connectedClusterSize(bits, index, visited);

                triggerPixels += clusterSize;

                if (clusterSize >= this.clusterBonusMinSize) {
                    triggerPixels += (clusterSize - (this.clusterBonusMinSize - 1)) * this.clusterBonusMultiplier;
                }
            }

            return triggerPixels;
        }

        connectedClusterSize(bits, startingIndex, visited) {
            const stack = [startingIndex];
            visited[startingIndex] = 1;
            let clusterSize = 0;

            while (stack.length > 0) {
                const index = stack.pop();

                if (typeof index !== 'number') {
                    continue;
                }

                clusterSize += 1;

                const x = index % this.gridWidth;
                const y = Math.floor(index / this.gridWidth);

                for (let neighborY = Math.max(0, y - 1); neighborY <= Math.min(this.gridHeight - 1, y + 1); neighborY += 1) {
                    for (let neighborX = Math.max(0, x - 1); neighborX <= Math.min(this.gridWidth - 1, x + 1); neighborX += 1) {
                        if (neighborX === x && neighborY === y) {
                            continue;
                        }

                        const neighborIndex = (neighborY * this.gridWidth) + neighborX;

                        if (bits[neighborIndex] !== 1 || visited[neighborIndex] === 1) {
                            continue;
                        }

                        visited[neighborIndex] = 1;
                        stack.push(neighborIndex);
                    }
                }
            }

            return clusterSize;
        }

        isFrameRefreshArtifact(leftFrame, rightFrame) {
            let changedPixels = 0;
            let luminanceChangedPixels = 0;
            let absoluteDelta = 0;
            let signedDelta = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                const delta = rightFrame[index] - leftFrame[index];
                absoluteDelta += Math.abs(delta);
                signedDelta += delta;

                if (Math.abs(delta) >= this.pixelDeltaThreshold) {
                    changedPixels += 1;
                }

                if (Math.abs(delta) >= this.artifactLuminancePixelDelta) {
                    luminanceChangedPixels += 1;
                }
            }

            if ((changedPixels / this.totalPixels) >= this.artifactWidespreadActivityRatio) {
                return true;
            }

            const meanAbsoluteDelta = absoluteDelta / this.totalPixels;

            if (meanAbsoluteDelta < this.artifactLuminanceMeanDelta
                || (luminanceChangedPixels / this.totalPixels) < this.artifactLuminanceCoverageRatio
                || absoluteDelta < 1) {
                return false;
            }

            return (Math.abs(signedDelta) / absoluteDelta) >= this.artifactLuminanceDirectionRatio;
        }

        isIsolatedRefreshSpike(currentIndex) {
            const previousFrame = this.frameHistory[currentIndex - 1] ?? null;
            const currentFrame = this.frameHistory[currentIndex] ?? null;

            if (!(previousFrame instanceof Uint8Array) || !(currentFrame instanceof Uint8Array)) {
                return false;
            }

            const activityThreshold = this.triggerPixelsNeeded() / Math.max(1, this.selectedPixels());
            const currentTransitionRatio = this.changedPixelsAcrossFrame(previousFrame, currentFrame) / this.totalPixels;

            if (currentTransitionRatio < this.refreshSpikeActivityRatio) {
                return false;
            }

            for (let lookahead = 1; lookahead <= this.refreshSpikeWindowFrames; lookahead += 1) {
                const futureFrame = this.frameHistory[currentIndex + lookahead] ?? null;

                if (!(futureFrame instanceof Uint8Array)) {
                    break;
                }

                const futureChangedRatio = this.changedPixelsAcrossFrame(currentFrame, futureFrame) / this.totalPixels;
                const recoveredRatio = this.changedPixelsAcrossFrame(previousFrame, futureFrame) / this.totalPixels;

                if (futureChangedRatio >= this.refreshSpikeActivityRatio && recoveredRatio < activityThreshold) {
                    return true;
                }
            }

            for (let lookback = 1; lookback <= this.refreshSpikeWindowFrames; lookback += 1) {
                const olderFrame = this.frameHistory[currentIndex - lookback - 1] ?? null;

                if (!(olderFrame instanceof Uint8Array)) {
                    break;
                }

                const pastChangedRatio = this.changedPixelsAcrossFrame(olderFrame, previousFrame) / this.totalPixels;
                const recoveredRatio = this.changedPixelsAcrossFrame(olderFrame, currentFrame) / this.totalPixels;

                if (pastChangedRatio >= this.refreshSpikeActivityRatio && recoveredRatio < activityThreshold) {
                    return true;
                }
            }

            return false;
        }

        changedPixelsAcrossFrame(leftFrame, rightFrame) {
            let changedPixels = 0;

            for (let index = 0; index < this.totalPixels; index += 1) {
                if (Math.abs(leftFrame[index] - rightFrame[index]) >= this.pixelDeltaThreshold) {
                    changedPixels += 1;
                }
            }

            return changedPixels;
        }

        resetDetectionState() {
            this.frameHistory = [];
            this.previousFrame = null;
            this.changedBits.fill(0);
            this.currentChangedPixels = 0;
            this.currentRawChangedPixels = 0;
            this.currentActivityRatio = 0;
            this.isTriggered = false;
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
