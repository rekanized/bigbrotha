(() => {
    if (window.BigBrothasRecordingReviewModule) {
        window.BigBrothasRecordingReviewModule.bootstrap();

        return;
    }

    const state = {
        boundVideo: null,
        boundVideoCleanup: null,
        cleanupFns: [],
        drag: null,
        lastDragEndedAt: 0,
        lastPointerEventAt: 0,
        observer: null,
        observerPauseDepth: 0,
        observerScope: null,
        scrubPreviewRequestId: 0,
        scrubSpriteCache: new Map(),
        scrubSpritePending: new Map(),
        stageMuted: null,
        stagePaused: null,
        stageVolume: 1,
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const clampVolume = (value) => clamp(Number(value) || 0, 0, 1);

    const formatFocusLabel = (focusMs) => {
        const date = new Date(Number(focusMs));

        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')} ${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')}:${String(date.getUTCSeconds()).padStart(2, '0')} UTC`;
    };

    const root = () => document.querySelector('[data-recording-review-root]');
    const rootFor = (element) => element instanceof Element ? element.closest('[data-recording-review-root]') : null;
    const railViewport = (scope) => scope?.querySelector('[data-role="rail-viewport"]') || null;
    const railTrack = (scope) => scope?.querySelector('[data-role="rail-track"]') || null;
    const stageRoot = (scope) => scope?.querySelector('[data-role="timeline-stage"]') || null;
    const stageVideo = (scope) => scope?.querySelector('[data-role="video"]') || null;
    const readNumber = (element, key, fallback = 0) => {
        if (!(element instanceof HTMLElement)) {
            return fallback;
        }

        const value = Number(element.dataset[key] || fallback);

        return Number.isFinite(value) ? value : fallback;
    };

    const timelineStartMs = (scope) => readNumber(scope, 'dayStartMs', 0);
    const timelineEndMs = (scope) => readNumber(scope, 'dayEndMs', timelineStartMs(scope) + 1000);
    const timelineDurationMs = (scope) => Math.max(1, timelineEndMs(scope) - timelineStartMs(scope));
    const timelineMaximumFocusMs = (scope) => Math.max(timelineStartMs(scope), timelineEndMs(scope) - 1000);
    const currentFocusMs = (scope) => clamp(readNumber(scope, 'focusMs', timelineStartMs(scope)), timelineStartMs(scope), timelineMaximumFocusMs(scope));

    const setText = (element, value) => {
        if (element instanceof HTMLElement) {
            element.textContent = value;
        }
    };

    const updateStageVolumeUi = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const volumePercent = Math.round(state.stageVolume * 100);

        scope.querySelectorAll('[data-role="audio-volume-slider"]').forEach((element) => {
            if (element instanceof HTMLInputElement) {
                element.value = String(volumePercent);
            }
        });

        scope.querySelectorAll('[data-role="audio-volume-value"]').forEach((element) => {
            setText(element, `${volumePercent}%`);
        });
    };

    const serverStageMuted = (scope) => {
        const stage = stageRoot(scope);

        return !(stage instanceof HTMLElement) || stage.dataset.audioState !== 'active';
    };

    const serverStagePaused = (scope) => {
        const stage = stageRoot(scope);

        return stage instanceof HTMLElement && stage.dataset.playbackState === 'paused';
    };

    const stageAudioState = (scope) => ((state.stageMuted ?? serverStageMuted(scope)) ? 'muted' : 'active');

    const syncStagePlaybackUi = (scope, isPaused) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const stage = stageRoot(scope);
        const playbackToggle = scope.querySelector('[data-role="playback-toggle"]');
        const playbackIndicator = scope.querySelector('[data-role="playback-indicator"]');
        const cameraLabel = scope.querySelector('[data-role="camera-label"]');
        const cameraName = cameraLabel instanceof HTMLElement && cameraLabel.textContent
            ? cameraLabel.textContent.trim()
            : 'camera';

        if (stage instanceof HTMLElement) {
            stage.dataset.playbackState = isPaused ? 'paused' : 'playing';
        }

        if (playbackToggle instanceof HTMLButtonElement) {
            playbackToggle.setAttribute('aria-pressed', isPaused ? 'false' : 'true');
            playbackToggle.setAttribute('aria-label', isPaused ? `Play ${cameraName}` : `Pause ${cameraName}`);
        }

        setText(playbackIndicator, isPaused ? 'Paused' : 'Playing');
    };

    const syncStageAudioUi = (scope, isMuted) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const stage = stageRoot(scope);
        const audioToggle = scope.querySelector('[data-role="audio-toggle"]');
        const audioIndicator = scope.querySelector('[data-role="audio-indicator"]');
        const cameraLabel = scope.querySelector('[data-role="camera-label"]');
        const cameraName = cameraLabel instanceof HTMLElement && cameraLabel.textContent
            ? cameraLabel.textContent.trim()
            : 'camera';

        if (stage instanceof HTMLElement) {
            stage.dataset.audioState = isMuted ? 'muted' : 'active';
        }

        if (audioToggle instanceof HTMLButtonElement) {
            audioToggle.setAttribute('aria-pressed', isMuted ? 'false' : 'true');
            audioToggle.setAttribute('aria-label', isMuted ? `Listen to ${cameraName}` : `Mute ${cameraName}`);
        }

        setText(audioIndicator, isMuted ? 'Muted' : 'Audio selected');
    };

    const applyStageAudioState = (video, isMuted, shouldPlay = false) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        video.defaultMuted = isMuted;
        video.muted = isMuted;
        video.volume = isMuted ? 0 : state.stageVolume;

        if (shouldPlay && !isMuted) {
            playVideo(video);
        }
    };

    const syncStagePlaybackState = (scope, video = stageVideo(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        if (state.stagePaused === null) {
            state.stagePaused = serverStagePaused(scope);
        }

        syncStagePlaybackUi(scope, state.stagePaused);

        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        if (state.stagePaused) {
            video.pause();

            return;
        }

        playVideo(video);
    };

    const syncStageAudioState = (scope, video = stageVideo(scope), shouldPlay = false) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        if (state.stageMuted === null) {
            state.stageMuted = serverStageMuted(scope);
        }

        syncStageAudioUi(scope, state.stageMuted);

        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        applyStageAudioState(video, state.stageMuted, shouldPlay);
    };

    const toggleStagePlayback = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.stagePaused = !(state.stagePaused ?? serverStagePaused(scope));
        syncStagePlaybackState(scope, stageVideo(scope));
    };

    const toggleStageAudio = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.stageMuted = !(state.stageMuted ?? serverStageMuted(scope));
        syncStageAudioState(scope, stageVideo(scope), !state.stageMuted && !(state.stagePaused ?? serverStagePaused(scope)));
    };

    const playVideo = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        const playPromise = video.play();

        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {
            });
        }
    };

    const railTrackHeight = (scope) => {
        const track = railTrack(scope);

        if (!(track instanceof HTMLElement)) {
            return 1;
        }

        return Math.max(track.scrollHeight, track.offsetHeight, 1);
    };

    const updateVisibleRange = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            return;
        }

        const trackHeight = railTrackHeight(scope);
        const ratioStart = clamp(viewport.scrollTop / trackHeight, 0, 1);
        const ratioEnd = clamp((viewport.scrollTop + viewport.clientHeight) / trackHeight, 0, 1);
        const visibleStartMs = timelineStartMs(scope) + (timelineDurationMs(scope) * ratioStart);
        const visibleEndMs = timelineStartMs(scope) + (timelineDurationMs(scope) * ratioEnd);
        const label = `${formatFocusLabel(visibleStartMs)} - ${formatFocusLabel(visibleEndMs)}`;

        scope.querySelectorAll('[data-role="visible-range-label"]').forEach((element) => {
            setText(element, label);
        });
    };

    const updateFocusCursor = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const topPercent = clamp(((Number(focusMs) - timelineStartMs(scope)) / timelineDurationMs(scope)) * 100, 0, 100);

        scope.querySelectorAll('[data-role="focus-cursor"]').forEach((element) => {
            if (element instanceof HTMLElement) {
                element.style.top = `${topPercent}%`;
            }
        });
    };

    const updateFocusLabels = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const label = formatFocusLabel(focusMs);

        scope.querySelectorAll('[data-role="focus-label"], [data-role="focus-label-rail"]').forEach((element) => {
            setText(element, label);
        });

        const scrubPreviewTime = scope.querySelector('[data-role="scrub-preview-time"]');
        setText(scrubPreviewTime, label);
    };

    const segmentExactMatch = (segment, focusMs) => {
        const startMs = readNumber(segment, 'startMs', 0);
        const endMs = readNumber(segment, 'endMs', startMs);

        return startMs <= focusMs && endMs >= focusMs;
    };

    const segmentRenderedMatch = (scope, segment, focusMs) => {
        const startMs = readNumber(segment, 'startMs', 0);
        const endMs = readNumber(segment, 'endMs', startMs);
        const renderTopPercent = Number(segment.dataset.topPercent || 0);
        const renderHeightPercent = Number(segment.dataset.renderHeightPercent || 0);
        const renderedEndMs = timelineStartMs(scope) + ((renderTopPercent + renderHeightPercent) / 100) * timelineDurationMs(scope);

        return startMs <= focusMs && Math.max(endMs, renderedEndMs) >= focusMs;
    };

    const segmentDistance = (segment, focusMs) => {
        const startMs = readNumber(segment, 'startMs', 0);
        const endMs = readNumber(segment, 'endMs', startMs);

        if (startMs <= focusMs && endMs >= focusMs) {
            return 0;
        }

        if (focusMs < startMs) {
            return startMs - focusMs;
        }

        return focusMs - endMs;
    };

    const segmentForFocus = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        const segments = Array.from(scope.querySelectorAll('[data-role="rail-segment"]'))
            .filter((element) => element instanceof HTMLElement);

        const exact = segments.find((segment) => segmentExactMatch(segment, focusMs));

        if (exact) {
            return exact;
        }

        const renderedMatches = segments
            .filter((segment) => segmentRenderedMatch(scope, segment, focusMs))
            .sort((left, right) => segmentDistance(left, focusMs) - segmentDistance(right, focusMs));

        if (renderedMatches[0]) {
            return renderedMatches[0];
        }

        const nearestSegments = [...segments].sort((left, right) => segmentDistance(left, focusMs) - segmentDistance(right, focusMs));

        return nearestSegments[0] || null;
    };

    const scrubPreviewLayers = (frame) => {
        if (!(frame instanceof HTMLElement)) {
            return [];
        }

        return Array.from(frame.querySelectorAll('[data-role="scrub-preview-layer"]'))
            .filter((element) => element instanceof HTMLElement);
    };

    const activeScrubPreviewLayerIndex = (frame) => {
        if (!(frame instanceof HTMLElement)) {
            return 0;
        }

        return Number(frame.dataset.activeLayerIndex || 0) === 1 ? 1 : 0;
    };

    const setActiveScrubPreviewLayer = (frame, nextIndex) => {
        if (!(frame instanceof HTMLElement)) {
            return;
        }

        scrubPreviewLayers(frame).forEach((layer, index) => {
            if (layer instanceof HTMLElement) {
                layer.classList.toggle('is-active', index === nextIndex);
            }
        });

        frame.dataset.activeLayerIndex = String(nextIndex);
    };

    const applyScrubPreviewLayerFrame = (layer, spriteUrl, columns, rows, frameWidth, frameHeight, offsetX, offsetY, frameKey) => {
        if (!(layer instanceof HTMLElement)) {
            return;
        }

        layer.style.backgroundImage = `url("${spriteUrl}")`;
        layer.style.backgroundSize = `${columns * frameWidth}px ${rows * frameHeight}px`;
        layer.style.backgroundPosition = `-${offsetX}px -${offsetY}px`;
        layer.dataset.spriteUrl = spriteUrl;
        layer.dataset.frameKey = frameKey;
    };

    const preloadScrubSprite = (spriteUrl) => {
        if (spriteUrl === '') {
            return Promise.resolve(false);
        }

        if (state.scrubSpriteCache.has(spriteUrl)) {
            return Promise.resolve(true);
        }

        const pendingLoad = state.scrubSpritePending.get(spriteUrl);

        if (pendingLoad) {
            return pendingLoad;
        }

        const preload = new Promise((resolve) => {
            const image = new Image();

            image.decoding = 'async';
            image.onload = () => {
                state.scrubSpriteCache.set(spriteUrl, true);
                state.scrubSpritePending.delete(spriteUrl);
                resolve(true);
            };
            image.onerror = () => {
                state.scrubSpritePending.delete(spriteUrl);
                resolve(false);
            };
            image.src = spriteUrl;
        });

        state.scrubSpritePending.set(spriteUrl, preload);

        return preload;
    };

    const warmScrubSprites = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const spriteUrls = [...new Set(railSegments(scope)
            .map((segment) => segment.dataset.scrubSpriteUrl || '')
            .filter((spriteUrl) => spriteUrl !== ''))];

        window.setTimeout(() => {
            spriteUrls.forEach((spriteUrl) => {
                void preloadScrubSprite(spriteUrl);
            });
        }, 0);
    };

    const hideScrubPreview = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const preview = scope.querySelector('[data-role="scrub-preview"]');

        if (preview instanceof HTMLElement) {
            preview.setAttribute('hidden', 'hidden');
            delete preview.dataset.requestId;
        }
    };

    const withObserverPaused = (callback) => {
        const observedScope = state.observerScope;

        if (!(observedScope instanceof HTMLElement) || !(state.observer instanceof MutationObserver)) {
            callback();

            return;
        }

        if (state.observerPauseDepth > 0) {
            state.observerPauseDepth += 1;

            try {
                callback();
            } finally {
                state.observerPauseDepth = Math.max(0, state.observerPauseDepth - 1);
            }

            return;
        }

        state.observer.disconnect();
        state.observerPauseDepth = 1;

        try {
            callback();
        } finally {
            state.observerPauseDepth = 0;
            state.observer.observe(observedScope, {
                attributes: true,
                attributeFilter: ['data-audio-state'],
                childList: true,
                subtree: true,
            });
        }
    };

    const refreshScope = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        withObserverPaused(() => {
            bindStageVideo(scope);
            syncStagePlaybackState(scope);
            syncStageAudioState(scope);
            updateStageVolumeUi(scope);
            updateVisibleRange(scope);
            updateFocusCursor(scope, currentFocusMs(scope));
            hideScrubPreview(scope);
            warmScrubSprites(scope);
        });
    };

    const showScrubPreview = (scope, segment, focusMs) => {
        if (!(scope instanceof HTMLElement) || !(segment instanceof HTMLElement)) {
            hideScrubPreview(scope);

            return;
        }

        const preview = scope.querySelector('[data-role="scrub-preview"]');
        const frame = scope.querySelector('[data-role="scrub-preview-frame"]');
        const layers = scrubPreviewLayers(frame);
        const camera = scope.querySelector('[data-role="scrub-preview-camera"]');
        const viewer = scope.querySelector('.recording-review-focus__viewer');
        const spriteUrl = segment.dataset.scrubSpriteUrl || '';
        const frameCount = readNumber(segment, 'scrubFrameCount', 0);
        const frameWidth = readNumber(segment, 'scrubFrameWidth', 0);
        const frameHeight = readNumber(segment, 'scrubFrameHeight', 0);
        const columns = Math.max(1, readNumber(segment, 'scrubColumns', 1));
        const rows = Math.max(1, readNumber(segment, 'scrubRows', 1));
        const frameIntervalMs = Math.max(1, readNumber(segment, 'scrubFrameIntervalMs', 0));

        if (!(preview instanceof HTMLElement) || !(frame instanceof HTMLElement) || layers.length === 0 || spriteUrl === '' || frameCount < 1 || frameWidth < 1 || frameHeight < 1) {
            hideScrubPreview(scope);

            return;
        }

        const startMs = readNumber(segment, 'startMs', 0);
        const endMs = Math.max(startMs, readNumber(segment, 'endMs', startMs));
        const offsetMs = clamp(focusMs - startMs, 0, Math.max(0, endMs - startMs));
        const frameIndex = Math.min(frameCount - 1, Math.max(0, Math.round(offsetMs / frameIntervalMs)));
        const offsetX = (frameIndex % columns) * frameWidth;
        const offsetY = Math.floor(frameIndex / columns) * frameHeight;

        const frameKey = `${spriteUrl}:${frameIndex}`;

        state.scrubPreviewRequestId += 1;

        const requestId = String(state.scrubPreviewRequestId);
        const activeLayerIndex = activeScrubPreviewLayerIndex(frame);
        const activeLayer = layers[activeLayerIndex] || layers[0] || null;
        const hasActiveSprite = activeLayer instanceof HTMLElement && (activeLayer.dataset.spriteUrl || '') !== '';
        const activeSpriteUrl = activeLayer instanceof HTMLElement ? (activeLayer.dataset.spriteUrl || '') : '';

        frame.style.width = `${frameWidth}px`;
        frame.style.height = `${frameHeight}px`;
        frame.style.aspectRatio = `${frameWidth} / ${frameHeight}`;
        preview.dataset.requestId = requestId;

        const renderLoadedSprite = () => {
            const latestPreview = scope.querySelector('[data-role="scrub-preview"]');
            const latestFrame = scope.querySelector('[data-role="scrub-preview-frame"]');

            if (!(latestPreview instanceof HTMLElement) || !(latestFrame instanceof HTMLElement) || latestPreview.dataset.requestId !== requestId) {
                return;
            }

            const latestLayers = scrubPreviewLayers(latestFrame);
            const latestActiveIndex = activeScrubPreviewLayerIndex(latestFrame);
            const latestActiveLayer = latestLayers[latestActiveIndex] || latestLayers[0] || null;

            if (!(latestActiveLayer instanceof HTMLElement)) {
                return;
            }

            if ((latestActiveLayer.dataset.spriteUrl || '') === spriteUrl) {
                applyScrubPreviewLayerFrame(latestActiveLayer, spriteUrl, columns, rows, frameWidth, frameHeight, offsetX, offsetY, frameKey);
                latestPreview.removeAttribute('hidden');

                return;
            }

            const nextLayerIndex = latestLayers.length > 1
                ? (latestActiveIndex === 0 ? 1 : 0)
                : latestActiveIndex;
            const nextLayer = latestLayers[nextLayerIndex] || latestActiveLayer;

            applyScrubPreviewLayerFrame(nextLayer, spriteUrl, columns, rows, frameWidth, frameHeight, offsetX, offsetY, frameKey);
            setActiveScrubPreviewLayer(latestFrame, latestLayers.indexOf(nextLayer));
            latestPreview.removeAttribute('hidden');
        };

        if (activeSpriteUrl === spriteUrl) {
            applyScrubPreviewLayerFrame(activeLayer, spriteUrl, columns, rows, frameWidth, frameHeight, offsetX, offsetY, frameKey);
            preview.removeAttribute('hidden');
        } else if (state.scrubSpriteCache.has(spriteUrl)) {
            renderLoadedSprite();
        } else {
            if (hasActiveSprite) {
                preview.removeAttribute('hidden');
            }

            void preloadScrubSprite(spriteUrl).then((loaded) => {
                if (!loaded) {
                    return;
                }

                renderLoadedSprite();
            });
        }

        if (viewer instanceof HTMLElement) {
            const focusRatio = clamp((focusMs - timelineStartMs(scope)) / timelineDurationMs(scope), 0, 1);
            const previewHeight = Math.max(preview.offsetHeight || 0, frameHeight + 64);
            const nextTop = clamp((viewer.clientHeight * focusRatio) - (previewHeight / 2), 18, Math.max(18, viewer.clientHeight - previewHeight - 18));

            preview.style.top = `${nextTop}px`;
        }

        preview.style.left = 'auto';
        preview.style.right = '18px';
        setText(camera, segment.dataset.cameraName || 'Camera');
        updateFocusLabels(scope, focusMs);
    };

    const shouldIgnoreMouseEvent = (event) => event instanceof MouseEvent
        && !(event instanceof PointerEvent)
        && (Date.now() - state.lastPointerEventAt) < 120;

    const updateLocalFocus = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        scope.dataset.focusMs = String(Math.round(focusMs));

        updateFocusCursor(scope, focusMs);
        updateFocusLabels(scope, focusMs);
    };

    const focusFromPointer = (scope, clientY) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            return null;
        }

        const rect = viewport.getBoundingClientRect();
        const offsetY = clamp(clientY - rect.top + viewport.scrollTop, 0, railTrackHeight(scope));
        const ratio = clamp(offsetY / railTrackHeight(scope), 0, 1);

        return timelineStartMs(scope) + (timelineDurationMs(scope) * ratio);
    };

    const centerViewportOnFocus = (scope, focusMs, anchorOffsetY = null) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            return;
        }

        const offset = anchorOffsetY === null ? viewport.clientHeight / 2 : Number(anchorOffsetY);
        const nextScrollTop = ((focusMs - timelineStartMs(scope)) / timelineDurationMs(scope)) * railTrackHeight(scope) - offset;

        viewport.scrollTop = Math.max(0, nextScrollTop);
        updateVisibleRange(scope);
    };

    const livewireComponent = (scope) => {
        if (!(scope instanceof HTMLElement) || !window.Livewire || typeof window.Livewire.find !== 'function') {
            return null;
        }

        const componentRoot = scope.closest('[wire\\:id]');

        if (!(componentRoot instanceof HTMLElement)) {
            return null;
        }

        const componentId = componentRoot.getAttribute('wire:id');

        return componentId ? window.Livewire.find(componentId) : null;
    };

    const railSegments = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return [];
        }

        return Array.from(scope.querySelectorAll('[data-role="rail-segment"]'))
            .filter((element) => element instanceof HTMLElement)
            .sort((left, right) => readNumber(left, 'startMs', 0) - readNumber(right, 'startMs', 0));
    };

    const nextSegmentForVideo = (scope, video) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return null;
        }

        const segments = railSegments(scope);

        if (segments.length < 2) {
            return null;
        }

        const currentRecordingId = String(video.dataset.recordingId || '');
        const currentIndex = segments.findIndex((segment) => String(segment.dataset.recordingId || '') === currentRecordingId);

        if (currentIndex >= 0) {
            return segments[currentIndex + 1] || null;
        }

        const currentEndMs = readNumber(video, 'endMs', 0);

        return segments.find((segment) => readNumber(segment, 'startMs', 0) > currentEndMs) || null;
    };

    const commitFocus = (scope, focusMs) => {
        const component = livewireComponent(scope);

        if (component && typeof component.call === 'function') {
            component.call('selectFocus', Math.round(focusMs));
        }
    };

    const cleanupVideoBinding = () => {
        if (typeof state.boundVideoCleanup === 'function') {
            state.boundVideoCleanup();
        }

        state.boundVideo = null;
        state.boundVideoCleanup = null;
    };

    const bindStageVideo = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            cleanupVideoBinding();

            return;
        }

        const video = stageVideo(scope);

        if (!(video instanceof HTMLVideoElement)) {
            cleanupVideoBinding();

            return;
        }

        if (state.boundVideo === video) {
            syncStagePlaybackState(scope, video);
            syncStageAudioState(scope, video);

            return;
        }

        cleanupVideoBinding();

        const seekToFocus = () => {
            const focusMs = currentFocusMs(scope);
            const startMs = readNumber(video, 'startMs', 0);
            const durationSeconds = Math.max(0, Number(video.dataset.durationSeconds || 0));
            const seekSeconds = clamp((focusMs - startMs) / 1000, 0, Math.max(0, durationSeconds - 0.2));

            try {
                if (Math.abs(Number(video.currentTime || 0) - seekSeconds) > 0.35) {
                    video.currentTime = seekSeconds;
                }
            } catch (error) {
            }

            syncStageAudioState(scope, video);
            syncStagePlaybackState(scope, video);
        };

        const handleError = () => {
            const fallbackUrl = video.dataset.fallbackStreamUrl || '';

            if (fallbackUrl === '' || video.currentSrc === fallbackUrl) {
                return;
            }

            const resumeAt = Number(video.currentTime || 0);
            video.src = fallbackUrl;
            video.load();

            const handleFallbackLoaded = () => {
                video.removeEventListener('loadedmetadata', handleFallbackLoaded);

                try {
                    video.currentTime = resumeAt;
                } catch (error) {
                }

                syncStageAudioState(scope, video);
                syncStagePlaybackState(scope, video);
            };

            video.addEventListener('loadedmetadata', handleFallbackLoaded);

            const assetStatus = scope.querySelector('[data-role="asset-status"]');
            setText(assetStatus, 'Fallback stream');
        };

        const handleEnded = () => {
            const nextSegment = nextSegmentForVideo(scope, video);

            if (!(nextSegment instanceof HTMLElement)) {
                return;
            }

            const nextFocus = readNumber(nextSegment, 'focusMs', readNumber(nextSegment, 'startMs', currentFocusMs(scope)));

            hideScrubPreview(scope);
            updateLocalFocus(scope, nextFocus);
            centerViewportOnFocus(scope, nextFocus);
            commitFocus(scope, nextFocus);
        };

        const handlePlay = () => {
            state.stagePaused = false;
            syncStagePlaybackUi(scope, false);
        };

        const handlePause = () => {
            if (!video.ended) {
                state.stagePaused = true;
            }

            syncStagePlaybackUi(scope, true);
        };

        if (video.readyState >= 1) {
            seekToFocus();
        } else {
            video.addEventListener('loadedmetadata', seekToFocus, { once: true });
        }

        video.addEventListener('error', handleError);
        video.addEventListener('ended', handleEnded);
        video.addEventListener('play', handlePlay);
        video.addEventListener('pause', handlePause);

        state.boundVideo = video;
        state.boundVideoCleanup = () => {
            video.removeEventListener('error', handleError);
            video.removeEventListener('ended', handleEnded);
            video.removeEventListener('play', handlePlay);
            video.removeEventListener('pause', handlePause);
        };
    };

    const handlePointerDown = (event) => {
        if ('button' in event && event.button !== 0) {
            return;
        }

        if (shouldIgnoreMouseEvent(event)) {
            return;
        }

        if (event instanceof PointerEvent) {
            state.lastPointerEventAt = Date.now();
        }

        const scope = rootFor(event.target);
        const viewport = event.target instanceof Element ? event.target.closest('[data-role="rail-viewport"]') : null;
        const scrubbable = event.target instanceof Element
            ? event.target.closest('[data-role="rail-viewport"], [data-role="rail-track"], [data-role="rail-segments"], [data-role="rail-segment"], [data-role="rail-tick"], [data-role="focus-cursor"]')
            : null;

        if (!(scope instanceof HTMLElement) || !(viewport instanceof HTMLElement) || !(scrubbable instanceof HTMLElement)) {
            return;
        }

        const rect = viewport.getBoundingClientRect();
        const anchorOffsetY = clamp(event.clientY - rect.top, 0, viewport.clientHeight);
        const nextFocus = focusFromPointer(scope, event.clientY);

        state.drag = {
            anchorOffsetY,
            moved: false,
            root: scope,
            viewport,
        };
        viewport.dataset.dragging = 'true';

        if (typeof viewport.setPointerCapture === 'function') {
            try {
                viewport.setPointerCapture(event.pointerId);
            } catch (error) {
            }
        }

        if (nextFocus !== null) {
            updateLocalFocus(scope, nextFocus);
            showScrubPreview(scope, segmentForFocus(scope, nextFocus), nextFocus);
        }

        event.preventDefault();
    };

    const handlePointerMove = (event) => {
        if (shouldIgnoreMouseEvent(event)) {
            return;
        }

        if (!state.drag) {
            return;
        }

        const nextFocus = focusFromPointer(state.drag.root, event.clientY);

        if (nextFocus === null) {
            return;
        }

        state.drag.moved = true;
        updateLocalFocus(state.drag.root, nextFocus);
        showScrubPreview(state.drag.root, segmentForFocus(state.drag.root, nextFocus), nextFocus);
    };

    const clearDrag = (event) => {
        if (shouldIgnoreMouseEvent(event)) {
            return;
        }

        if (!state.drag) {
            return;
        }

        const scope = state.drag.root;
        const viewport = state.drag.viewport;
        const nextFocus = focusFromPointer(scope, event.clientY) ?? currentFocusMs(scope);

        if (typeof viewport.releasePointerCapture === 'function') {
            try {
                viewport.releasePointerCapture(event.pointerId);
            } catch (error) {
            }
        }

        delete viewport.dataset.dragging;

        state.lastDragEndedAt = Date.now();

        updateLocalFocus(scope, nextFocus);
        centerViewportOnFocus(scope, nextFocus, state.drag.anchorOffsetY);
        hideScrubPreview(scope);
        state.drag = null;
        commitFocus(scope, nextFocus);
    };

    const handleScroll = (event) => {
        const scope = rootFor(event.target);

        if (scope instanceof HTMLElement && event.target instanceof HTMLElement && event.target.matches('[data-role="rail-viewport"]')) {
            updateVisibleRange(scope);
        }
    };

    const handleInput = (event) => {
        const target = event.target;
        const scope = rootFor(target);

        if (!(scope instanceof HTMLElement) || !(target instanceof HTMLInputElement) || target.dataset.role !== 'audio-volume-slider') {
            return;
        }

        state.stageVolume = clampVolume(Number(target.value) / 100);
        updateStageVolumeUi(scope);
        syncStageAudioState(scope, stageVideo(scope));
    };

    const handleClick = (event) => {
        if (Date.now() - state.lastDragEndedAt < 180) {
            event.preventDefault();
            event.stopPropagation();

            return;
        }

        const scope = rootFor(event.target);

        if (!(scope instanceof HTMLElement) || !(event.target instanceof Element)) {
            return;
        }

        const tick = event.target.closest('[data-role="rail-tick"]');
        const segment = event.target.closest('[data-role="rail-segment"]');
        const thumbnail = event.target.closest('[data-role="rail-thumbnail"]');
        const playbackToggle = event.target.closest('[data-role="playback-toggle"]');
        const audioToggle = event.target.closest('[data-role="audio-toggle"]');

        if (playbackToggle instanceof HTMLButtonElement) {
            event.preventDefault();
            toggleStagePlayback(scope);

            return;
        }

        if (audioToggle instanceof HTMLButtonElement) {
            event.preventDefault();
            toggleStageAudio(scope);

            return;
        }

        const focusTarget = tick instanceof HTMLElement
            ? tick
            : segment instanceof HTMLElement
                ? segment
                : thumbnail instanceof HTMLElement
                    ? thumbnail
                    : null;

        if (focusTarget instanceof HTMLElement) {
            const nextFocus = readNumber(focusTarget, 'focusMs', currentFocusMs(scope));

            updateLocalFocus(scope, nextFocus);
            centerViewportOnFocus(scope, nextFocus);
            hideScrubPreview(scope);
            commitFocus(scope, nextFocus);
            event.preventDefault();
        }
    };

    const startObserver = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.observerScope = scope;

        state.observer = new MutationObserver(() => {
            const currentRoot = root();

            if (!(currentRoot instanceof HTMLElement) || state.observerPauseDepth > 0 || state.drag) {
                return;
            }

            refreshScope(currentRoot);
        });

        state.observer.observe(scope, {
            attributes: true,
            attributeFilter: ['data-audio-state'],
            childList: true,
            subtree: true,
        });
    };

    const destroy = () => {
        state.cleanupFns.forEach((cleanup) => cleanup());
        state.cleanupFns = [];

        if (state.observer) {
            state.observer.disconnect();
            state.observer = null;
        }

        state.observerScope = null;

        cleanupVideoBinding();
        state.drag = null;
    };

    const bootstrap = () => {
        destroy();

        const scope = root();

        if (!(scope instanceof HTMLElement)) {
            return;
        }

        updateLocalFocus(scope, currentFocusMs(scope));
        centerViewportOnFocus(scope, currentFocusMs(scope));
        startObserver(scope);
        refreshScope(scope);

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('pointermove', handlePointerMove);
        document.addEventListener('pointerup', clearDrag);
        document.addEventListener('pointercancel', clearDrag);
        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('mousemove', handlePointerMove);
        document.addEventListener('mouseup', clearDrag);
        document.addEventListener('scroll', handleScroll, true);
        document.addEventListener('input', handleInput, true);
        document.addEventListener('click', handleClick, true);

        state.cleanupFns.push(() => document.removeEventListener('pointerdown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('pointermove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('pointerup', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('pointercancel', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('mousedown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('mousemove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('mouseup', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('scroll', handleScroll, true));
        state.cleanupFns.push(() => document.removeEventListener('input', handleInput, true));
        state.cleanupFns.push(() => document.removeEventListener('click', handleClick, true));
    };

    window.BigBrothasRecordingReviewModule = {
        bootstrap,
        destroy,
    };

    document.addEventListener('livewire:navigated', bootstrap);
    document.addEventListener('livewire:navigating', destroy);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    } else {
        bootstrap();
    }
})();