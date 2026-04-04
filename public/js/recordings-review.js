(() => {
    if (window.BigBrothasRecordingReviewModule) {
        window.BigBrothasRecordingReviewModule.bootstrap();

        return;
    }

    const defaultStageControllerState = () => ({
        initialized: false,
        isMuted: true,
        isPlaying: true,
        volume: 1,
    });

    const defaultRailState = () => ({
        cameraId: null,
        loadedRanges: [],
        pendingKeys: new Set(),
        renderFrame: null,
        renderKey: '',
        segments: [],
        thumbnailObserver: null,
        thumbnailObserverScope: null,
        ticks: [],
    });

    const defaultStagePrewarmState = () => ({
        container: null,
        entries: new Map(),
    });

    const state = {
        activeCameraId: null,
        boundAudio: null,
        boundAudioCleanup: null,
        boundVideo: null,
        boundVideoCleanup: null,
        boundViewport: null,
        boundViewportCleanup: null,
        cleanupFns: [],
        drag: null,
        lastDragEndedAt: 0,
        lastNativeScrollAt: 0,
        lastPointerEventAt: 0,
        lastScrollbarPointerAt: 0,
        lastWheelZoomAt: 0,
        observer: null,
        observerPauseDepth: 0,
        observerScope: null,
        resizeObservedElements: [],
        resizeObserver: null,
        resizeObserverScope: null,
        scrubPreviewFocusMs: null,
        scrubPreviewRequestId: 0,
        scrubSpriteCache: new Map(),
        scrubSpritePending: new Map(),
        scrubPreviewVisible: false,
        rail: defaultRailState(),
        stage: defaultStageControllerState(),
        stagePrewarm: defaultStagePrewarmState(),
        stageAudioLastSyncAt: 0,
        pendingViewportRestore: false,
        viewportScrollTop: null,
        zoomScale: null,
        zoomSyncTimer: null,
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const clampVolume = (value) => clamp(Number(value) || 0, 0, 1);
    const normalizeMediaUrl = (value) => {
        if (typeof value !== 'string') {
            return '';
        }

        const normalized = value.trim();

        if (normalized === '') {
            return '';
        }

        try {
            return new URL(normalized, window.location.href).toString();
        } catch (error) {
            return normalized;
        }
    };
    const displayTimezone = (scope = root()) => scope instanceof HTMLElement && scope.dataset.displayTimezone
        ? scope.dataset.displayTimezone
        : 'UTC';
    const focusFormatterCache = new Map();

    const focusFormatter = (scope = root()) => {
        const timezone = displayTimezone(scope);

        if (!focusFormatterCache.has(timezone)) {
            focusFormatterCache.set(timezone, new Intl.DateTimeFormat('en-GB', {
                timeZone: timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23',
                timeZoneName: 'short',
            }));
        }

        return focusFormatterCache.get(timezone);
    };

    const formatFocusLabel = (focusMs, scope = root()) => {
        const date = new Date(Number(focusMs));
        const parts = focusFormatter(scope).formatToParts(date).reduce((carry, part) => {
            carry[part.type] = part.value;

            return carry;
        }, {});

        return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second} ${parts.timeZoneName}`;
    };

    const root = () => document.querySelector('[data-recording-review-root]');
    const rootFor = (element) => element instanceof Element ? element.closest('[data-recording-review-root]') : null;
    const timelineRail = (scope) => scope?.querySelector('[data-role="timeline-rail"]') || null;
    const railViewport = (scope) => scope?.querySelector('[data-role="rail-viewport"]') || null;
    const railTrack = (scope) => scope?.querySelector('[data-role="rail-track"]') || null;
    const railTicksLayer = (scope) => scope?.querySelector('[data-role="rail-ticks"]') || null;
    const railSegmentsLayer = (scope) => scope?.querySelector('[data-role="rail-segments"]') || null;
    const railThumbnailsLayer = (scope) => scope?.querySelector('[data-role="rail-thumbnails"]') || null;
    const stageAudio = (scope) => scope?.querySelector('[data-role="companion-audio"]') || null;
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
    const timelineHours = (scope) => Math.max(1, timelineDurationMs(scope) / 3600000);
    const timelineMaximumFocusMs = (scope) => Math.max(timelineStartMs(scope), timelineEndMs(scope) - 1000);
    const currentFocusMs = (scope) => clamp(readNumber(scope, 'focusMs', timelineStartMs(scope)), timelineStartMs(scope), timelineMaximumFocusMs(scope));
    const activeCameraId = (scope) => scope instanceof HTMLElement ? String(scope.dataset.activeCameraId || '') : '';
    const railBaseHourHeightPx = (scope) => Math.max(1, readNumber(scope, 'railBaseHourHeightPx', 88));
    const railMinTrackHeightPx = (scope) => Math.max(1, readNumber(scope, 'railMinTrackHeightPx', 1800));
    const railChunkDurationMs = (scope) => Math.max(60000, readNumber(scope, 'railChunkDurationMs', 28800000));
    const railBufferDurationMs = (scope) => Math.max(60000, readNumber(scope, 'railBufferDurationMs', 14400000));
    const minimumZoomScale = (scope) => Math.max(0.25, readNumber(scope, 'zoomMinScale', 1));
    const maximumZoomScale = (scope) => Math.max(minimumZoomScale(scope), readNumber(scope, 'zoomMaxScale', 8));
    const zoomStepFactor = (scope) => Math.max(1.01, readNumber(scope, 'zoomStepFactor', 1.18));
    const secondaryTickIntervalMinutes = (scope) => Math.max(1, readNumber(scope, 'secondaryTickIntervalMinutes', 15));
    const secondaryTickMinLabelSpacingPx = (scope) => Math.max(1, readNumber(scope, 'secondaryTickMinLabelSpacingPx', 20));
    const currentZoomScale = (scope) => clamp(state.zoomScale ?? readNumber(scope, 'zoomScale', 1), minimumZoomScale(scope), maximumZoomScale(scope));
    const setCurrentZoomScale = (scope, zoomScale) => {
        const nextScale = clamp(Number(zoomScale) || 1, minimumZoomScale(scope), maximumZoomScale(scope));

        state.zoomScale = nextScale;

        if (scope instanceof HTMLElement) {
            scope.dataset.zoomScale = String(nextScale);
        }

        return nextScale;
    };
    const normalizedZoomScale = (scope, zoomScale = currentZoomScale(scope)) => Number(
        clamp(Number(zoomScale) || 1, minimumZoomScale(scope), maximumZoomScale(scope)).toFixed(3),
    );
    const scaledTrackHeight = (scope, zoomScale = currentZoomScale(scope)) => Math.max(
        railMinTrackHeightPx(scope),
        Math.ceil(timelineHours(scope) * railBaseHourHeightPx(scope) * zoomScale),
    );
    const timelineRatioForMs = (scope, timestampMs) => clamp(
        (Number(timestampMs || timelineStartMs(scope)) - timelineStartMs(scope)) / timelineDurationMs(scope),
        0,
        1,
    );
    const clippedSegmentRangeMs = (scope, startMs, endMs) => {
        const timelineStart = timelineStartMs(scope);
        const timelineEnd = timelineEndMs(scope);
        const rawStartMs = Number(startMs || timelineStart);
        const rawEndMs = Math.max(rawStartMs + 1, Number(endMs || rawStartMs));
        const clippedStartMs = clamp(rawStartMs, timelineStart, timelineEnd);
        const clippedEndMs = Math.max(clippedStartMs + 1, clamp(rawEndMs, timelineStart, timelineEnd));

        return {
            endMs: clippedEndMs,
            startMs: clippedStartMs,
        };
    };
    const railTrackOffsetPxForMs = (scope, timestampMs, trackHeight = railTrackHeight(scope)) => Math.max(
        0,
        timelineRatioForMs(scope, timestampMs) * Math.max(1, trackHeight),
    );

    const setText = (element, value) => {
        if (element instanceof HTMLElement) {
            element.textContent = value;
        }
    };

    const parseJsonScript = (element, fallback = []) => {
        if (!(element instanceof HTMLScriptElement)) {
            return fallback;
        }

        try {
            const parsed = JSON.parse(element.textContent || '[]');

            return Array.isArray(parsed) ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    };

    const persistZoomInUrl = (zoomScale, scope = root()) => {
        if (!(scope instanceof HTMLElement) || !(window.location instanceof Location) || typeof window.history?.replaceState !== 'function') {
            return;
        }

        const url = new URL(window.location.href);
        const normalizedZoomScale = clamp(Number(zoomScale) || 1, minimumZoomScale(scope), maximumZoomScale(scope));

        if (Math.abs(normalizedZoomScale - minimumZoomScale(scope)) < 0.001) {
            url.searchParams.delete('zoom');
        } else {
            url.searchParams.set('zoom', normalizedZoomScale.toFixed(2));
        }

        window.history.replaceState(window.history.state, '', url);
    };

    const updateZoomUi = (scope, zoomScale = currentZoomScale(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const normalizedZoomScale = clamp(Number(zoomScale) || 1, minimumZoomScale(scope), maximumZoomScale(scope));

        scope.querySelectorAll('[data-role="zoom-label"]').forEach((element) => {
            setText(element, `${normalizedZoomScale.toFixed(2)}x`);
        });

        scope.querySelectorAll('[data-role="zoom-in"]').forEach((element) => {
            if (element instanceof HTMLButtonElement) {
                element.disabled = normalizedZoomScale >= (maximumZoomScale(scope) - 0.001);
            }
        });

        scope.querySelectorAll('[data-role="zoom-out"]').forEach((element) => {
            if (element instanceof HTMLButtonElement) {
                element.disabled = normalizedZoomScale <= (minimumZoomScale(scope) + 0.001);
            }
        });

        scope.querySelectorAll('[data-role="zoom-reset"]').forEach((element) => {
            if (element instanceof HTMLButtonElement) {
                element.disabled = Math.abs(normalizedZoomScale - minimumZoomScale(scope)) < 0.001;
            }
        });
    };

    const focusFromViewportOffset = (scope, offsetY) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            return null;
        }

        const trackOffsetY = clamp(viewport.scrollTop + Number(offsetY), 0, railTrackHeight(scope));
        const ratio = clamp(trackOffsetY / railTrackHeight(scope), 0, 1);

        return timelineStartMs(scope) + (timelineDurationMs(scope) * ratio);
    };

    const applyZoomScale = (scope, zoomScale, options = {}) => {
        if (!(scope instanceof HTMLElement)) {
            return false;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            return false;
        }

        const nextZoomScale = clamp(Number(zoomScale) || 1, minimumZoomScale(scope), maximumZoomScale(scope));
        const previousZoomScale = currentZoomScale(scope);

        if (Math.abs(nextZoomScale - previousZoomScale) < 0.001) {
            updateZoomUi(scope, previousZoomScale);
            persistZoomInUrl(previousZoomScale, scope);

            return false;
        }

        const anchorOffsetY = clamp(
            Number(options.anchorOffsetY ?? (viewport.clientHeight / 2)),
            0,
            Math.max(0, viewport.clientHeight),
        );
        const anchorMs = clamp(
            Number(options.anchorMs ?? focusFromViewportOffset(scope, anchorOffsetY) ?? currentFocusMs(scope)),
            timelineStartMs(scope),
            timelineEndMs(scope),
        );

        setCurrentZoomScale(scope, nextZoomScale);
        applyTimelineScale(scope);

        const nextTrackHeight = railTrackHeight(scope);
        const nextScrollTop = ((anchorMs - timelineStartMs(scope)) / timelineDurationMs(scope)) * nextTrackHeight - anchorOffsetY;

        viewport.scrollTop = clamp(nextScrollTop, 0, Math.max(0, nextTrackHeight - viewport.clientHeight));
        state.viewportScrollTop = viewport.scrollTop;
        hideScrubPreview(scope);
        updateVisibleRange(scope);
        updateZoomUi(scope, nextZoomScale);
        persistZoomInUrl(nextZoomScale, scope);

        return true;
    };

    const readStageInitialMuted = (scope) => {
        const stage = stageRoot(scope);

        return !(stage instanceof HTMLElement) || stage.dataset.initialMuted !== 'false';
    };

    const readStageInitialPlaying = (scope) => {
        const stage = stageRoot(scope);

        return !(stage instanceof HTMLElement) || stage.dataset.initialPlaying !== 'false';
    };

    const readStageInitialVolume = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return 1;
        }

        const slider = scope.querySelector('[data-role="audio-volume-slider"]');

        if (slider instanceof HTMLInputElement) {
            return clampVolume(Number(slider.value) / 100);
        }

        const stage = stageRoot(scope);

        return stage instanceof HTMLElement ? clampVolume(stage.dataset.initialVolume ?? 1) : 1;
    };

    const ensureStageControllerState = (scope, video = stageVideo(scope)) => {
        if (state.stage.initialized) {
            return state.stage;
        }

        state.stage = {
            initialized: true,
            isMuted: video instanceof HTMLVideoElement ? video.muted : readStageInitialMuted(scope),
            isPlaying: readStageInitialPlaying(scope),
            volume: video instanceof HTMLVideoElement ? clampVolume(video.volume) : readStageInitialVolume(scope),
        };

        return state.stage;
    };

    const stageControllerSnapshot = (scope, video = stageVideo(scope)) => ({
        ...ensureStageControllerState(scope, video),
    });

    const updateStageVolumeUi = (scope, volume = ensureStageControllerState(scope).volume) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const volumePercent = Math.round(clampVolume(volume) * 100);

        scope.querySelectorAll('[data-role="audio-volume-slider"]').forEach((element) => {
            if (element instanceof HTMLInputElement) {
                element.value = String(volumePercent);
            }
        });

        scope.querySelectorAll('[data-role="audio-volume-value"]').forEach((element) => {
            setText(element, `${volumePercent}%`);
        });
    };

    const syncStagePlaybackUi = (scope, isPlaying) => {
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
            stage.dataset.playbackState = isPlaying ? 'playing' : 'paused';
        }

        if (playbackToggle instanceof HTMLButtonElement) {
            playbackToggle.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
            playbackToggle.setAttribute('aria-label', isPlaying ? `Pause ${cameraName}` : `Play ${cameraName}`);
        }

        setText(playbackIndicator, isPlaying ? 'Playing' : 'Paused');
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

    const renderStageControllerUi = (scope, playerState = ensureStageControllerState(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const stage = stageRoot(scope);

        if (stage instanceof HTMLElement) {
            stage.dataset.playerMuted = playerState.isMuted ? 'true' : 'false';
            stage.dataset.playerPlaying = playerState.isPlaying ? 'true' : 'false';
            stage.dataset.playerVolume = String(playerState.volume);
        }

        syncStagePlaybackUi(scope, playerState.isPlaying);
        syncStageAudioUi(scope, playerState.isMuted);
        updateStageVolumeUi(scope, playerState.volume);
    };

    const setStageControllerState = (scope, partialState = {}) => {
        if (!(scope instanceof HTMLElement)) {
            return defaultStageControllerState();
        }

        const currentState = ensureStageControllerState(scope);
        const nextState = {
            ...currentState,
            ...partialState,
            initialized: true,
            volume: clampVolume(partialState.volume ?? currentState.volume),
        };

        state.stage = nextState;
        renderStageControllerUi(scope, nextState);

        return nextState;
    };

    const stagePreviewStreamUrl = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.previewStreamUrl || '').trim()
        : '';

    const stageReviewStreamUrl = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.reviewStreamUrl || '').trim()
        : '';

    const stageDirectStreamUrl = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.directStreamUrl || video.dataset.fallbackStreamUrl || '').trim()
        : '';

    const stagePreferredAssetStatusLabel = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.previewStatusLabel || 'Assets cached').trim()
        : 'Assets cached';

    const stageReviewAssetStatusLabel = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.reviewStatusLabel || 'Playback stream').trim()
        : 'Playback stream';

    const stageDirectAssetStatusLabel = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.directStatusLabel || 'Direct stream').trim()
        : 'Direct stream';

    const desiredStageStreamUrl = (video) => {
        const previewUrl = stagePreviewStreamUrl(video);
        const directUrl = stageDirectStreamUrl(video);

        return previewUrl || directUrl || stageReviewStreamUrl(video);
    };

    const desiredStageAudioUrl = (video) => {
        const reviewUrl = stageReviewStreamUrl(video);
        const directUrl = stageDirectStreamUrl(video);

        return reviewUrl || directUrl;
    };

    const stageUsesPreviewSource = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return false;
        }

        const normalizedPreviewUrl = normalizeMediaUrl(stagePreviewStreamUrl(video));
        const normalizedCurrentSourceUrl = normalizeMediaUrl(video.currentSrc || video.getAttribute('src') || desiredStageStreamUrl(video));

        return normalizedPreviewUrl !== '' && normalizedCurrentSourceUrl === normalizedPreviewUrl;
    };

    const stageUsesCompanionAudio = (video) => stageUsesPreviewSource(video) && desiredStageAudioUrl(video) !== '';

    const stageAssetStatusLabelForUrl = (video, sourceUrl) => {
        const normalizedSourceUrl = normalizeMediaUrl(sourceUrl);
        const normalizedPreviewUrl = normalizeMediaUrl(stagePreviewStreamUrl(video));
        const normalizedReviewUrl = normalizeMediaUrl(stageReviewStreamUrl(video));
        const normalizedDirectUrl = normalizeMediaUrl(stageDirectStreamUrl(video));

        if (normalizedSourceUrl !== '' && normalizedSourceUrl === normalizedPreviewUrl) {
            return stagePreferredAssetStatusLabel(video);
        }

        if (normalizedSourceUrl !== '' && normalizedSourceUrl === normalizedReviewUrl) {
            return stageReviewAssetStatusLabel(video);
        }

        if (normalizedSourceUrl !== '' && normalizedSourceUrl === normalizedDirectUrl) {
            return stageDirectAssetStatusLabel(video);
        }

        if (normalizedReviewUrl !== '') {
            return stageReviewAssetStatusLabel(video);
        }

        return normalizedDirectUrl !== '' ? stageDirectAssetStatusLabel(video) : stagePreferredAssetStatusLabel(video);
    };

    const updateStageAssetStatus = (scope, video, sourceUrl = null) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const assetStatus = scope.querySelector('[data-role="asset-status"]');
        const resolvedSourceUrl = sourceUrl
            ?? (video instanceof HTMLVideoElement ? (video.currentSrc || video.getAttribute('src') || '') : '');

        setText(assetStatus, stageAssetStatusLabelForUrl(video, resolvedSourceUrl));
    };

    const pendingStageFocusMs = (video, fallback = null) => {
        if (!(video instanceof HTMLVideoElement)) {
            return fallback;
        }

        const resolved = Number(video.dataset.pendingFocusMs ?? fallback);

        return Number.isFinite(resolved) ? resolved : fallback;
    };

    const setPendingStageFocusMs = (video, focusMs) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        const resolved = Number(focusMs);

        if (!Number.isFinite(resolved)) {
            delete video.dataset.pendingFocusMs;

            return;
        }

        video.dataset.pendingFocusMs = String(resolved);
    };

    const clearPendingStageSourceLoad = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        delete video.dataset.pendingSourceLoad;
        delete video.dataset.pendingFocusMs;
    };

    const stageSourceIsLoading = (video) => video instanceof HTMLVideoElement && video.dataset.pendingSourceLoad === 'true';

    const resetStagePrewarm = () => {
        state.stagePrewarm.entries.forEach((entry) => {
            if (!(entry?.element instanceof HTMLVideoElement)) {
                return;
            }

            entry.element.removeAttribute('src');

            try {
                entry.element.load();
            } catch (error) {
            }

            entry.element.remove();
        });

        state.stagePrewarm.entries.clear();

        if (state.stagePrewarm.container instanceof HTMLElement) {
            state.stagePrewarm.container.remove();
        }

        state.stagePrewarm = defaultStagePrewarmState();
    };

    const ensureStagePrewarmContainer = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        if (state.stagePrewarm.container instanceof HTMLElement && state.stagePrewarm.container.isConnected) {
            return state.stagePrewarm.container;
        }

        const container = document.createElement('div');

        container.hidden = true;
        container.setAttribute('aria-hidden', 'true');
        container.dataset.role = 'stage-prewarm-cache';
        scope.appendChild(container);
        state.stagePrewarm.container = container;

        return container;
    };

    const prewarmStageUrl = (scope, url) => {
        const normalizedUrl = normalizeMediaUrl(url);

        if (!(scope instanceof HTMLElement) || normalizedUrl === '') {
            return;
        }

        const container = ensureStagePrewarmContainer(scope);

        if (!(container instanceof HTMLElement)) {
            return;
        }

        const existing = state.stagePrewarm.entries.get(normalizedUrl);

        if (existing?.element instanceof HTMLVideoElement) {
            existing.lastUsedAt = Date.now();

            return;
        }

        const element = document.createElement('video');

        element.muted = true;
        element.preload = 'metadata';
        element.playsInline = true;
        element.tabIndex = -1;
        element.setAttribute('aria-hidden', 'true');
        element.src = normalizedUrl;
        container.appendChild(element);

        try {
            element.load();
        } catch (error) {
        }

        state.stagePrewarm.entries.set(normalizedUrl, {
            element,
            lastUsedAt: Date.now(),
        });

        while (state.stagePrewarm.entries.size > 3) {
            const oldest = Array.from(state.stagePrewarm.entries.entries())
                .sort((left, right) => left[1].lastUsedAt - right[1].lastUsedAt)[0];

            if (!oldest) {
                break;
            }

            const [oldestUrl, entry] = oldest;

            if (entry?.element instanceof HTMLVideoElement) {
                entry.element.removeAttribute('src');

                try {
                    entry.element.load();
                } catch (error) {
                }

                entry.element.remove();
            }

            state.stagePrewarm.entries.delete(oldestUrl);
        }
    };

    const prewarmStageNeighbors = (scope, segment) => {
        if (!(scope instanceof HTMLElement) || !(segment && typeof segment === 'object')) {
            return;
        }

        readRailBootstrapData(scope);

        const orderedSegments = state.rail.segments
            .filter((candidate) => candidate && typeof candidate === 'object')
            .slice()
            .sort((left, right) => {
                const leftStart = Number(left.startMs || 0);
                const rightStart = Number(right.startMs || 0);

                if (leftStart !== rightStart) {
                    return leftStart - rightStart;
                }

                return Number(left.id || 0) - Number(right.id || 0);
            });
        const currentIndex = orderedSegments.findIndex((candidate) => Number(candidate.id || 0) === Number(segment.id || 0));

        if (currentIndex < 0) {
            return;
        }

        [orderedSegments[currentIndex - 1], orderedSegments[currentIndex + 1]].forEach((neighbor) => {
            if (!(neighbor && typeof neighbor === 'object')) {
                return;
            }

            const previewUrl = normalizeMediaUrl(String(neighbor.preferredStreamUrl || ''));

            if (previewUrl !== '') {
                prewarmStageUrl(scope, previewUrl);
            }
        });
    };

    const seekStageVideoToFocus = (scope, video, focusMs = null) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return null;
        }

        const resolvedFocusMs = clamp(
            Number(focusMs ?? currentFocusMs(scope)),
            timelineStartMs(scope),
            timelineMaximumFocusMs(scope),
        );
        const startMs = readNumber(video, 'startMs', 0);
        const durationSeconds = Math.max(0, Number(video.dataset.durationSeconds || 0));
        const seekSeconds = clamp((resolvedFocusMs - startMs) / 1000, 0, Math.max(0, durationSeconds - 0.2));

        try {
            if (Math.abs(Number(video.currentTime || 0) - seekSeconds) > 0.35) {
                video.currentTime = seekSeconds;
            }
        } catch (error) {
        }

        return seekSeconds;
    };

    const seekStagePlaybackToFocus = (scope, focusMs = null) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const video = stageVideo(scope);

        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        const resolvedFocusMs = clamp(
            Number(focusMs ?? currentFocusMs(scope)),
            timelineStartMs(scope),
            timelineMaximumFocusMs(scope),
        );

        if (stageSourceIsLoading(video)) {
            setPendingStageFocusMs(video, resolvedFocusMs);

            return;
        }

        const applySeek = () => {
            try {
                video.pause();
            } catch (error) {
            }

            const seekSeconds = seekStageVideoToFocus(scope, video, resolvedFocusMs);

            if (seekSeconds === null) {
                applyStageControllerStateToVideo(scope, video);

                return;
            }

            const finalizeSeek = () => {
                syncCompanionAudioTime(video, stageAudio(scope), true);
                applyStageControllerStateToVideo(scope, video);
            };

            if (Math.abs(Number(video.currentTime || 0) - seekSeconds) <= 0.35) {
                finalizeSeek();

                return;
            }

            const handleSeeked = () => {
                video.removeEventListener('seeked', handleSeeked);
                finalizeSeek();
            };

            video.addEventListener('seeked', handleSeeked, { once: true });

            try {
                video.currentTime = seekSeconds;
            } catch (error) {
                video.removeEventListener('seeked', handleSeeked);
                finalizeSeek();
            }
        };

        if (video.readyState >= 1) {
            applySeek();

            return;
        }

        const handleLoadedMetadata = () => {
            video.removeEventListener('loadedmetadata', handleLoadedMetadata);
            applySeek();
        };

        video.addEventListener('loadedmetadata', handleLoadedMetadata, { once: true });
    };

    const playVideo = (video, onRejected = null) => {
        if (!(video instanceof HTMLMediaElement)) {
            return;
        }

        const playPromise = video.play();

        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {
                if (typeof onRejected === 'function') {
                    onRejected();
                }
            });
        }
    };

    const ensureStageVideoSource = (scope, video, shouldPlay = false) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return false;
        }

        const targetSourceUrl = desiredStageStreamUrl(video);

        if (targetSourceUrl === '') {
            updateStageAssetStatus(scope, video);

            return false;
        }

        const normalizedTargetSourceUrl = normalizeMediaUrl(targetSourceUrl);
        const normalizedCurrentSourceUrl = normalizeMediaUrl(video.currentSrc || video.getAttribute('src') || '');

        if (normalizedCurrentSourceUrl === normalizedTargetSourceUrl) {
            clearPendingStageSourceLoad(video);
            updateStageAssetStatus(scope, video, targetSourceUrl);

            return false;
        }

        const handleSourceLoaded = () => {
            const nextFocusMs = pendingStageFocusMs(video, currentFocusMs(scope));

            clearPendingStageSourceLoad(video);
            seekStageVideoToFocus(scope, video, nextFocusMs);
            updateStageAssetStatus(scope, video, targetSourceUrl);
            const resolvedPlayerState = ensureStageControllerState(scope, video);

            if (shouldPlay && !resolvedPlayerState.isPlaying) {
                state.stage = {
                    ...resolvedPlayerState,
                    initialized: true,
                    isPlaying: true,
                };
            }

            applyStageControllerStateToVideo(scope, video);
        };

        video.dataset.pendingSourceLoad = 'true';
        video.addEventListener('loadedmetadata', handleSourceLoaded, { once: true });
        video.src = targetSourceUrl;
        video.load();
        updateStageAssetStatus(scope, video, targetSourceUrl);

        return true;
    };

    const syncStageControllerFromVideo = (scope, video = stageVideo(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            return stageControllerSnapshot(scope, video);
        }

        const currentState = ensureStageControllerState(scope, video);
        const usesCompanionAudio = stageUsesCompanionAudio(video);
        const nextState = {
            ...currentState,
            initialized: true,
            isMuted: usesCompanionAudio ? currentState.isMuted : (video instanceof HTMLVideoElement ? video.muted : currentState.isMuted),
            isPlaying: video instanceof HTMLVideoElement ? !video.paused && !video.ended : currentState.isPlaying,
            volume: usesCompanionAudio ? currentState.volume : (video instanceof HTMLVideoElement ? clampVolume(video.volume) : currentState.volume),
        };

        state.stage = nextState;
        renderStageControllerUi(scope, nextState);

        if (video instanceof HTMLVideoElement) {
            updateStageAssetStatus(scope, video);
        }

        return nextState;
    };

    const syncCompanionAudioTime = (video, audio, force = false) => {
        if (!(video instanceof HTMLVideoElement) || !(audio instanceof HTMLAudioElement) || audio.readyState < 1) {
            return;
        }

        const nextTime = Number(video.currentTime || 0);
        const driftSeconds = Math.abs(Number(audio.currentTime || 0) - nextTime);

        if (!force) {
            const syncedRecently = (Date.now() - state.stageAudioLastSyncAt) < 1500;

            if (audio.paused || driftSeconds < 1.25 || syncedRecently) {
                return;
            }
        }

        if (force && driftSeconds < 0.08) {
            return;
        }

        try {
            audio.currentTime = nextTime;
            state.stageAudioLastSyncAt = Date.now();
        } catch (error) {
        }
    };

    const cleanupAudioBinding = () => {
        if (typeof state.boundAudioCleanup === 'function') {
            state.boundAudioCleanup();
        }

        state.boundAudio = null;
        state.boundAudioCleanup = null;
        state.stageAudioLastSyncAt = 0;
    };

    const bindStageAudio = (scope, video = stageVideo(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            cleanupAudioBinding();

            return;
        }

        const audio = stageAudio(scope);

        if (!(audio instanceof HTMLAudioElement) || !(video instanceof HTMLVideoElement)) {
            cleanupAudioBinding();

            return;
        }

        if (state.boundAudio === audio) {
            return;
        }

        cleanupAudioBinding();

        const handleLoadedMetadata = () => {
            syncCompanionAudioTime(video, audio, true);
        };

        audio.addEventListener('loadedmetadata', handleLoadedMetadata);

        if (stageUsesPreviewSource(video)) {
            ensureStageAudioSource(scope, audio, video);
        }

        state.boundAudio = audio;
        state.boundAudioCleanup = () => {
            audio.removeEventListener('loadedmetadata', handleLoadedMetadata);
            audio.muted = true;
            audio.volume = 0;

            try {
                audio.pause();
            } catch (error) {
            }

            audio.removeAttribute('src');

            try {
                audio.load();
            } catch (error) {
            }
        };
    };

    const ensureStageAudioSource = (scope, audio, video) => {
        if (!(scope instanceof HTMLElement) || !(audio instanceof HTMLAudioElement) || !(video instanceof HTMLVideoElement)) {
            return false;
        }

        const targetSourceUrl = desiredStageAudioUrl(video);

        if (targetSourceUrl === '') {
            return false;
        }

        const normalizedTargetSourceUrl = normalizeMediaUrl(targetSourceUrl);
        const normalizedCurrentSourceUrl = normalizeMediaUrl(audio.currentSrc || audio.getAttribute('src') || '');

        if (normalizedCurrentSourceUrl === normalizedTargetSourceUrl) {
            return false;
        }

        audio.src = targetSourceUrl;
        audio.load();

        return true;
    };

    const applyStageCompanionAudioState = (scope, video, playerState, audio = stageAudio(scope)) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement) || !(audio instanceof HTMLAudioElement)) {
            return false;
        }

        const shouldUseCompanionAudio = stageUsesCompanionAudio(video);

        if (!shouldUseCompanionAudio) {
            audio.muted = true;
            audio.volume = 0;

            try {
                audio.pause();
            } catch (error) {
            }

            return false;
        }

        const audioSourceChanged = ensureStageAudioSource(scope, audio, video);
        audio.muted = playerState.isMuted;
        audio.volume = playerState.volume;

        const shouldForceSync = audioSourceChanged
            || audio.paused
            || Math.abs(Number(audio.currentTime || 0) - Number(video.currentTime || 0)) > 2.5;

        syncCompanionAudioTime(video, audio, shouldForceSync);

        if (!playerState.isMuted && playerState.isPlaying) {
            playVideo(audio, () => {
                syncCompanionAudioTime(video, audio, true);
            });
        } else {
            try {
                audio.pause();
            } catch (error) {
            }
        }

        return true;
    };

    const applyStageControllerStateToVideo = (scope, video = stageVideo(scope)) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const playerState = stageControllerSnapshot(scope, video);
        const audio = stageAudio(scope);

        renderStageControllerUi(scope, playerState);

        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        bindStageAudio(scope, video);

        if (ensureStageVideoSource(scope, video, playerState.isPlaying)) {
            return;
        }

        const usingCompanionAudio = applyStageCompanionAudioState(scope, video, playerState, audio);

        video.defaultMuted = usingCompanionAudio ? true : playerState.isMuted;
        video.muted = usingCompanionAudio ? true : playerState.isMuted;

        if (usingCompanionAudio) {
            if (Math.abs(video.volume) > 0.01) {
                video.volume = 0;
            }
        } else if (Math.abs(video.volume - playerState.volume) > 0.01) {
            video.volume = playerState.volume;
        }

        if (!playerState.isPlaying) {
            video.pause();

            return;
        }

        playVideo(video, () => {
            syncStageControllerFromVideo(scope, video);
        });
    };

    const syncStagePlaybackState = (scope, video = stageVideo(scope)) => {
        applyStageControllerStateToVideo(scope, video);
    };

    const syncStageAudioState = (scope, video = stageVideo(scope)) => {
        applyStageControllerStateToVideo(scope, video);
    };

    const toggleStagePlayback = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const playerState = stageControllerSnapshot(scope);

        setStageControllerState(scope, {
            isPlaying: !playerState.isPlaying,
        });
        applyStageControllerStateToVideo(scope, stageVideo(scope));
    };

    const toggleStageAudio = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const playerState = stageControllerSnapshot(scope);

        setStageControllerState(scope, {
            isMuted: !playerState.isMuted,
        });
        applyStageControllerStateToVideo(scope, stageVideo(scope));
    };

    const focusMsForVideoPlayback = (scope, video) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return currentFocusMs(scope);
        }

        const startMs = readNumber(video, 'startMs', currentFocusMs(scope));
        const endMs = Math.max(startMs, readNumber(video, 'endMs', startMs));
        const maxOffsetMs = Math.max(0, (endMs - startMs) - 1);
        const playbackOffsetMs = clamp(Math.round(Number(video.currentTime || 0) * 1000), 0, maxOffsetMs);

        return clamp(startMs + playbackOffsetMs, timelineStartMs(scope), timelineMaximumFocusMs(scope));
    };

    const railTrackHeight = (scope) => {
        const track = railTrack(scope);

        if (!(track instanceof HTMLElement)) {
            return 1;
        }

        return Math.max(track.scrollHeight, track.offsetHeight, 1);
    };

    const secondaryTickLabelSpacingPx = (scope, trackHeight = railTrackHeight(scope)) => (
        (Math.max(1, trackHeight) * secondaryTickIntervalMinutes(scope) * 60000) / timelineDurationMs(scope)
    );

    const updateTimelineTickLabelVisibility = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const shouldShowSecondaryLabels = secondaryTickLabelSpacingPx(scope) >= secondaryTickMinLabelSpacingPx(scope);

        scope.querySelectorAll('[data-role="rail-tick"][data-tick-kind="secondary"]').forEach((tick) => {
            if (!(tick instanceof HTMLElement)) {
                return;
            }

            const label = tick.querySelector('[data-role="rail-tick-label"]');

            tick.classList.toggle('is-label-hidden', !shouldShowSecondaryLabels);

            if (label instanceof HTMLElement) {
                label.classList.toggle('hide-label', !shouldShowSecondaryLabels);
            }
        });
    };

    const viewportOffsetFromClientY = (viewport, clientY) => {
        if (!(viewport instanceof HTMLElement) || !Number.isFinite(Number(clientY))) {
            return null;
        }

        const rect = viewport.getBoundingClientRect();

        return clamp(Number(clientY) - rect.top, 0, viewport.clientHeight);
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
        const label = `${formatFocusLabel(visibleStartMs, scope)} - ${formatFocusLabel(visibleEndMs, scope)}`;

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

        const label = formatFocusLabel(focusMs, scope);

        scope.querySelectorAll('[data-role="focus-label"], [data-role="focus-label-rail"]').forEach((element) => {
            setText(element, label);
        });

        const scrubPreviewTime = scope.querySelector('[data-role="scrub-preview-time"]');
        setText(scrubPreviewTime, label);
    };

    const cleanupRailThumbnailObserver = () => {
        if (state.rail.thumbnailObserver instanceof IntersectionObserver) {
            state.rail.thumbnailObserver.disconnect();
        }

        state.rail.thumbnailObserver = null;
        state.rail.thumbnailObserverScope = null;
    };

    const resetRailState = () => {
        if (Number.isInteger(state.rail.renderFrame)) {
            window.cancelAnimationFrame(state.rail.renderFrame);
        }

        cleanupRailThumbnailObserver();
        state.rail = defaultRailState();
    };

    const normalizeRailRange = (scope, startMs, endMs) => {
        const normalizedStartMs = clamp(Number(startMs || timelineStartMs(scope)), timelineStartMs(scope), timelineEndMs(scope));
        const normalizedEndMs = clamp(
            Math.max(normalizedStartMs + 1000, Number(endMs || normalizedStartMs + 1000)),
            normalizedStartMs + 1000,
            timelineEndMs(scope),
        );

        return {
            endMs: normalizedEndMs,
            startMs: normalizedStartMs,
        };
    };

    const mergeRailRanges = (ranges, nextRange) => {
        const sortedRanges = [...ranges, nextRange]
            .filter((range) => range && Number.isFinite(range.startMs) && Number.isFinite(range.endMs))
            .sort((left, right) => left.startMs - right.startMs);
        const mergedRanges = [];

        sortedRanges.forEach((range) => {
            const currentRange = {
                endMs: Math.max(range.startMs + 1000, range.endMs),
                startMs: range.startMs,
            };
            const previousRange = mergedRanges[mergedRanges.length - 1] || null;

            if (!previousRange || currentRange.startMs > previousRange.endMs) {
                mergedRanges.push(currentRange);

                return;
            }

            previousRange.endMs = Math.max(previousRange.endMs, currentRange.endMs);
        });

        return mergedRanges;
    };

    const railRangeCovered = (ranges, startMs, endMs) => {
        let cursorMs = startMs;

        for (const range of ranges) {
            if (range.endMs < cursorMs) {
                continue;
            }

            if (range.startMs > cursorMs) {
                return false;
            }

            cursorMs = Math.max(cursorMs, range.endMs);

            if (cursorMs >= endMs) {
                return true;
            }
        }

        return cursorMs >= endMs;
    };

    const railSegmentKey = (segment) => {
        const id = segment && segment.id !== undefined && segment.id !== null ? String(segment.id) : '';

        return id !== '' ? id : `${segment?.cameraId || 'camera'}:${segment?.startMs || 0}:${segment?.endMs || 0}`;
    };

    const normalizeRailSegment = (segment) => {
        if (!segment || typeof segment !== 'object') {
            return null;
        }

        const startMs = Number(segment.startMs || 0);
        const endMs = Math.max(startMs + 1000, Number(segment.endMs || startMs + 1000));

        return {
            ...segment,
            endMs,
            focusMs: Number(segment.focusMs || startMs),
            id: segment.id ?? null,
            startMs,
        };
    };

    const storeRailSegments = (segments) => {
        const mergedSegments = new Map(state.rail.segments.map((segment) => [railSegmentKey(segment), segment]));

        segments.forEach((segment) => {
            const normalizedSegment = normalizeRailSegment(segment);

            if (!normalizedSegment) {
                return;
            }

            mergedSegments.set(railSegmentKey(normalizedSegment), normalizedSegment);
        });

        state.rail.segments = Array.from(mergedSegments.values())
            .sort((left, right) => left.startMs - right.startMs);
    };

    const activeRailSegmentId = (scope) => {
        const host = timelineRail(scope);

        return host instanceof HTMLElement ? String(host.dataset.activeSegmentId || '') : '';
    };

    const ensureRailThumbnailObserver = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            cleanupRailThumbnailObserver();

            return null;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement) || typeof IntersectionObserver === 'undefined') {
            cleanupRailThumbnailObserver();

            return null;
        }

        if (state.rail.thumbnailObserver instanceof IntersectionObserver && state.rail.thumbnailObserverScope === scope) {
            return state.rail.thumbnailObserver;
        }

        cleanupRailThumbnailObserver();

        state.rail.thumbnailObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting || !(entry.target instanceof HTMLElement)) {
                    return;
                }

                const thumbnail = entry.target.closest('[data-role="rail-thumbnail"]');

                if (thumbnail instanceof HTMLElement) {
                    hydrateRailThumbnail(thumbnail);
                }

                state.rail.thumbnailObserver?.unobserve(entry.target);
            });
        }, {
            root: viewport,
            rootMargin: '320px 0px 320px 0px',
            threshold: 0.01,
        });
        state.rail.thumbnailObserverScope = scope;

        return state.rail.thumbnailObserver;
    };

    const hydrateRailThumbnail = (thumbnail) => {
        if (!(thumbnail instanceof HTMLElement)) {
            return;
        }

        const frame = thumbnail.querySelector('[data-role="rail-thumbnail-frame"]');
        const thumbnailUrl = String(thumbnail.dataset.thumbnailUrl || '').trim();
        const thumbnailAlt = String(thumbnail.dataset.thumbnailAlt || '').trim();

        if (!(frame instanceof HTMLElement) || thumbnailUrl === '' || frame.querySelector('img')) {
            return;
        }

        const image = document.createElement('img');

        image.alt = thumbnailAlt;
        image.decoding = 'async';
        image.loading = 'lazy';
        image.src = thumbnailUrl;
        frame.appendChild(image);
    };

    const observeRailThumbnails = (scope) => {
        const observer = ensureRailThumbnailObserver(scope);

        scope.querySelectorAll('[data-role="rail-thumbnail-frame"]').forEach((frame) => {
            if (!(frame instanceof HTMLElement)) {
                return;
            }

            if (observer) {
                observer.observe(frame);

                return;
            }

            const thumbnail = frame.closest('[data-role="rail-thumbnail"]');

            if (thumbnail instanceof HTMLElement) {
                hydrateRailThumbnail(thumbnail);
            }
        });
    };

    const railWindowForViewport = (scope) => {
        const viewport = railViewport(scope);
        const trackHeight = railTrackHeight(scope);

        if (!(viewport instanceof HTMLElement)) {
            return normalizeRailRange(scope, timelineStartMs(scope), timelineEndMs(scope));
        }

        const bufferPx = Math.max(viewport.clientHeight, 320);
        const windowStartPx = Math.max(0, viewport.scrollTop - bufferPx);
        const windowEndPx = Math.min(trackHeight, viewport.scrollTop + viewport.clientHeight + bufferPx);
        const startRatio = clamp(windowStartPx / trackHeight, 0, 1);
        const endRatio = clamp(windowEndPx / trackHeight, 0, 1);

        return normalizeRailRange(
            scope,
            timelineStartMs(scope) + (timelineDurationMs(scope) * startRatio),
            timelineStartMs(scope) + (timelineDurationMs(scope) * endRatio),
        );
    };

    const readRailBootstrapData = (scope) => {
        const host = timelineRail(scope);

        if (!(host instanceof HTMLElement)) {
            resetRailState();

            return state.rail;
        }

        const nextCameraId = String(host.dataset.cameraId || activeCameraId(scope));
        const cameraDidChange = state.rail.cameraId !== nextCameraId;

        if (cameraDidChange) {
            resetRailState();
            state.rail.cameraId = nextCameraId;
        }

        if (cameraDidChange || state.rail.ticks.length === 0) {
            state.rail.ticks = parseJsonScript(host.querySelector('[data-role="rail-ticks-json"]'), []);
        }

        if (cameraDidChange || state.rail.segments.length === 0) {
            const initialSegments = parseJsonScript(host.querySelector('[data-role="rail-segments-json"]'), []);
            const initialRange = normalizeRailRange(
                scope,
                readNumber(host, 'initialWindowStartMs', timelineStartMs(scope)),
                readNumber(host, 'initialWindowEndMs', timelineEndMs(scope)),
            );

            storeRailSegments(initialSegments);
            state.rail.loadedRanges = mergeRailRanges(cameraDidChange ? [] : state.rail.loadedRanges, initialRange);
        }

        return state.rail;
    };

    const buildRailThumbnailSegments = (scope, segments) => {
        const track = railTrack(scope);

        if (!(track instanceof HTMLElement) || segments.length === 0) {
            return [];
        }

        const trackHeight = railTrackHeight(scope);
        const computedStyle = window.getComputedStyle(track);
        const thumbnailHeight = Math.max(1, readCssPixelValue(computedStyle.getPropertyValue('--recording-review-rail-thumb-height'), 84));
        const thumbnailGap = Math.max(0, readCssPixelValue(computedStyle.getPropertyValue('--recording-review-rail-thumb-gap'), 12));
        const maxThumbnailTopPx = Math.max(0, trackHeight - thumbnailHeight);
        const activeSegment = activeRailSegmentId(scope);
        const occupiedRanges = [];

        return segments
            .map((segment) => {
                const desiredTopPx = clamp(railTrackOffsetPxForMs(scope, segment.startMs, trackHeight), 0, maxThumbnailTopPx);

                return {
                    ...segment,
                    isActive: activeSegment !== '' && String(segment.id ?? '') === activeSegment,
                    thumbnailTopPx: desiredTopPx,
                };
            })
            .sort((left, right) => {
                if (left.isActive !== right.isActive) {
                    return left.isActive ? -1 : 1;
                }

                const leftDurationMs = Math.max(1, left.endMs - left.startMs);
                const rightDurationMs = Math.max(1, right.endMs - right.startMs);

                if (leftDurationMs !== rightDurationMs) {
                    return rightDurationMs - leftDurationMs;
                }

                return left.startMs - right.startMs;
            })
            .filter((segment) => {
                const topPx = Number(segment.thumbnailTopPx || 0);
                const bottomPx = topPx + thumbnailHeight;
                const overlaps = occupiedRanges.some((range) => topPx < (range.bottomPx + thumbnailGap)
                    && (bottomPx + thumbnailGap) > range.topPx);

                if (overlaps) {
                    return false;
                }

                occupiedRanges.push({
                    bottomPx,
                    topPx,
                });

                return true;
            })
            .sort((left, right) => left.startMs - right.startMs);
    };

    const createRailTickElement = (tick) => {
        const button = document.createElement('button');
        const label = document.createElement('span');
        const rawVariant = typeof tick?.labelVariant === 'string' ? tick.labelVariant : '';
        const variant = ['day', 'hour', 'minute'].includes(rawVariant)
            ? rawVariant
            : (tick?.isDayStart ? 'day' : (tick?.kind === 'primary' ? 'hour' : 'minute'));
        const fallbackLabel = String(tick?.label || '');
        const fallbackDayParts = fallbackLabel.split(/\s+/, 2);
        const fallbackHourParts = fallbackLabel.split(':', 2);
        const primaryText = variant === 'day'
            ? String(tick?.labelPrimary || fallbackDayParts[0] || '')
            : (variant === 'hour' ? String(tick?.labelPrimary || fallbackHourParts[0] || '') : '');
        const secondaryText = variant === 'day'
            ? String(tick?.labelSecondary || fallbackDayParts[1] || '')
            : (variant === 'hour'
                ? String(tick?.labelSecondary || fallbackHourParts[1] || '')
                : String(tick?.labelSecondary || fallbackLabel || ''));

        button.type = 'button';
        button.className = `recording-review-focus__rail-tick recording-review-focus__rail-tick--${tick.kind || 'primary'}${tick.isDayStart ? ' is-day-start' : ''}`;
        button.dataset.role = 'rail-tick';
        button.dataset.tickKind = String(tick.kind || 'primary');
        button.dataset.focusMs = String(tick.focusMs || 0);
        button.dataset.topPercent = String(tick.topPercent || 0);
        button.style.top = `${Number(tick.topPercent || 0)}%`;
        button.style.height = `${Number(tick.heightPercent || 0)}%`;
        button.setAttribute('aria-label', String(tick.ariaLabel || tick.label || ''));
        button.title = String(tick.ariaLabel || tick.label || '');

        label.className = `recording-review-focus__rail-tick-label recording-review-focus__rail-tick-label--${variant}`;
        label.dataset.role = 'rail-tick-label';

        if (primaryText !== '') {
            const primary = document.createElement('span');

            primary.className = 'recording-review-focus__rail-tick-part recording-review-focus__rail-tick-part--primary';
            primary.textContent = primaryText;
            label.appendChild(primary);
        }

        if (secondaryText !== '') {
            const secondary = document.createElement('span');

            secondary.className = 'recording-review-focus__rail-tick-part recording-review-focus__rail-tick-part--secondary';
            secondary.textContent = secondaryText;
            label.appendChild(secondary);
        }

        button.appendChild(label);

        return button;
    };

    const createRailSegmentElement = (scope, segment) => {
        const button = document.createElement('button');
        const bar = document.createElement('span');
        const captureMode = segment.captureMode === 'motion' ? 'motion' : 'continuous';
        const activeSegment = activeRailSegmentId(scope);
        const trackHeight = railTrackHeight(scope);
        const range = clippedSegmentRangeMs(scope, segment.startMs, segment.endMs);
        const topPx = railTrackOffsetPxForMs(scope, range.startMs, trackHeight);
        const heightPx = Math.max(1, railTrackOffsetPxForMs(scope, range.endMs, trackHeight) - topPx);
        const cameraName = timelineRail(scope)?.dataset.cameraName || 'Camera';

        button.type = 'button';
        button.className = `recording-review-focus__rail-segment recording-review-focus__rail-segment--${captureMode}${activeSegment !== '' && String(segment.id ?? '') === activeSegment ? ' is-active' : ''}`;
        button.dataset.role = 'rail-segment';
        button.dataset.recordingId = String(segment.id ?? '');
        button.dataset.focusMs = String(segment.startMs || 0);
        button.dataset.startMs = String(segment.startMs || 0);
        button.dataset.endMs = String(segment.endMs || 0);
        button.dataset.topPercent = String(segment.topPercent || 0);
        button.dataset.renderHeightPercent = String(segment.renderHeightPercent || segment.heightPercent || 0);
        button.dataset.cameraName = cameraName;
        button.dataset.scrubSpriteUrl = String(segment.scrubSpriteUrl || '');
        button.dataset.scrubFrameCount = String(segment.scrubFrameCount || 0);
        button.dataset.scrubFrameWidth = String(segment.scrubFrameWidth || 0);
        button.dataset.scrubFrameHeight = String(segment.scrubFrameHeight || 0);
        button.dataset.scrubColumns = String(segment.scrubColumns || 0);
        button.dataset.scrubRows = String(segment.scrubRows || 0);
        button.dataset.scrubFrameIntervalMs = String(segment.scrubFrameIntervalMs || 0);
        button.style.top = `${topPx}px`;
        button.style.height = `${heightPx}px`;
        button.setAttribute('aria-label', `${cameraName} ${segment.timeLabel || 'Saved clip'} ${segment.modeLabel || 'Recorded clip'}`);
        button.title = `${segment.timeLabel || 'Saved clip'} · ${segment.modeLabel || 'Recorded clip'}`;

        bar.className = 'recording-review-focus__rail-segment-bar';
        button.appendChild(bar);

        return button;
    };

    const createRailThumbnailElement = (scope, segment) => {
        const button = document.createElement('button');
        const frame = document.createElement('span');
        const captureMode = segment.captureMode === 'motion' ? 'motion' : 'continuous';
        const activeSegment = activeRailSegmentId(scope);
        const cameraName = timelineRail(scope)?.dataset.cameraName || 'Camera';

        button.type = 'button';
        button.className = `recording-review-focus__rail-thumbnail recording-review-focus__rail-thumbnail--${captureMode}${activeSegment !== '' && String(segment.id ?? '') === activeSegment ? ' is-active' : ''}`;
        button.dataset.role = 'rail-thumbnail';
        button.dataset.recordingId = String(segment.id ?? '');
        button.dataset.focusMs = String(segment.startMs || 0);
        button.dataset.thumbnailUrl = String(segment.thumbnailUrl || '');
        button.dataset.thumbnailAlt = `${cameraName} ${segment.timeLabel || 'Segment preview'} preview`;
        button.style.top = `${Number(segment.thumbnailTopPx || 0)}px`;
        button.setAttribute('aria-label', `${cameraName} ${segment.timeLabel || 'Saved clip'} preview thumbnail`);
        button.title = `${segment.timeLabel || 'Saved clip'} · ${segment.modeLabel || 'Recorded clip'}`;

        frame.className = 'recording-review-focus__rail-thumbnail-frame';
        frame.dataset.role = 'rail-thumbnail-frame';

        button.appendChild(frame);

        return button;
    };

    const renderRailWindow = (scope, force = false) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const ticksLayer = railTicksLayer(scope);
        const segmentsLayer = railSegmentsLayer(scope);
        const thumbnailsLayer = railThumbnailsLayer(scope);

        if (!(ticksLayer instanceof HTMLElement) || !(segmentsLayer instanceof HTMLElement) || !(thumbnailsLayer instanceof HTMLElement)) {
            return;
        }

        readRailBootstrapData(scope);

        const visibleWindow = railWindowForViewport(scope);
        const tickBufferMs = secondaryTickIntervalMinutes(scope) * 60000;
        const visibleTicks = state.rail.ticks.filter((tick) => {
            const focusMs = Number(tick.focusMs || 0);

            return focusMs >= (visibleWindow.startMs - tickBufferMs)
                && focusMs <= (visibleWindow.endMs + tickBufferMs);
        });
        const visibleSegments = state.rail.segments.filter((segment) => segment.endMs >= visibleWindow.startMs && segment.startMs <= visibleWindow.endMs);
        const visibleThumbnailSegments = buildRailThumbnailSegments(scope, visibleSegments);
        const renderKey = [
            state.rail.cameraId,
            activeRailSegmentId(scope),
            visibleTicks.length,
            visibleTicks[0]?.focusMs || 'none',
            visibleTicks[visibleTicks.length - 1]?.focusMs || 'none',
            visibleSegments.length,
            visibleSegments[0]?.id || 'none',
            visibleSegments[visibleSegments.length - 1]?.id || 'none',
            visibleThumbnailSegments.length,
            visibleThumbnailSegments[0]?.id || 'none',
            visibleThumbnailSegments[visibleThumbnailSegments.length - 1]?.id || 'none',
        ].join(':');

        if (!force && state.rail.renderKey === renderKey) {
            observeRailThumbnails(scope);
            updateTimelineTickLabelVisibility(scope);

            return;
        }

        state.rail.renderKey = renderKey;
        state.observerPauseDepth += 1;

        try {
            const tickFragment = document.createDocumentFragment();
            const segmentFragment = document.createDocumentFragment();
            const thumbnailFragment = document.createDocumentFragment();

            visibleTicks.forEach((tick) => {
                tickFragment.appendChild(createRailTickElement(tick));
            });

            visibleSegments.forEach((segment) => {
                segmentFragment.appendChild(createRailSegmentElement(scope, segment));
            });

            visibleThumbnailSegments.forEach((segment) => {
                thumbnailFragment.appendChild(createRailThumbnailElement(scope, segment));
            });

            ticksLayer.replaceChildren(tickFragment);
            segmentsLayer.replaceChildren(segmentFragment);
            thumbnailsLayer.replaceChildren(thumbnailFragment);
        } finally {
            state.observerPauseDepth = Math.max(0, state.observerPauseDepth - 1);
        }

        observeRailThumbnails(scope);
        updateTimelineTickLabelVisibility(scope);
    };

    const segmentPayloadForFocus = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        readRailBootstrapData(scope);

        const resolvedFocusMs = Number(focusMs || currentFocusMs(scope));
        const exact = state.rail.segments.find((segment) => {
            if (!segment || typeof segment !== 'object') {
                return false;
            }

            if (segment.endMs <= segment.startMs) {
                return segment.startMs === resolvedFocusMs;
            }

            return segment.startMs <= resolvedFocusMs && resolvedFocusMs < segment.endMs;
        });

        return exact || null;
    };

    const updateStageText = (scope, role, value) => {
        scope.querySelectorAll(`[data-role="${role}"]`).forEach((element) => {
            setText(element, value);
        });
    };

    const applyStageSegment = (scope, segment, focusMs = null) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const videoShell = scope.querySelector('[data-role="video-shell"]');
        const emptyState = scope.querySelector('[data-role="empty"]');
        const video = stageVideo(scope);
        const actions = scope.querySelector('.recording-review-focus__actions');
        const downloadLink = scope.querySelector('[data-role="download-link"]');
        const cameraName = timelineRail(scope)?.dataset.cameraName || 'Camera';

        updateStageText(scope, 'camera-label', cameraName);

        if (!(segment && typeof segment === 'object')) {
            if (videoShell instanceof HTMLElement) {
                videoShell.setAttribute('hidden', 'hidden');
            }

            if (emptyState instanceof HTMLElement) {
                emptyState.removeAttribute('hidden');
            }

            if (actions instanceof HTMLElement) {
                actions.setAttribute('hidden', 'hidden');
            }

            updateStageText(scope, 'time-label', 'No clip selected');
            updateStageText(scope, 'mode-label', 'Select a clip from the rail');
            updateStageText(scope, 'duration-label', 'No duration');
            updateStageText(scope, 'size-label', 'No file saved');
            updateStageText(scope, 'asset-status', 'No clip selected');

            if (video instanceof HTMLVideoElement) {
                video.dataset.recordingId = '';
                video.dataset.startMs = '';
                video.dataset.endMs = '';
                video.dataset.durationSeconds = '';
                video.dataset.fallbackStreamUrl = '';
                video.dataset.previewStreamUrl = '';
                video.dataset.reviewStreamUrl = '';
                video.dataset.directStreamUrl = '';
                video.dataset.previewStatusLabel = 'Assets cached';
                video.dataset.reviewStatusLabel = 'Playback stream';
                video.dataset.directStatusLabel = 'Direct stream';
                video.removeAttribute('src');

                try {
                    video.pause();
                    video.load();
                } catch (error) {
                }
            }

            return;
        }

        if (videoShell instanceof HTMLElement) {
            videoShell.removeAttribute('hidden');
        }

        if (emptyState instanceof HTMLElement) {
            emptyState.setAttribute('hidden', 'hidden');
        }

        if (actions instanceof HTMLElement) {
            actions.removeAttribute('hidden');
        }

        updateStageText(scope, 'time-label', String(segment.timeLabel || 'Saved clip'));
        updateStageText(scope, 'mode-label', String(segment.modeLabel || 'Recorded clip'));
        updateStageText(scope, 'duration-label', String(segment.durationLabel || 'No duration'));
        updateStageText(scope, 'size-label', String(segment.fileSizeLabel || 'No file saved'));

        if (downloadLink instanceof HTMLAnchorElement) {
            if (segment.downloadUrl) {
                downloadLink.href = String(segment.downloadUrl);
                downloadLink.removeAttribute('hidden');
            } else {
                downloadLink.setAttribute('hidden', 'hidden');
            }
        }

        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        setPendingStageFocusMs(video, Number(focusMs ?? currentFocusMs(scope)));

        const previewUrl = String(segment.preferredStreamUrl || '');
        const reviewUrl = String(segment.reviewStreamUrl || '');
        const directUrl = String(segment.streamUrl || '');
        const previewStatusLabel = previewUrl !== '' ? 'Assets cached' : 'Direct stream';

        video.dataset.recordingId = String(segment.id || '');
        video.dataset.startMs = String(segment.startMs || '');
        video.dataset.endMs = String(segment.endMs || '');
        video.dataset.durationSeconds = String(segment.durationSeconds || '');
        video.dataset.fallbackStreamUrl = directUrl;
        video.dataset.previewStreamUrl = previewUrl;
        video.dataset.reviewStreamUrl = reviewUrl;
        video.dataset.directStreamUrl = directUrl;
        video.dataset.previewStatusLabel = previewStatusLabel;
        video.dataset.reviewStatusLabel = 'Playback stream';
        video.dataset.directStatusLabel = 'Direct stream';

        applyStageControllerStateToVideo(scope, video);
        prewarmStageNeighbors(scope, segment);
    };

    const scheduleRailRender = (scope, force = false) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        readRailBootstrapData(scope);

        if (Number.isInteger(state.rail.renderFrame)) {
            return;
        }

        state.rail.renderFrame = window.requestAnimationFrame(() => {
            state.rail.renderFrame = null;
            renderRailWindow(scope, force);
        });
    };

    const requestRailWindow = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const component = livewireComponent(scope);
        const host = timelineRail(scope);

        if (!component || typeof component.call !== 'function' || !(host instanceof HTMLElement)) {
            return;
        }

        readRailBootstrapData(scope);

        const cameraId = Number(host.dataset.cameraId || activeCameraId(scope) || 0);

        if (!Number.isFinite(cameraId) || cameraId <= 0) {
            return;
        }

        const viewportWindow = railWindowForViewport(scope);
        const desiredRange = normalizeRailRange(
            scope,
            viewportWindow.startMs - railBufferDurationMs(scope),
            viewportWindow.endMs + railBufferDurationMs(scope),
        );

        if (railRangeCovered(state.rail.loadedRanges, desiredRange.startMs, desiredRange.endMs)) {
            return;
        }

        const chunkDuration = railChunkDurationMs(scope);
        let requestStartMs = desiredRange.startMs;
        let requestEndMs = Math.min(timelineEndMs(scope), Math.max(requestStartMs + chunkDuration, desiredRange.endMs));

        if ((requestEndMs - requestStartMs) < chunkDuration) {
            requestStartMs = Math.max(timelineStartMs(scope), requestEndMs - chunkDuration);
        }

        const requestRange = normalizeRailRange(scope, requestStartMs, requestEndMs);
        const requestKey = `${cameraId}:${requestRange.startMs}:${requestRange.endMs}`;

        if (state.rail.pendingKeys.has(requestKey)) {
            return;
        }

        state.rail.pendingKeys.add(requestKey);

        component.call('loadRailChunk', cameraId, requestRange.startMs, requestRange.endMs)
            .then((payload) => {
                const currentRoot = root();
                const currentRail = currentRoot instanceof HTMLElement ? timelineRail(currentRoot) : null;

                if (!(currentRoot instanceof HTMLElement) || !(currentRail instanceof HTMLElement)) {
                    return;
                }

                if (String(payload?.cameraId || '') !== String(currentRail.dataset.cameraId || '')) {
                    return;
                }

                storeRailSegments(Array.isArray(payload?.segments) ? payload.segments : []);
                state.rail.loadedRanges = mergeRailRanges(state.rail.loadedRanges, normalizeRailRange(
                    currentRoot,
                    Number(payload?.windowStartMs || requestRange.startMs),
                    Number(payload?.windowEndMs || requestRange.endMs),
                ));
                renderRailWindow(currentRoot, true);
            })
            .catch(() => {
            })
            .finally(() => {
                state.rail.pendingKeys.delete(requestKey);
            });
    };

    const segmentExactMatch = (segment, focusMs) => {
        const startMs = readNumber(segment, 'startMs', 0);
        const endMs = Math.max(startMs, readNumber(segment, 'endMs', startMs));

        if (endMs <= startMs) {
            return startMs === focusMs;
        }

        return startMs <= focusMs && focusMs < endMs;
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

        return null;
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

        state.scrubPreviewVisible = false;
        state.scrubPreviewFocusMs = null;

        if (preview instanceof HTMLElement) {
            preview.setAttribute('hidden', 'hidden');
            delete preview.dataset.requestId;
        }
    };

    const readCssPixelValue = (value, fallback = 0) => {
        const parsedValue = Number.parseFloat(String(value || '').trim());

        return Number.isFinite(parsedValue) ? parsedValue : fallback;
    };

    const recentScrollInteractionAt = () => Math.max(
        state.lastDragEndedAt,
        state.lastNativeScrollAt,
        state.lastScrollbarPointerAt,
        state.lastWheelZoomAt,
    );

    const shouldSuppressFocusInteraction = () => (Date.now() - recentScrollInteractionAt()) < 180;

    const isScrollbarPointer = (viewport, event) => {
        if (!(viewport instanceof HTMLElement) || !(event instanceof MouseEvent)) {
            return false;
        }

        const rect = viewport.getBoundingClientRect();
        const verticalScrollbarWidth = Math.max(0, rect.width - viewport.clientWidth);
        const horizontalScrollbarHeight = Math.max(0, rect.height - viewport.clientHeight);

        return (verticalScrollbarWidth > 0 && event.clientX >= (rect.right - verticalScrollbarWidth))
            || (horizontalScrollbarHeight > 0 && event.clientY >= (rect.bottom - horizontalScrollbarHeight));
    };

    const layoutRailThumbnails = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const track = railTrack(scope);

        if (!(track instanceof HTMLElement)) {
            return;
        }

        const thumbnails = Array.from(scope.querySelectorAll('[data-role="rail-thumbnail"]'))
            .filter((element) => element instanceof HTMLElement)
            .sort((left, right) => readNumber(left, 'focusMs', 0) - readNumber(right, 'focusMs', 0));

        if (thumbnails.length === 0) {
            return;
        }

        const segmentsByRecordingId = new Map(railSegments(scope)
            .map((segment) => [String(segment.dataset.recordingId || ''), segment]));
        const trackHeight = railTrackHeight(scope);
        const computedStyle = window.getComputedStyle(track);
        const thumbnailHeight = Math.max(
            1,
            readCssPixelValue(computedStyle.getPropertyValue('--recording-review-rail-thumb-height'), thumbnails[0].offsetHeight || 84),
        );
        const thumbnailGap = Math.max(0, readCssPixelValue(computedStyle.getPropertyValue('--recording-review-rail-thumb-gap'), 12));
        const maxThumbnailTopPx = Math.max(0, trackHeight - thumbnailHeight);
        const occupiedRanges = [];
        const candidates = thumbnails.map((thumbnail) => {
            const recordingId = String(thumbnail.dataset.recordingId || '');
            const segment = segmentsByRecordingId.get(recordingId) || null;
            const fallbackTopPx = clamp(
                railTrackOffsetPxForMs(scope, readNumber(thumbnail, 'focusMs', timelineStartMs(scope)), trackHeight),
                0,
                maxThumbnailTopPx,
            );
            const desiredTopPx = clamp(
                segment instanceof HTMLElement
                    ? railTrackOffsetPxForMs(scope, readNumber(segment, 'startMs', timelineStartMs(scope)), trackHeight)
                    : fallbackTopPx,
                0,
                maxThumbnailTopPx,
            );

            return {
                durationMs: segment instanceof HTMLElement
                    ? Math.max(1, readNumber(segment, 'endMs', 0) - readNumber(segment, 'startMs', 0))
                    : 0,
                isActive: thumbnail.classList.contains('is-active') || (segment instanceof HTMLElement && segment.classList.contains('is-active')),
                thumbnail,
                topPx: desiredTopPx,
            };
        }).sort((left, right) => {
            if (left.isActive !== right.isActive) {
                return left.isActive ? -1 : 1;
            }

            if (left.durationMs !== right.durationMs) {
                return right.durationMs - left.durationMs;
            }

            return readNumber(left.thumbnail, 'focusMs', 0) - readNumber(right.thumbnail, 'focusMs', 0);
        });

        candidates.forEach(({ thumbnail, topPx }) => {
            const bottomPx = topPx + thumbnailHeight;
            const overlapsExistingThumbnail = occupiedRanges.some((range) => topPx < (range.bottomPx + thumbnailGap)
                && (bottomPx + thumbnailGap) > range.topPx);

            if (overlapsExistingThumbnail) {
                thumbnail.hidden = true;

                return;
            }

            thumbnail.hidden = false;
            thumbnail.style.top = `${topPx}px`;
            occupiedRanges.push({
                bottomPx,
                topPx,
            });
        });
    };

    const layoutRailSegments = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        railSegments(scope).forEach((segment) => {
            const trackHeight = railTrackHeight(scope);
            const range = clippedSegmentRangeMs(
                scope,
                readNumber(segment, 'startMs', timelineStartMs(scope)),
                readNumber(segment, 'endMs', timelineStartMs(scope) + 1000),
            );
            const topPx = railTrackOffsetPxForMs(scope, range.startMs, trackHeight);
            const heightPx = Math.max(1, railTrackOffsetPxForMs(scope, range.endMs, trackHeight) - topPx);

            segment.style.top = `${topPx}px`;
            segment.style.height = `${heightPx}px`;
        });
    };

    const applyTimelineScale = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        readRailBootstrapData(scope);

        const track = railTrack(scope);
        const viewport = railViewport(scope);

        if (!(track instanceof HTMLElement)) {
            return;
        }

        const zoomScale = setCurrentZoomScale(scope, currentZoomScale(scope));
        const nextTrackHeight = scaledTrackHeight(scope, zoomScale);

        track.style.height = `${nextTrackHeight}px`;
        track.style.setProperty('--recording-review-zoom-scale', String(zoomScale));

        if (viewport instanceof HTMLElement) {
            const restoredScrollTop = state.pendingViewportRestore && Number.isFinite(state.viewportScrollTop)
                ? Number(state.viewportScrollTop)
                : viewport.scrollTop;

            viewport.scrollTop = clamp(restoredScrollTop, 0, Math.max(0, nextTrackHeight - viewport.clientHeight));
            state.viewportScrollTop = viewport.scrollTop;
            state.pendingViewportRestore = false;
        }

        updateFocusCursor(scope, currentFocusMs(scope));
        renderRailWindow(scope, true);
        requestRailWindow(scope);
        updateTimelineTickLabelVisibility(scope);
        updateZoomUi(scope, zoomScale);
    };

    const handleViewportScroll = (event) => {
        const viewport = event.currentTarget;
        const scope = rootFor(viewport);

        if (!(viewport instanceof HTMLElement) || !(scope instanceof HTMLElement)) {
            return;
        }

        state.lastNativeScrollAt = Date.now();
        state.viewportScrollTop = viewport.scrollTop;
        scheduleRailRender(scope);
        requestRailWindow(scope);
    };

    const handleViewportWheel = (event) => {
        const viewport = event.currentTarget;
        const scope = rootFor(viewport);

        if (!(viewport instanceof HTMLElement) || !(scope instanceof HTMLElement) || event.deltaY === 0 || state.drag) {
            return;
        }

        const zoomScale = currentZoomScale(scope);
        const nextZoomScale = clamp(
            event.deltaY < 0
                ? zoomScale * zoomStepFactor(scope)
                : zoomScale / zoomStepFactor(scope),
            minimumZoomScale(scope),
            maximumZoomScale(scope),
        );

        state.lastWheelZoomAt = Date.now();

        if (Math.abs(nextZoomScale - zoomScale) < 0.001) {
            event.preventDefault();
            event.stopPropagation();

            return;
        }

        const rect = viewport.getBoundingClientRect();
        const anchorOffsetY = clamp(event.clientY - rect.top, 0, viewport.clientHeight);
        const anchorMs = focusFromPointer(scope, event.clientY) ?? currentFocusMs(scope);

        const zoomDidChange = applyZoomScale(scope, nextZoomScale, {
            anchorMs,
            anchorOffsetY,
        });

        if (zoomDidChange) {
            syncZoomScaleWithServer(scope, nextZoomScale);
        }

        event.preventDefault();
        event.stopPropagation();
    };

    const cleanupViewportBinding = () => {
        if (typeof state.boundViewportCleanup === 'function') {
            state.boundViewportCleanup();
        }

        state.boundViewport = null;
        state.boundViewportCleanup = null;
    };

    const bindRailViewport = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            cleanupViewportBinding();

            return;
        }

        const viewport = railViewport(scope);

        if (!(viewport instanceof HTMLElement)) {
            cleanupViewportBinding();

            return;
        }

        if (state.boundViewport === viewport) {
            return;
        }

        if (state.boundViewport instanceof HTMLElement) {
            state.viewportScrollTop = state.boundViewport.scrollTop;
        }

        cleanupViewportBinding();
        state.pendingViewportRestore = Number.isFinite(state.viewportScrollTop);

        viewport.addEventListener('scroll', handleViewportScroll, { passive: true });
        viewport.addEventListener('wheel', handleViewportWheel, { passive: false });

        state.boundViewport = viewport;
        state.boundViewportCleanup = () => {
            viewport.removeEventListener('scroll', handleViewportScroll);
            viewport.removeEventListener('wheel', handleViewportWheel);
        };
    };

    const cleanupResizeObserver = () => {
        if (typeof ResizeObserver !== 'undefined' && state.resizeObserver instanceof ResizeObserver) {
            state.resizeObserver.disconnect();
        }

        state.resizeObserver = null;
        state.resizeObserverScope = null;
        state.resizeObservedElements = [];
    };

    const bindResizeObserver = (scope) => {
        if (!(scope instanceof HTMLElement) || typeof ResizeObserver === 'undefined') {
            cleanupResizeObserver();

            return;
        }

        const viewport = railViewport(scope);
        const track = railTrack(scope);
        const observedElements = [viewport, track].filter((element) => element instanceof HTMLElement);

        if (observedElements.length === 0) {
            cleanupResizeObserver();

            return;
        }

        const isSameScope = state.resizeObserverScope === scope;
        const hasSameElements = isSameScope
            && state.resizeObservedElements.length === observedElements.length
            && state.resizeObservedElements.every((element, index) => element === observedElements[index]);

        if (hasSameElements) {
            return;
        }

        cleanupResizeObserver();

        state.resizeObserverScope = scope;
        state.resizeObservedElements = observedElements;
        state.resizeObserver = new ResizeObserver(() => {
            const currentRoot = root();

            if (!(currentRoot instanceof HTMLElement)) {
                return;
            }

            updateVisibleRange(currentRoot);
            scheduleRailRender(currentRoot, true);
            requestRailWindow(currentRoot);
            updateTimelineTickLabelVisibility(currentRoot);
        });

        observedElements.forEach((element) => {
            state.resizeObserver?.observe(element);
        });
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
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['data-active-camera-id'],
            });
        }
    };

    const observerRefreshSelectors = [
        '[data-role="timeline-stage"]',
        '[data-role="video"]',
        '[data-role="video-shell"]',
        '[data-role="empty"]',
        '[data-role="download-link"]',
        '[data-role="rail-viewport"]',
        '[data-role="rail-track"]',
        '[data-role="rail-segments"]',
        '[data-role="rail-segment"]',
        '[data-role="rail-thumbnail"]',
        '[data-role="camera-switch"]',
    ].join(', ');

    const mutationNodeNeedsRefresh = (node) => {
        if (!(node instanceof Element)) {
            return false;
        }

        return node.matches(observerRefreshSelectors) || node.querySelector(observerRefreshSelectors) !== null;
    };

    const mutationsNeedRefresh = (mutations, scope) => mutations.some((mutation) => {
        if (mutation.type === 'attributes') {
            return mutation.target === scope && mutation.attributeName === 'data-active-camera-id';
        }

        if (mutation.type !== 'childList') {
            return false;
        }

        return Array.from(mutation.addedNodes).some(mutationNodeNeedsRefresh)
            || Array.from(mutation.removedNodes).some(mutationNodeNeedsRefresh);
    });

    const refreshScope = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const nextActiveCameraId = activeCameraId(scope);
        const cameraDidChange = state.activeCameraId !== null && state.activeCameraId !== nextActiveCameraId;

        withObserverPaused(() => {
            if (cameraDidChange) {
                state.pendingViewportRestore = false;
                state.viewportScrollTop = null;
                resetStagePrewarm();
            }

            bindRailViewport(scope);
            bindResizeObserver(scope);
            readRailBootstrapData(scope);
            applyTimelineScale(scope);
            bindStageVideo(scope);
            bindStageAudio(scope);
            syncStagePlaybackState(scope);
            syncStageAudioState(scope);
            seekStagePlaybackToFocus(scope, currentFocusMs(scope));
            updateStageVolumeUi(scope);
            updateFocusCursor(scope, currentFocusMs(scope));
            warmScrubSprites(scope);
            prewarmStageNeighbors(scope, segmentPayloadForFocus(scope, currentFocusMs(scope)));

            if (cameraDidChange) {
                centerViewportOnFocus(scope, currentFocusMs(scope));
            }

            if (state.scrubPreviewVisible) {
                const previewFocusMs = clamp(
                    Number(state.scrubPreviewFocusMs ?? currentFocusMs(scope)),
                    timelineStartMs(scope),
                    timelineMaximumFocusMs(scope),
                );

                showScrubPreview(scope, segmentForFocus(scope, previewFocusMs), previewFocusMs);
            } else {
                hideScrubPreview(scope);
            }

            state.activeCameraId = nextActiveCameraId;
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
        const frameIndex = Math.min(frameCount - 1, Math.max(0, Math.floor(offsetMs / frameIntervalMs)));
        const offsetX = (frameIndex % columns) * frameWidth;
        const offsetY = Math.floor(frameIndex / columns) * frameHeight;

        const frameKey = `${spriteUrl}:${frameIndex}`;

        state.scrubPreviewRequestId += 1;

        const requestId = String(state.scrubPreviewRequestId);
        const activeLayerIndex = activeScrubPreviewLayerIndex(frame);
        const activeLayer = layers[activeLayerIndex] || layers[0] || null;
        const hasActiveSprite = activeLayer instanceof HTMLElement && (activeLayer.dataset.spriteUrl || '') !== '';
        const activeSpriteUrl = activeLayer instanceof HTMLElement ? (activeLayer.dataset.spriteUrl || '') : '';

        state.scrubPreviewVisible = true;
        state.scrubPreviewFocusMs = focusMs;

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
        state.viewportScrollTop = viewport.scrollTop;
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

    const cancelPendingZoomSync = () => {
        if (state.zoomSyncTimer !== null) {
            window.clearTimeout(state.zoomSyncTimer);
            state.zoomSyncTimer = null;
        }
    };

    const syncZoomScaleWithServer = (scope, zoomScale, immediate = false) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const component = livewireComponent(scope);

        if (!component || typeof component.call !== 'function') {
            return;
        }

        const nextZoomScale = normalizedZoomScale(scope, zoomScale);

        if (!immediate) {
            cancelPendingZoomSync();
            state.zoomSyncTimer = window.setTimeout(() => {
                state.zoomSyncTimer = null;
                syncZoomScaleWithServer(scope, nextZoomScale, true);
            }, 120);

            return;
        }

        cancelPendingZoomSync();
        component.call('syncZoomScale', nextZoomScale);
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
        const viewport = railViewport(scope);
        const component = livewireComponent(scope);

        if (viewport instanceof HTMLElement) {
            state.viewportScrollTop = viewport.scrollTop;
            state.pendingViewportRestore = true;
        }

        if (component && typeof component.call === 'function') {
            cancelPendingZoomSync();
            const request = component.call('selectFocus', Math.round(focusMs), normalizedZoomScale(scope));

            if (request && typeof request.then === 'function') {
                request.then(() => {
                    const currentRoot = root();

                    if (currentRoot instanceof HTMLElement) {
                        seekStagePlaybackToFocus(currentRoot, focusMs);
                    }
                }).catch(() => {
                });
            }
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
            cleanupAudioBinding();
            cleanupVideoBinding();

            return;
        }

        const video = stageVideo(scope);

        if (!(video instanceof HTMLVideoElement)) {
            cleanupAudioBinding();
            cleanupVideoBinding();

            return;
        }

        if (state.boundVideo === video) {
            applyStageControllerStateToVideo(scope, video);

            return;
        }

        cleanupVideoBinding();

        const seekToFocus = () => {
            seekStageVideoToFocus(scope, video);
            applyStageControllerStateToVideo(scope, video);
        };

        const handleError = () => {
            const fallbackUrl = video.dataset.fallbackStreamUrl || '';

            if (fallbackUrl === '' || video.currentSrc === fallbackUrl) {
                return;
            }

            const resumeAt = Number(video.currentTime || 0);

            video.dataset.pendingSourceLoad = 'true';
            video.src = fallbackUrl;
            video.load();

            const handleFallbackLoaded = () => {
                video.removeEventListener('loadedmetadata', handleFallbackLoaded);
                clearPendingStageSourceLoad(video);

                try {
                    video.currentTime = resumeAt;
                } catch (error) {
                }

                applyStageControllerStateToVideo(scope, video);
            };

            video.addEventListener('loadedmetadata', handleFallbackLoaded);

            const assetStatus = scope.querySelector('[data-role="asset-status"]');
            setText(assetStatus, 'Fallback stream');
        };

        const handleEnded = () => {
            const nextSegment = nextSegmentForVideo(scope, video);

            if (!(nextSegment instanceof HTMLElement)) {
                syncStageControllerFromVideo(scope, video);

                return;
            }

            const playerState = stageControllerSnapshot(scope, video);

            state.stage = {
                ...playerState,
                initialized: true,
                isPlaying: true,
            };

            const nextFocus = readNumber(nextSegment, 'focusMs', readNumber(nextSegment, 'startMs', currentFocusMs(scope)));

            hideScrubPreview(scope);
            updateLocalFocus(scope, nextFocus);
            centerViewportOnFocus(scope, nextFocus);
            commitFocus(scope, nextFocus);
        };

        const handlePlay = () => {
            const playerState = syncStageControllerFromVideo(scope, video);
            syncCompanionAudioTime(video, stageAudio(scope), true);
            applyStageCompanionAudioState(scope, video, playerState, stageAudio(scope));
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handlePause = () => {
            const playerState = syncStageControllerFromVideo(scope, video);
            applyStageCompanionAudioState(scope, video, playerState, stageAudio(scope));
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleVolumeChange = () => {
            syncStageControllerFromVideo(scope, video);
        };

        const handleTimeUpdate = () => {
            if (state.drag) {
                return;
            }

            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleSeeked = () => {
            syncCompanionAudioTime(video, stageAudio(scope), true);
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
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
        video.addEventListener('volumechange', handleVolumeChange);
        video.addEventListener('timeupdate', handleTimeUpdate);
        video.addEventListener('seeked', handleSeeked);

        state.boundVideo = video;
        state.boundVideoCleanup = () => {
            video.removeEventListener('error', handleError);
            video.removeEventListener('ended', handleEnded);
            video.removeEventListener('play', handlePlay);
            video.removeEventListener('pause', handlePause);
            video.removeEventListener('volumechange', handleVolumeChange);
            video.removeEventListener('timeupdate', handleTimeUpdate);
            video.removeEventListener('seeked', handleSeeked);

            // Livewire can replace the stage video node during mute state updates.
            // Explicitly stop the old element so detached audio cannot continue playing.
            video.defaultMuted = true;
            video.muted = true;
            video.volume = 0;

            try {
                video.pause();
            } catch (error) {
            }

            video.removeAttribute('src');

            try {
                video.load();
            } catch (error) {
            }
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

        if (isScrollbarPointer(viewport, event)) {
            state.lastScrollbarPointerAt = Date.now();

            return;
        }

        const anchorOffsetY = viewportOffsetFromClientY(viewport, event.clientY) ?? (viewport.clientHeight / 2);
        const nextFocus = focusFromPointer(scope, event.clientY);

        state.drag = {
            anchorOffsetY,
            currentOffsetY: anchorOffsetY,
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
        state.drag.currentOffsetY = viewportOffsetFromClientY(state.drag.viewport, event.clientY) ?? state.drag.currentOffsetY;
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

        const releaseOffsetY = viewportOffsetFromClientY(viewport, event.clientY)
            ?? state.drag.currentOffsetY
            ?? state.drag.anchorOffsetY;

        updateLocalFocus(scope, nextFocus);
        centerViewportOnFocus(scope, nextFocus, releaseOffsetY);
        hideScrubPreview(scope);
        applyStageSegment(scope, segmentPayloadForFocus(scope, nextFocus), nextFocus);
        seekStagePlaybackToFocus(scope, nextFocus);
        state.drag = null;
        commitFocus(scope, nextFocus);
    };

    const handleInput = (event) => {
        const target = event.target;
        const scope = rootFor(target);

        if (!(scope instanceof HTMLElement) || !(target instanceof HTMLInputElement) || target.dataset.role !== 'audio-volume-slider') {
            return;
        }

        const nextVolume = clampVolume(Number(target.value) / 100);

        setStageControllerState(scope, {
            volume: nextVolume,
            isMuted: nextVolume <= 0 ? true : false,
        });
        applyStageControllerStateToVideo(scope, stageVideo(scope));
    };

    const handleClick = (event) => {
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

        const zoomInButton = event.target.closest('[data-role="zoom-in"]');
        const zoomOutButton = event.target.closest('[data-role="zoom-out"]');
        const zoomResetButton = event.target.closest('[data-role="zoom-reset"]');

        if (zoomInButton instanceof HTMLButtonElement || zoomOutButton instanceof HTMLButtonElement || zoomResetButton instanceof HTMLButtonElement) {
            const viewport = railViewport(scope);
            const anchorOffsetY = viewport instanceof HTMLElement ? viewport.clientHeight / 2 : 0;
            const nextZoomScale = zoomInButton instanceof HTMLButtonElement
                ? currentZoomScale(scope) * zoomStepFactor(scope)
                : zoomOutButton instanceof HTMLButtonElement
                    ? currentZoomScale(scope) / zoomStepFactor(scope)
                    : minimumZoomScale(scope);

            const zoomDidChange = applyZoomScale(scope, nextZoomScale, {
                anchorMs: focusFromViewportOffset(scope, anchorOffsetY) ?? currentFocusMs(scope),
                anchorOffsetY,
            });

            if (zoomDidChange) {
                syncZoomScaleWithServer(scope, nextZoomScale);
            }

            event.preventDefault();

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
            if (shouldSuppressFocusInteraction()) {
                event.preventDefault();
                event.stopPropagation();

                return;
            }

            const nextFocus = readNumber(focusTarget, 'focusMs', currentFocusMs(scope));

            updateLocalFocus(scope, nextFocus);
            hideScrubPreview(scope);
            applyStageSegment(scope, segmentPayloadForFocus(scope, nextFocus), nextFocus);
            seekStagePlaybackToFocus(scope, nextFocus);
            commitFocus(scope, nextFocus);
            event.preventDefault();
        }
    };

    const startObserver = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.observerScope = scope;

        state.observer = new MutationObserver((mutations) => {
            const currentRoot = root();

            if (!(currentRoot instanceof HTMLElement) || state.observerPauseDepth > 0 || state.drag) {
                return;
            }

            if (!mutationsNeedRefresh(mutations, currentRoot)) {
                return;
            }

            refreshScope(currentRoot);
        });

        state.observer.observe(scope, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['data-active-camera-id'],
        });
    };

    const destroy = () => {
        state.cleanupFns.forEach((cleanup) => cleanup());
        state.cleanupFns = [];

        cleanupViewportBinding();
        cleanupResizeObserver();

        if (state.observer) {
            state.observer.disconnect();
            state.observer = null;
        }

        state.observerScope = null;

        cleanupAudioBinding();
        cleanupVideoBinding();
        resetRailState();
        state.drag = null;
        state.lastNativeScrollAt = 0;
        state.lastScrollbarPointerAt = 0;
        state.lastWheelZoomAt = 0;
        state.pendingViewportRestore = false;
        state.activeCameraId = null;
        state.scrubPreviewFocusMs = null;
        state.scrubPreviewVisible = false;
        state.viewportScrollTop = null;
        state.zoomScale = null;
        state.stage = defaultStageControllerState();
        resetStagePrewarm();
        cancelPendingZoomSync();
    };

    const bootstrap = () => {
        destroy();

        const scope = root();

        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.activeCameraId = activeCameraId(scope);
        updateLocalFocus(scope, currentFocusMs(scope));
        bindRailViewport(scope);
        applyTimelineScale(scope);
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
        document.addEventListener('input', handleInput, true);
        document.addEventListener('click', handleClick, true);

        state.cleanupFns.push(() => document.removeEventListener('pointerdown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('pointermove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('pointerup', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('pointercancel', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('mousedown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('mousemove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('mouseup', clearDrag));
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