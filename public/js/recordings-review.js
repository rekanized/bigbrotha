(() => {
    if (window.BigBrothaRecordingReviewModule) {
        window.BigBrothaRecordingReviewModule.bootstrap();

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
        queuedRequest: null,
        renderFrame: null,
        thumbnailLayout: null,
        statusTimer: null,
        statusShownAt: 0,
        requestController: null,
        requestInFlight: false,
        requestTimer: null,
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
        bootstrappedRoot: null,
        boundAudio: null,
        boundAudioCleanup: null,
        boundVideo: null,
        boundVideoCleanup: null,
        boundViewport: null,
        boundViewportCleanup: null,
        cleanupFns: [],
        drag: null,
        clipScrub: null,
        lastDragEndedAt: 0,
        lastNativeScrollAt: 0,
        lastPointerEventAt: 0,
        lastScrollbarPointerAt: 0,
        lastWheelZoomAt: 0,
        railRequestId: 0,
        resizeObservedElements: [],
        resizeObserver: null,
        resizeObserverScope: null,
        scrubPreviewFocusMs: null,
        scrubPreviewRequestId: 0,
        scrubSpriteCache: new Map(),
        scrubSpriteFailures: new Map(),
        scrubSpritePending: new Map(),
        scrubPreviewVisible: false,
        rail: defaultRailState(),
        stage: defaultStageControllerState(),
        stagePrewarm: defaultStagePrewarmState(),
        stageAudioLastSyncAt: 0,
        stageSegmentRequestController: null,
        stageSegmentRequestId: 0,
        stageSelectionId: 0,
        stageSourceLoadTimer: null,
        stageSourceRequestId: 0,
        pendingViewportRestore: false,
        viewportScrollTop: null,
        zoomScale: null,
    };

    const maximumRememberedSprites = 64;
    const spriteFailureRetryDelayMs = 60_000;

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
    const pendingStageReadyHandlers = new WeakMap();

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
    const railShell = (scope) => scope?.querySelector('[data-role="rail-shell"]') || null;
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
    const cameraSwitches = (scope) => scope instanceof HTMLElement
        ? Array.from(scope.querySelectorAll('[data-role="camera-switch"]')).filter((element) => element instanceof HTMLButtonElement)
        : [];
    const cameraSwitchForId = (scope, cameraId) => cameraSwitches(scope).find((element) => String(element.dataset.cameraId || '') === String(cameraId || '')) || null;
    const activeCameraSwitch = (scope) => cameraSwitchForId(scope, activeCameraId(scope));
    const timelineRailDataUrl = (scope, cameraId = null) => {
        const explicitCameraId = cameraId === null ? activeCameraId(scope) : String(cameraId || '');
        const switchButton = cameraSwitchForId(scope, explicitCameraId);

        if (switchButton instanceof HTMLButtonElement && String(switchButton.dataset.railUrl || '').trim() !== '') {
            return String(switchButton.dataset.railUrl || '').trim();
        }

        const host = timelineRail(scope);

        return host instanceof HTMLElement ? String(host.dataset.railUrl || '').trim() : '';
    };
    const timelineStageDataUrl = (scope, cameraId = null) => {
        const explicitCameraId = cameraId === null ? activeCameraId(scope) : String(cameraId || '');
        const switchButton = cameraSwitchForId(scope, explicitCameraId);

        if (switchButton instanceof HTMLButtonElement && String(switchButton.dataset.stageUrl || '').trim() !== '') {
            return String(switchButton.dataset.stageUrl || '').trim();
        }

        const host = timelineRail(scope);

        return host instanceof HTMLElement ? String(host.dataset.stageUrl || '').trim() : '';
    };
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
        if (element instanceof HTMLElement && element.textContent !== String(value)) {
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
        scope.querySelectorAll('[data-role="zoom-input"]').forEach(input => { input.value = normalizedZoomScale.toFixed(2); });
    };

    const persistReviewInUrl = (scope) => {
        const url = new URL(window.location.href);
        url.searchParams.set('focus_at', new Date(currentFocusMs(scope)).toISOString());
        url.searchParams.set('active_camera_id', activeCameraId(scope));
        for (const name of ['date_from', 'date_to']) {
            const value = scope.querySelector(`.recording-review__time-jump [name="${name}"]`)?.value;
            if (value) url.searchParams.set(name, value);
        }
        window.history.replaceState(window.history.state, '', url);
        document.querySelectorAll('[data-role="active-camera-input"]').forEach(input => { input.value = activeCameraId(scope); });
    };

    const setPlaybackNotice = (scope, message = '', status = 'loading') => {
        const notice = scope.querySelector('[data-role="playback-notice"]');
        if (!(notice instanceof HTMLElement)) return;
        notice.hidden = message === '';
        notice.dataset.state = status;
        setText(notice.querySelector('[data-role="playback-message"]'), message);
        const retry = notice.querySelector('[data-role="playback-retry"]');
        if (retry) retry.hidden = status !== 'error';
    };

    const clipTimeLabel = (seconds) => {
        const total = Math.max(0, Math.floor(seconds));
        const minutes = Math.floor(total / 60);
        return `${minutes}:${String(total % 60).padStart(2, '0')}`;
    };

    const clipSeekBounds = (scope, video = stageVideo(scope)) => {
        if (!video?.dataset.recordingId) return null;
        const start = readNumber(video, 'startMs');
        const end = Math.min(readNumber(video, 'endMs'), timelineEndMs(scope));
        const mediaDuration = !stageSourceIsLoading(video) && Number.isFinite(video.duration) && video.duration > 0
            ? video.duration : readNumber(video, 'durationSeconds', (end - start) / 1000);
        const min = Math.max(0, (timelineStartMs(scope) - start) / 1000);
        const duration = Math.min(mediaDuration, (end - start) / 1000);
        // Stay inside this clip, including when its end adjoins another clip.
        const max = Math.max(min, Math.min(Math.floor((duration - 0.1) * 10) / 10, (timelineMaximumFocusMs(scope) - start) / 1000));
        return { start, min, max, duration };
    };

    const updateClipProgress = (scope) => {
        const slider = scope.querySelector('[data-role="clip-seek"]');
        if (!(slider instanceof HTMLInputElement)) return;
        const video = stageVideo(scope);
        const bounds = clipSeekBounds(scope, video);
        const available = bounds && bounds.max > bounds.min;
        slider.disabled = !available;
        const detail = scope.querySelector('[data-role="clip-detail"]');
        if (detail) detail.disabled = !available;
        if (!available) return;
        const elapsed = clamp((currentFocusMs(scope) - bounds.start) / 1000, bounds.min, bounds.max);
        slider.min = String(bounds.min);
        slider.max = String(bounds.max);
        if (!state.clipScrub) slider.value = String(elapsed);
        slider.style.setProperty('--clip-progress', `${((elapsed - bounds.min) / (bounds.max - bounds.min)) * 100}%`);
        slider.setAttribute('aria-valuetext', `${clipTimeLabel(elapsed)} of ${clipTimeLabel(bounds.duration)}`);
        setText(scope.querySelector('[data-role="clip-elapsed"]'), clipTimeLabel(elapsed));
        setText(scope.querySelector('[data-role="clip-duration"]'), clipTimeLabel(bounds.duration));
    };

    const beginClipScrub = (scope) => {
        const video = stageVideo(scope);
        if (state.clipScrub || !clipSeekBounds(scope, video)) return;
        state.clipScrub = { scope, video, recordingId: video.dataset.recordingId, wasPlaying: stageControllerSnapshot(scope).isPlaying };
        video.pause();
        stageAudio(scope)?.pause();
    };

    const finishClipScrub = () => {
        const scrub = state.clipScrub;
        if (!scrub) return;
        state.clipScrub = null;
        if (root() !== scrub.scope || scrub.video.dataset.recordingId !== scrub.recordingId) return;
        setStageControllerState(scrub.scope, { isPlaying: scrub.wasPlaying });
        applyStageControllerStateToVideo(scrub.scope, scrub.video);
        centerViewportOnFocus(scrub.scope, currentFocusMs(scrub.scope));
        commitFocus(scrub.scope, currentFocusMs(scrub.scope));
    };

    const seekWithinClip = (scope, seconds) => {
        const video = stageVideo(scope);
        const bounds = clipSeekBounds(scope, video);
        if (!bounds) return;
        const elapsed = clamp(seconds, bounds.min, bounds.max);
        const focus = bounds.start + elapsed * 1000;
        if (stageSourceIsLoading(video) || video.readyState < 1) setPendingStageFocusMs(video, focus);
        else video.currentTime = elapsed;
        updateLocalFocus(scope, focus);
        syncCompanionAudioTime(video, stageAudio(scope), true);
    };

    const selectFocus = (scope, focusMs) => {
        const nextFocus = clamp(focusMs, timelineStartMs(scope), timelineMaximumFocusMs(scope));
        updateLocalFocus(scope, nextFocus);
        centerViewportOnFocus(scope, nextFocus);
        hideScrubPreview(scope);
        void syncStageSegmentToFocus(scope, nextFocus, { fetchIfMissing: true });
        commitFocus(scope, nextFocus);
    };

    const navigateClip = async (scope, direction) => {
        const video = stageVideo(scope);
        const referenceMs = video?.dataset.recordingId ? readNumber(video, 'startMs', currentFocusMs(scope)) : currentFocusMs(scope);
        const selectionId = ++state.stageSelectionId;
        const cameraId = activeCameraId(scope);
        setPlaybackNotice(scope, 'Finding '+(direction === 'previous' ? 'previous' : 'next')+' clip…');
        const segment = await requestStageSegmentForFocus(scope, referenceMs, direction);
        if (root() !== scope || selectionId !== state.stageSelectionId || cameraId !== activeCameraId(scope)) return;
        if (segment === undefined) return;
        if (!segment) {
            setPlaybackNotice(scope, 'No '+(direction === 'previous' ? 'earlier' : 'later')+' clip in this date range.', 'empty');
            return;
        }
        selectFocus(scope, segment.startMs);
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
        applyTimelineScale(scope, { anchorMs, anchorOffsetY });
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

    const stageSourceNode = (video) => video instanceof HTMLVideoElement
        ? video.querySelector('[data-role="video-source"]')
        : null;

    const stageReviewStreamUrl = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.reviewStreamUrl || '').trim()
        : '';

    const stageDirectStreamUrl = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.directStreamUrl || video.dataset.fallbackStreamUrl || '').trim()
        : '';

    const stageReviewAssetStatusLabel = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.reviewStatusLabel || 'Playback stream').trim()
        : 'Playback stream';

    const stageDirectAssetStatusLabel = (video) => video instanceof HTMLVideoElement
        ? String(video.dataset.directStatusLabel || 'Direct stream').trim()
        : 'Direct stream';

    const stageSourceCandidates = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return [];
        }

        return [
            stageReviewStreamUrl(video),
            stageDirectStreamUrl(video),
        ].filter((candidate, index, candidates) => candidate !== '' && candidates.indexOf(candidate) === index);
    };

    const nextStageFallbackUrl = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return '';
        }

        const candidates = stageSourceCandidates(video);

        if (candidates.length === 0) {
            return '';
        }

        const sourceNode = stageSourceNode(video);
        const currentSource = normalizeMediaUrl(
            video.currentSrc
            || (sourceNode instanceof HTMLSourceElement ? (sourceNode.getAttribute('src') || '') : '')
            || video.getAttribute('src')
            || '',
        );

        if (currentSource === '') {
            return candidates[0] || '';
        }

        const currentIndex = candidates.findIndex((candidate) => normalizeMediaUrl(candidate) === currentSource);

        if (currentIndex < 0) {
            return candidates[0] || '';
        }

        return candidates.slice(currentIndex + 1).find((candidate) => normalizeMediaUrl(candidate) !== currentSource) || '';
    };

    const desiredStageStreamUrl = (video) => {
        const reviewUrl = stageReviewStreamUrl(video);
        const directUrl = stageDirectStreamUrl(video);

        return reviewUrl || directUrl;
    };

    const desiredStageAudioUrl = (video) => {
        const reviewUrl = stageReviewStreamUrl(video);
        const directUrl = stageDirectStreamUrl(video);

        return reviewUrl || directUrl;
    };

    const stageUsesCompanionAudio = () => false;

    const stageAssetStatusLabelForUrl = (video, sourceUrl) => {
        const normalizedSourceUrl = normalizeMediaUrl(sourceUrl);
        const normalizedReviewUrl = normalizeMediaUrl(stageReviewStreamUrl(video));
        const normalizedDirectUrl = normalizeMediaUrl(stageDirectStreamUrl(video));

        if (normalizedSourceUrl !== '' && normalizedSourceUrl === normalizedReviewUrl) {
            return stageReviewAssetStatusLabel(video);
        }

        if (normalizedSourceUrl !== '' && normalizedSourceUrl === normalizedDirectUrl) {
            return stageDirectAssetStatusLabel(video);
        }

        if (normalizedReviewUrl !== '') {
            return stageReviewAssetStatusLabel(video);
        }

        return normalizedDirectUrl !== '' ? stageDirectAssetStatusLabel(video) : stageReviewAssetStatusLabel(video);
    };

    const updateStageAssetStatus = (scope, video, sourceUrl = null) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const assetStatus = scope.querySelector('[data-role="asset-status"]');
        const sourceNode = stageSourceNode(video);
        const resolvedSourceUrl = sourceUrl
            ?? (video instanceof HTMLVideoElement
                ? (video.currentSrc
                    || (sourceNode instanceof HTMLSourceElement ? (sourceNode.getAttribute('src') || '') : '')
                    || video.getAttribute('src')
                    || '')
                : '');

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

    const detachPendingStageReadyHandler = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        const handler = pendingStageReadyHandlers.get(video);

        if (typeof handler === 'function') {
            video.removeEventListener('loadeddata', handler);
            pendingStageReadyHandlers.delete(video);
        }
    };

    const clearPendingStageSourceLoad = (video) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        detachPendingStageReadyHandler(video);
        delete video.dataset.pendingSourceLoad;
        delete video.dataset.pendingFocusMs;
    };

    const stageSourceIsLoading = (video) => video instanceof HTMLVideoElement && video.dataset.pendingSourceLoad === 'true';

    const setStageVideoLoadVisibility = (video, isVisible) => {
        if (!(video instanceof HTMLVideoElement)) {
            return;
        }

        video.style.visibility = isVisible ? 'visible' : 'hidden';
    };

    const clearStageSourceLoadWatchdog = () => {
        if (state.stageSourceLoadTimer !== null) {
            window.clearTimeout(state.stageSourceLoadTimer);
            state.stageSourceLoadTimer = null;
        }
    };

    const nextStageSourceRequestId = (video = null) => {
        state.stageSourceRequestId += 1;

        const requestId = String(state.stageSourceRequestId);

        if (video instanceof HTMLVideoElement) {
            video.dataset.sourceRequestId = requestId;
        }

        return requestId;
    };

    const loadStageVideoSource = (scope, video, sourceUrl, options = {}) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return false;
        }

        const normalizedSourceUrl = normalizeMediaUrl(sourceUrl);

        if (normalizedSourceUrl === '') {
            return false;
        }

        const resumeAt = Number.isFinite(Number(options.resumeAt)) ? Number(options.resumeAt) : null;
        const pendingFocusMs = pendingStageFocusMs(video, currentFocusMs(scope));
        const requestId = nextStageSourceRequestId(video);
        const handleSourceReady = () => {
            if (String(video.dataset.sourceRequestId || '') !== requestId) {
                return;
            }

            const latestFocusMs = pendingStageFocusMs(video, pendingFocusMs);
            clearStageSourceLoadWatchdog();
            clearPendingStageSourceLoad(video);
            pendingStageReadyHandlers.delete(video);

            if (resumeAt !== null && latestFocusMs === pendingFocusMs) {
                try {
                    video.currentTime = Math.max(0, resumeAt);
                } catch (error) {
                }
            } else {
                seekStageVideoToFocus(scope, video, latestFocusMs);
            }

            setStageVideoLoadVisibility(video, true);
            updateStageAssetStatus(scope, video, normalizedSourceUrl);

            // Honor pause and seek actions made while the new source was loading.
            applyStageControllerStateToVideo(scope, video);
        };

        clearStageSourceLoadWatchdog();
        detachPendingStageReadyHandler(video);
        pendingStageReadyHandlers.set(video, handleSourceReady);
        video.dataset.pendingSourceLoad = 'true';
        video.addEventListener('loadeddata', handleSourceReady, { once: true });
        setStageVideoLoadVisibility(video, false);

        const sourceNode = stageSourceNode(video);

        if (sourceNode instanceof HTMLSourceElement) {
            sourceNode.src = normalizedSourceUrl;
            video.removeAttribute('src');
        } else {
            video.src = normalizedSourceUrl;
        }

        video.load();
        updateStageAssetStatus(scope, video, normalizedSourceUrl);

        return true;
    };

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

    const prewarmStageNeighbors = () => {};

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
        const sourceNode = stageSourceNode(video);
        const normalizedCurrentSourceUrl = normalizeMediaUrl(
            video.currentSrc
            || (sourceNode instanceof HTMLSourceElement ? (sourceNode.getAttribute('src') || '') : '')
            || video.getAttribute('src')
            || '',
        );

        if (normalizedCurrentSourceUrl === normalizedTargetSourceUrl) {
            updateStageAssetStatus(scope, video, targetSourceUrl);

            return false;
        }

        return loadStageVideoSource(scope, video, targetSourceUrl, { shouldPlay });
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
                fallbackStageToMutedAutoplay(scope, video);
            });
        } else {
            try {
                audio.pause();
            } catch (error) {
            }
        }

        return true;
    };

    const fallbackStageToMutedAutoplay = (scope, video = stageVideo(scope)) => {
        if (!(scope instanceof HTMLElement) || !(video instanceof HTMLVideoElement)) {
            return false;
        }

        const playerState = stageControllerSnapshot(scope, video);

        if (!playerState.isPlaying || playerState.isMuted) {
            return false;
        }

        setStageControllerState(scope, {
            isMuted: true,
            isPlaying: true,
        });
        applyStageControllerStateToVideo(scope, video);

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

        if (!playerState.isPlaying || state.clipScrub?.video === video) {
            video.pause();

            return;
        }

        playVideo(video, () => {
            if (!fallbackStageToMutedAutoplay(scope, video)) {
                syncStageControllerFromVideo(scope, video);
            }
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

        // Absolute children still have old pixel positions while zoom is changing.
        // Their overflow must not redefine the timeline duration/scale.
        return Math.max(track.offsetHeight, 1);
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
        const first = formatFocusLabel(visibleStartMs, scope);
        const last = formatFocusLabel(visibleEndMs, scope);
        const dates = first.slice(0, 10) === last.slice(0, 10)
            ? first.slice(0, 10) : `${first.slice(0, 10)} – ${last.slice(0, 10)}`;
        const label = `${dates}\n${first.slice(11, 19)} – ${last.slice(11)}`;

        scope.querySelectorAll('[data-role="visible-range-label"]').forEach((element) => {
            setText(element, label);
        });
    };

    const setRailStatus = (scope, status, message = '') => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const viewport = railViewport(scope);
        const statusElement = scope.querySelector('[data-role="rail-status"]');
        const retry = scope.querySelector('[data-role="rail-retry"]');
        if (retry) retry.hidden = status !== 'error';
        const isLoading = status === 'loading';
        const normalizedMessage = String(message || '').trim();

        if (viewport instanceof HTMLElement) {
            viewport.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        }

        window.clearTimeout(state.rail.statusTimer);
        state.rail.statusTimer = null;
        if (!(statusElement instanceof HTMLElement)) return;

        const show = () => {
            state.rail.statusTimer = null;
            statusElement.dataset.state = status;
            setText(statusElement, normalizedMessage);
            statusElement.hidden = normalizedMessage === '';
            state.rail.statusShownAt = normalizedMessage === '' ? 0 : Date.now();
        };
        // Background window requests should not flash a message on every scroll.
        // Slow requests remain visible long enough to read, without moving the rail.
        if (isLoading && statusElement.hidden) {
            state.rail.statusTimer = window.setTimeout(show, 300);
        } else if (status === 'ready' && !statusElement.hidden && statusElement.dataset.state === 'loading') {
            state.rail.statusTimer = window.setTimeout(show, Math.max(0, 300 - (Date.now() - state.rail.statusShownAt)));
        } else {
            show();
        }
    };

    const updateFocusCursor = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const topPercent = clamp(((Number(focusMs) - timelineStartMs(scope)) / timelineDurationMs(scope)) * 100, 0, 100);

        scope.querySelectorAll('[data-role="focus-cursor"]').forEach((element) => {
            if (element instanceof HTMLElement) {
                element.style.top = `${topPercent}%`;
                element.setAttribute('aria-valuenow', String(Math.round(focusMs)));
                element.setAttribute('aria-valuetext', formatFocusLabel(focusMs, scope));
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
        if (typeof AbortController !== 'undefined' && state.rail.requestController instanceof AbortController) {
            state.rail.requestController.abort();
        }

        state.railRequestId += 1;

        if (Number.isInteger(state.rail.renderFrame)) {
            window.cancelAnimationFrame(state.rail.renderFrame);
        }

        if (state.rail.requestTimer !== null) {
            window.clearTimeout(state.rail.requestTimer);
        }

        window.clearTimeout(state.rail.statusTimer);
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

    const flushRailWindowRequest = () => {
        if (state.rail.requestInFlight || !(state.rail.queuedRequest && typeof state.rail.queuedRequest === 'object')) {
            return;
        }

        const scope = root();
        const host = scope instanceof HTMLElement ? timelineRail(scope) : null;
        const railDataUrl = scope instanceof HTMLElement ? timelineRailDataUrl(scope, state.rail.queuedRequest.cameraId) : '';

        if (!(scope instanceof HTMLElement) || !(host instanceof HTMLElement) || railDataUrl === '') {
            state.rail.queuedRequest = null;

            return;
        }

        const queuedRequest = state.rail.queuedRequest;
        const cameraId = Number(queuedRequest.cameraId || 0);
        const requestRange = normalizeRailRange(scope, queuedRequest.startMs, queuedRequest.endMs);
        const requestKey = `${cameraId}:${requestRange.startMs}:${requestRange.endMs}`;

        state.rail.queuedRequest = null;

        if (!Number.isFinite(cameraId) || cameraId <= 0 || String(cameraId) !== String(host.dataset.cameraId || '')) {
            return;
        }

        if (railRangeCovered(state.rail.loadedRanges, requestRange.startMs, requestRange.endMs) || state.rail.pendingKeys.has(requestKey)) {
            if (state.rail.queuedRequest) {
                state.rail.requestTimer = window.setTimeout(() => {
                    state.rail.requestTimer = null;
                    flushRailWindowRequest();
                }, 0);
            }

            return;
        }

        state.rail.requestInFlight = true;
        state.rail.pendingKeys.add(requestKey);
        state.railRequestId += 1;

        const requestId = state.railRequestId;
        const controller = typeof AbortController !== 'undefined'
            ? new AbortController()
            : null;

        state.rail.requestController = controller;
        setRailStatus(scope, 'loading', 'Loading the visible timeline…');

        const requestUrl = new URL(railDataUrl, window.location.href);

        requestUrl.searchParams.set('day_start_ms', String(timelineStartMs(scope)));
        requestUrl.searchParams.set('day_end_ms', String(timelineEndMs(scope)));
        requestUrl.searchParams.set('window_start_ms', String(requestRange.startMs));
        requestUrl.searchParams.set('window_end_ms', String(requestRange.endMs));

        window.fetch(requestUrl.toString(), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller?.signal,
        })
            .then((response) => response.ok ? response.json() : Promise.reject(response))
            .then((payload) => {
                const currentRoot = root();
                const currentRail = currentRoot instanceof HTMLElement ? timelineRail(currentRoot) : null;

                if (currentRoot !== scope || requestId !== state.railRequestId || !(currentRail instanceof HTMLElement)) {
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
                renderRailWindow(currentRoot);
                updateCameraSwitchUi(currentRoot);
                const video = stageVideo(currentRoot);
                if (!video?.dataset.recordingId && !state.stageSegmentRequestController
                    && segmentPayloadForFocus(currentRoot, currentFocusMs(currentRoot))) {
                    void syncStageSegmentToFocus(currentRoot, currentFocusMs(currentRoot), { fetchIfMissing: false });
                }
                setRailStatus(currentRoot, 'ready');
            })
            .catch((error) => {
                if (error?.name === 'AbortError' || requestId !== state.railRequestId) {
                    return;
                }

                const currentRoot = root();
                const currentRail = currentRoot instanceof HTMLElement ? timelineRail(currentRoot) : null;

                if (currentRoot instanceof HTMLElement
                    && currentRail instanceof HTMLElement
                    && String(cameraId) === String(currentRail.dataset.cameraId || '')) {
                    setRailStatus(currentRoot, 'error', 'The next timeline section could not be loaded. Scroll or try again to retry.');
                }
            })
            .finally(() => {
                if (requestId !== state.railRequestId) {
                    return;
                }

                state.rail.pendingKeys.delete(requestKey);
                state.rail.requestInFlight = false;
                state.rail.requestController = null;

                if (state.rail.queuedRequest && state.rail.requestTimer === null) {
                    state.rail.requestTimer = window.setTimeout(() => {
                        state.rail.requestTimer = null;
                        flushRailWindowRequest();
                    }, 0);
                }
            });
    };

    const queueRailWindowRequest = (cameraId, requestRange) => {
        state.rail.queuedRequest = {
            cameraId,
            endMs: requestRange.endMs,
            startMs: requestRange.startMs,
        };

        if (state.rail.requestInFlight || state.rail.requestTimer !== null) {
            return;
        }

        state.rail.requestTimer = window.setTimeout(() => {
            state.rail.requestTimer = null;
            flushRailWindowRequest();
        }, 90);
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
        const scrubFrameCount = Math.max(0, Number(segment.scrubFrameCount || 0));
        const scrubFrameWidth = Math.max(0, Number(segment.scrubFrameWidth || 0));
        const scrubFrameHeight = Math.max(0, Number(segment.scrubFrameHeight || 0));
        const scrubColumns = Math.max(1, Number(segment.scrubColumns || 0) || 1);
        const scrubRows = Math.max(
            1,
            Number(segment.scrubRows || 0) || Math.ceil(Math.max(1, scrubFrameCount) / Math.max(1, scrubColumns)),
        );
        const scrubFrameCapacity = Math.max(1, scrubColumns * scrubRows);
        const thumbnailFrameIndex = clamp(
            Number(segment.thumbnailFrameIndex || 0),
            0,
            Math.max(0, Math.min(Math.max(0, scrubFrameCount - 1), scrubFrameCapacity - 1)),
        );
        const scrubSpriteUrl = typeof segment.scrubSpriteUrl === 'string' ? segment.scrubSpriteUrl.trim() : '';

        return {
            ...segment,
            endMs,
            focusMs: Number(segment.focusMs || startMs),
            id: segment.id ?? null,
            startMs,
            scrubColumns,
            scrubFrameCount,
            scrubFrameHeight,
            scrubFrameIntervalMs: Math.max(0, Number(segment.scrubFrameIntervalMs || 0)),
            scrubFrameWidth,
            scrubRows,
            scrubSpriteUrl,
            thumbnailFrameIndex,
            thumbnailSpriteUrl: typeof segment.thumbnailSpriteUrl === 'string' ? segment.thumbnailSpriteUrl.trim() : '',
            thumbnailFallbackUrl: typeof segment.thumbnailFallbackUrl === 'string' ? segment.thumbnailFallbackUrl.trim() : '',
            thumbnailUrl: typeof segment.thumbnailUrl === 'string' ? segment.thumbnailUrl.trim() : '',
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

    const updateCameraSwitchUi = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const resolvedActiveCameraId = activeCameraId(scope);

        cameraSwitches(scope).forEach((button) => {
            const isActive = String(button.dataset.cameraId || '') === resolvedActiveCameraId;

            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    const updateActiveRailDecorations = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const resolvedActiveSegmentId = activeRailSegmentId(scope);

        scope.querySelectorAll('[data-role="rail-segment"], [data-role="rail-thumbnail"]').forEach((element) => {
            if (!(element instanceof HTMLElement)) {
                return;
            }

            element.classList.toggle('is-active', resolvedActiveSegmentId !== '' && String(element.dataset.recordingId || '') === resolvedActiveSegmentId);
        });
    };

    const setActiveRailSegmentId = (scope, segmentId) => {
        const host = timelineRail(scope);

        if (!(host instanceof HTMLElement)) {
            return;
        }

        host.dataset.activeSegmentId = segmentId === null || segmentId === undefined ? '' : String(segmentId);
        updateActiveRailDecorations(scope);
    };

    const stageFallbackMessage = (scope) => {
        const button = activeCameraSwitch(scope);
        const latestRecordingLabel = button instanceof HTMLButtonElement ? String(button.dataset.latestRecordingLabel || '').trim() : '';

        return latestRecordingLabel !== ''
            ? `Latest clip: ${latestRecordingLabel}`
            : 'Choose a camera below or drag the scrub rail onto a saved event.';
    };

    const resetRailBootstrapPayload = (scope) => {
        const host = timelineRail(scope);

        if (!(host instanceof HTMLElement)) {
            return;
        }

        const segmentsScript = host.querySelector('[data-role="rail-segments-json"]');

        if (segmentsScript instanceof HTMLScriptElement) {
            segmentsScript.textContent = '[]';
        }
    };

    const switchActiveCamera = (scope, button) => {
        if (!(scope instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
            return;
        }

        const host = timelineRail(scope);
        const nextCameraId = String(button.dataset.cameraId || '').trim();

        if (!(host instanceof HTMLElement) || nextCameraId === '') {
            return;
        }

        if (nextCameraId === activeCameraId(scope)) {
            updateCameraSwitchUi(scope);

            return;
        }

        const preservedFocus = currentFocusMs(scope);
        state.activeCameraId = nextCameraId;
        scope.dataset.activeCameraId = nextCameraId;
        host.dataset.cameraId = nextCameraId;
        host.dataset.cameraName = String(button.dataset.cameraName || 'Camera');
        host.dataset.railUrl = String(button.dataset.railUrl || '');
        host.dataset.stageUrl = String(button.dataset.stageUrl || '');
        host.dataset.activeSegmentId = '';

        const viewport = railViewport(scope);
        if (viewport) viewport.setAttribute('aria-label', 'Scrollable recording timeline for '+host.dataset.cameraName);
        resetStagePrewarm();
        hideScrubPreview(scope);
        resetRailBootstrapPayload(scope);
        resetRailState();
        setRailStatus(scope, 'ready');
        readRailBootstrapData(scope);
        updateCameraSwitchUi(scope);
        renderRailWindow(scope);
        void syncStageSegmentToFocus(scope, currentFocusMs(scope), {
            fetchIfMissing: true,
        });
        syncStagePlaybackState(scope);
        syncStageAudioState(scope);
        updateStageVolumeUi(scope);
        updateLocalFocus(scope, preservedFocus);
        persistReviewInUrl(scope);
        requestRailWindow(scope);
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

    const ensureRailThumbnailSpriteLayer = (frame) => {
        if (!(frame instanceof HTMLElement)) {
            return null;
        }

        const existing = frame.querySelector('[data-role="rail-thumbnail-sprite"]');

        if (existing instanceof HTMLElement) {
            return existing;
        }

        const sprite = document.createElement('span');

        sprite.className = 'recording-review-focus__rail-thumbnail-sprite';
        sprite.dataset.role = 'rail-thumbnail-sprite';
        sprite.setAttribute('aria-hidden', 'true');
        frame.appendChild(sprite);

        return sprite;
    };

    const ensureRailThumbnailFallbackImage = (frame, thumbnailUrl, thumbnailAlt) => {
        if (!(frame instanceof HTMLElement) || thumbnailUrl === '') {
            return null;
        }

        const existing = frame.querySelector('img');

        if (existing instanceof HTMLImageElement) {
            if (existing.alt !== thumbnailAlt) {
                existing.alt = thumbnailAlt;
            }

            if (existing.getAttribute('src') !== thumbnailUrl) {
                existing.src = thumbnailUrl;
            }

            return existing;
        }

        const image = document.createElement('img');

        image.alt = thumbnailAlt;
        image.decoding = 'async';
        image.loading = 'eager';
        image.src = thumbnailUrl;
        frame.appendChild(image);

        return image;
    };

    const removeRailThumbnailFallbackImage = (frame) => {
        if (!(frame instanceof HTMLElement)) {
            return;
        }

        frame.querySelectorAll('img').forEach((image) => {
            image.remove();
        });
    };

    const railThumbnailMediaKey = (thumbnail) => JSON.stringify([
        thumbnail.dataset.thumbnailSpriteUrl, thumbnail.dataset.thumbnailUrl,
        thumbnail.dataset.thumbnailFrameIndex, thumbnail.dataset.scrubFrameCount,
        thumbnail.dataset.scrubFrameWidth, thumbnail.dataset.scrubFrameHeight,
        thumbnail.dataset.scrubColumns, thumbnail.dataset.scrubRows,
    ]);

    const hydrateRailThumbnail = (thumbnail) => {
        if (!(thumbnail instanceof HTMLElement)) {
            return;
        }

        const frame = thumbnail.querySelector('[data-role="rail-thumbnail-frame"]');
        const thumbnailUrl = String(thumbnail.dataset.thumbnailUrl || '').trim();
        const thumbnailAlt = String(thumbnail.dataset.thumbnailAlt || '').trim();
        const thumbnailSpriteUrl = String(thumbnail.dataset.thumbnailSpriteUrl || '').trim();
        const thumbnailFrameIndex = readNumber(thumbnail, 'thumbnailFrameIndex', 0);
        const scrubFrameCount = Math.max(0, readNumber(thumbnail, 'scrubFrameCount', 0));
        const scrubFrameWidth = Math.max(0, readNumber(thumbnail, 'scrubFrameWidth', 0));
        const scrubFrameHeight = Math.max(0, readNumber(thumbnail, 'scrubFrameHeight', 0));
        const scrubColumns = Math.max(1, readNumber(thumbnail, 'scrubColumns', 1));
        const scrubRows = Math.max(1, readNumber(thumbnail, 'scrubRows', 1));

        if (!(frame instanceof HTMLElement)) {
            return;
        }

        const mediaKey = railThumbnailMediaKey(thumbnail);
        if (frame.dataset.hydrationKey === mediaKey) return;
        frame.dataset.hydrationKey = mediaKey;
        const isCurrent = () => thumbnail.isConnected && frame.dataset.hydrationKey === mediaKey
            && railThumbnailMediaKey(thumbnail) === mediaKey;

        if (thumbnailSpriteUrl === '' || scrubFrameCount < 1) {
            const oldSprite = frame.querySelector('[data-role=rail-thumbnail-sprite]');
            oldSprite?.classList.remove('is-ready');
            clearSpriteFrameBackground(oldSprite);
            if (thumbnailUrl === '') removeRailThumbnailFallbackImage(frame);
            ensureRailThumbnailFallbackImage(frame, thumbnailUrl, thumbnailAlt);
            frame.dataset.renderState = thumbnailUrl !== '' ? 'fallback' : 'empty';

            return;
        }

        const spriteLayer = ensureRailThumbnailSpriteLayer(frame);

        if (!(spriteLayer instanceof HTMLElement)) {
            ensureRailThumbnailFallbackImage(frame, thumbnailUrl, thumbnailAlt);
            frame.dataset.renderState = thumbnailUrl !== '' ? 'fallback' : 'empty';

            return;
        }

        const frameKey = `${thumbnailSpriteUrl}:${thumbnailFrameIndex}`;
        const renderSprite = () => {
            if (!isCurrent()) return;

            removeRailThumbnailFallbackImage(frame);
            applySpriteFrameBackground(
                spriteLayer,
                thumbnailSpriteUrl,
                thumbnailFrameIndex,
                scrubFrameCount,
                scrubColumns,
                scrubRows,
                frameKey,
            );
            spriteLayer.classList.add('is-ready');
            frame.dataset.renderState = 'ready';
        };

        if (state.scrubSpriteCache.has(thumbnailSpriteUrl)) {
            renderSprite();

            return;
        }

        // Keep the current preview until the replacement sprite has decoded.
        if (!spriteLayer.classList.contains('is-ready')) {
            ensureRailThumbnailFallbackImage(frame, thumbnailUrl, thumbnailAlt);
            frame.dataset.renderState = 'loading';
        }

        void preloadScrubSprite(thumbnailSpriteUrl, {
            expectedHeight: scrubFrameHeight * scrubRows,
            expectedWidth: scrubFrameWidth * scrubColumns,
            priority: 'low',
        }).then((loaded) => {
            if (!isCurrent()) return;
            if (!loaded) {
                spriteLayer.classList.remove('is-ready');
                clearSpriteFrameBackground(spriteLayer);
                ensureRailThumbnailFallbackImage(frame, thumbnailUrl, thumbnailAlt);
                frame.dataset.renderState = thumbnailUrl !== '' ? 'fallback' : 'error';

                return;
            }

            renderSprite();
        });
    };

    const observeRailThumbnails = (scope) => {
        const observer = ensureRailThumbnailObserver(scope);

        scope.querySelectorAll('[data-role="rail-thumbnail-frame"]').forEach((frame) => {
            if (!(frame instanceof HTMLElement)) {
                return;
            }

            const thumbnail = frame.closest('[data-role="rail-thumbnail"]');
            if (!(thumbnail instanceof HTMLElement) || frame.dataset.hydrationKey === railThumbnailMediaKey(thumbnail)) return;
            if (observer) {
                observer.observe(frame);
            } else {
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

            storeRailSegments(initialSegments);

            if (initialSegments.length > 0) {
                const initialRange = normalizeRailRange(
                    scope,
                    readNumber(host, 'initialWindowStartMs', timelineStartMs(scope)),
                    readNumber(host, 'initialWindowEndMs', timelineEndMs(scope)),
                );

                state.rail.loadedRanges = mergeRailRanges(cameraDidChange ? [] : state.rail.loadedRanges, initialRange);
            } else if (cameraDidChange) {
                state.rail.loadedRanges = [];
            }
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
        const previous = state.rail.thumbnailLayout;
        const geometryKey = [trackHeight, thumbnailHeight, thumbnailGap, activeSegment].join(':');
        if (previous?.segments === segments && previous.geometryKey === geometryKey) return previous.items;
        const retainedIds = new Set(previous?.geometryKey === geometryKey ? previous.items.map(item => String(item.id)) : []);
        const occupiedRanges = [];

        const items = segments
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

                const leftRetained = retainedIds.has(String(left.id));
                const rightRetained = retainedIds.has(String(right.id));
                if (leftRetained !== rightRetained) return leftRetained ? -1 : 1;

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
        state.rail.thumbnailLayout = { segments, geometryKey, items };
        return items;
    };

    const createRailTickElement = (tick, existing = null) => {
        const button = existing || document.createElement('button');
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

        button.replaceChildren(label);

        return button;
    };

    const createRailSegmentElement = (scope, segment, existing = null) => {
        const button = existing || document.createElement('button');
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

        if (!existing) {
            const bar = document.createElement('span');
            bar.className = 'recording-review-focus__rail-segment-bar';
            button.appendChild(bar);
        }

        return button;
    };

    const createRailThumbnailElement = (scope, segment, existing = null) => {
        const button = existing || document.createElement('button');
        const frame = document.createElement('span');
        const sprite = document.createElement('span');
        const captureMode = segment.captureMode === 'motion' ? 'motion' : 'continuous';
        const activeSegment = activeRailSegmentId(scope);
        const cameraName = timelineRail(scope)?.dataset.cameraName || 'Camera';

        button.type = 'button';
        button.className = `recording-review-focus__rail-thumbnail recording-review-focus__rail-thumbnail--${captureMode}${activeSegment !== '' && String(segment.id ?? '') === activeSegment ? ' is-active' : ''}`;
        button.dataset.role = 'rail-thumbnail';
        button.dataset.recordingId = String(segment.id ?? '');
        button.dataset.focusMs = String(segment.startMs || 0);
        button.dataset.thumbnailUrl = String(segment.thumbnailFallbackUrl || '');
        button.dataset.thumbnailSpriteUrl = String(segment.thumbnailSpriteUrl || '');
        button.dataset.thumbnailFrameIndex = String(segment.thumbnailFrameIndex || 0);
        button.dataset.scrubFrameCount = String(segment.scrubFrameCount || 0);
        button.dataset.scrubFrameWidth = String(segment.scrubFrameWidth || 0);
        button.dataset.scrubFrameHeight = String(segment.scrubFrameHeight || 0);
        button.dataset.scrubColumns = String(segment.scrubColumns || 0);
        button.dataset.scrubRows = String(segment.scrubRows || 0);
        button.dataset.thumbnailAlt = `${cameraName} ${segment.timeLabel || 'Segment preview'} preview`;
        button.style.top = `${Number(segment.thumbnailTopPx || 0)}px`;
        button.setAttribute('aria-label', `${cameraName} ${segment.timeLabel || 'Saved clip'} preview thumbnail`);
        button.title = `${segment.timeLabel || 'Saved clip'} · ${segment.modeLabel || 'Recorded clip'}`;

        if (existing) return button;

        frame.className = 'recording-review-focus__rail-thumbnail-frame';
        frame.dataset.role = 'rail-thumbnail-frame';
        frame.dataset.renderState = 'idle';

        sprite.className = 'recording-review-focus__rail-thumbnail-sprite';
        sprite.dataset.role = 'rail-thumbnail-sprite';
        sprite.setAttribute('aria-hidden', 'true');

        frame.appendChild(sprite);

        button.appendChild(frame);

        return button;
    };

    const reconcileRailLayer = (layer, items, keyFor, signatureFor, render) => {
        const desiredKeys = new Set(items.map(keyFor));
        const existing = new Map();
        for (const node of Array.from(layer.children)) {
            if (desiredKeys.has(node.dataset.railKey)) {
                existing.set(node.dataset.railKey, node);
            } else {
                const frame = node.querySelector('[data-role="rail-thumbnail-frame"]');
                if (frame) state.rail.thumbnailObserver?.unobserve(frame);
                node.remove();
            }
        }
        let cursor = layer.firstElementChild;
        for (const item of items) {
            const key = keyFor(item);
            const signature = signatureFor(item);
            let node = existing.get(key);
            if (!node || node.railRenderSignature !== signature) {
                node = render(item, node);
                node.dataset.railKey = key;
                node.railRenderSignature = signature;
            }
            // Do not detach/reinsert retained nodes: that resets image painting and focus.
            if (node !== cursor) layer.insertBefore(node, cursor);
            cursor = node.nextElementSibling;
        }
    };

    const renderRailWindow = (scope) => {
        if (!(scope instanceof HTMLElement)) return;
        const ticksLayer = railTicksLayer(scope);
        const segmentsLayer = railSegmentsLayer(scope);
        const thumbnailsLayer = railThumbnailsLayer(scope);
        if (!ticksLayer || !segmentsLayer || !thumbnailsLayer) return;
        readRailBootstrapData(scope);

        const visibleWindow = railWindowForViewport(scope);
        const tickBufferMs = secondaryTickIntervalMinutes(scope) * 60000;
        const visibleTicks = state.rail.ticks.filter(tick => tick.focusMs >= visibleWindow.startMs - tickBufferMs
            && tick.focusMs <= visibleWindow.endMs + tickBufferMs);
        const intersects = segment => segment.endMs >= visibleWindow.startMs && segment.startMs <= visibleWindow.endMs;
        const visibleSegments = state.rail.segments.filter(intersects);
        // Select non-overlapping previews across loaded data, not the moving viewport.
        const thumbnails = buildRailThumbnailSegments(scope, state.rail.segments).filter(intersects);
        const cameraKey = String(state.rail.cameraId);
        const trackHeight = railTrackHeight(scope);
        const keyForSegment = segment => `${cameraKey}:${railSegmentKey(segment)}`;
        const segmentSignature = segment => `${trackHeight}:${JSON.stringify(segment)}`;

        reconcileRailLayer(ticksLayer, visibleTicks, tick => `${cameraKey}:${tick.focusMs}:${tick.kind}`,
            tick => JSON.stringify(tick), (tick, node) => createRailTickElement(tick, node));
        reconcileRailLayer(segmentsLayer, visibleSegments, keyForSegment, segmentSignature,
            (segment, node) => createRailSegmentElement(scope, segment, node));
        reconcileRailLayer(thumbnailsLayer, thumbnails, keyForSegment, segmentSignature,
            (segment, node) => createRailThumbnailElement(scope, segment, node));
        updateActiveRailDecorations(scope);
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

        if (exact && typeof exact === 'object') {
            return exact;
        }

        return null;
    };

    const abortPendingStageSegmentRequest = () => {
        if (typeof AbortController !== 'undefined' && state.stageSegmentRequestController instanceof AbortController) {
            state.stageSegmentRequestController.abort();
        }

        state.stageSegmentRequestController = null;
    };

    const requestStageSegmentForFocus = (scope, focusMs, direction = null) => {
        if (!(scope instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        const cameraId = String(activeCameraId(scope) || '');
        const stageDataUrl = timelineStageDataUrl(scope, cameraId);

        if (cameraId === '' || stageDataUrl === '') {
            return Promise.resolve(null);
        }

        abortPendingStageSegmentRequest();
        state.stageSegmentRequestId += 1;
        const requestId = state.stageSegmentRequestId;
        const controller = typeof AbortController !== 'undefined'
            ? new AbortController()
            : null;

        state.stageSegmentRequestController = controller;

        const requestUrl = new URL(stageDataUrl, window.location.href);
        requestUrl.searchParams.set('day_start_ms', String(timelineStartMs(scope)));
        requestUrl.searchParams.set('day_end_ms', String(timelineEndMs(scope)));
        requestUrl.searchParams.set('focus_ms', String(Math.round(focusMs)));
        if (direction) requestUrl.searchParams.set('direction', direction);

        return window.fetch(requestUrl.toString(), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller?.signal,
        })
            .then((response) => response.ok ? response.json() : Promise.reject(response))
            .then((payload) => {
                const currentRoot = root();

                if (!(currentRoot instanceof HTMLElement) || currentRoot !== scope || requestId !== state.stageSegmentRequestId) {
                    return null;
                }

                if (String(payload?.cameraId || '') !== cameraId || activeCameraId(scope) !== cameraId) {
                    return null;
                }

                const segment = normalizeRailSegment(payload?.segment);

                if (segment) {
                    storeRailSegments([segment]);
                    renderRailWindow(scope);
                }

                return segment;
            })
            .catch((error) => {
                if (error?.name !== 'AbortError' && root() === scope && requestId === state.stageSegmentRequestId) {
                    setPlaybackNotice(scope, 'Could not load this recording. Check your connection and retry.', 'error');
                }
                return undefined;
            })
            .finally(() => {
                if (requestId === state.stageSegmentRequestId) {
                    state.stageSegmentRequestController = null;
                }
            });
    };

    const syncStageSegmentToFocus = (scope, focusMs, options = {}) => {
        if (!(scope instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        const selectionId = ++state.stageSelectionId;
        const cameraId = activeCameraId(scope);
        const resolvedFocusMs = clamp(
            Number(focusMs ?? currentFocusMs(scope)),
            timelineStartMs(scope),
            timelineMaximumFocusMs(scope),
        );
        const localSegment = segmentPayloadForFocus(scope, resolvedFocusMs);
        const shouldFetch = options.fetchIfMissing !== false;
        const shouldResumePlayback = options.resumePlayback === true;

        abortPendingStageSegmentRequest();

        if (localSegment) {
            applyStageSegment(scope, localSegment, resolvedFocusMs);
            seekStagePlaybackToFocus(scope, resolvedFocusMs);

            return Promise.resolve(localSegment);
        }

        applyStageSegment(scope, null, resolvedFocusMs);

        if (!shouldFetch) {
            setStageControllerState(scope, {
                isPlaying: false,
            });

            return Promise.resolve(null);
        }

        setPlaybackNotice(scope, 'Loading recording…');
        return requestStageSegmentForFocus(scope, resolvedFocusMs).then((segment) => {
            const currentRoot = root();

            if (!(currentRoot instanceof HTMLElement) || currentRoot !== scope) {
                return segment;
            }

            if (segment === undefined || selectionId !== state.stageSelectionId || cameraId !== activeCameraId(scope)
                || Math.abs(currentFocusMs(scope) - resolvedFocusMs) > 1) {
                return segment;
            }

            if (segment) {
                if (shouldResumePlayback) {
                    setStageControllerState(scope, {
                        isPlaying: true,
                    });
                }

                applyStageSegment(scope, segment, resolvedFocusMs);
                seekStagePlaybackToFocus(scope, resolvedFocusMs);

                return segment;
            }

            setStageControllerState(scope, {
                isPlaying: false,
            });
            applyStageSegment(scope, null, resolvedFocusMs);

            return null;
        });
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
        const emptyCopy = scope.querySelector('[data-role="empty-copy"]');
        const video = stageVideo(scope);
        const audio = stageAudio(scope);
        const actions = scope.querySelector('.recording-review-focus__actions');
        const downloadLink = scope.querySelector('[data-role="download-link"]');
        const cameraName = timelineRail(scope)?.dataset.cameraName || 'Camera';

        if (state.clipScrub && String(segment?.id ?? '') !== state.clipScrub.recordingId) {
            const wasPlaying = state.clipScrub.wasPlaying;
            state.clipScrub = null;
            setStageControllerState(scope, { isPlaying: wasPlaying });
        }
        setPlaybackNotice(scope);
        updateStageText(scope, 'camera-label', cameraName);
        setActiveRailSegmentId(scope, segment && typeof segment === 'object' ? (segment.id ?? null) : null);

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

            if (emptyCopy instanceof HTMLElement) {
                setText(emptyCopy, stageFallbackMessage(scope));
            }

            if (video instanceof HTMLVideoElement) {
                const sourceNode = stageSourceNode(video);

                clearStageSourceLoadWatchdog();
                clearPendingStageSourceLoad(video);
                nextStageSourceRequestId(video);
                video.dataset.recordingId = '';
                updateClipProgress(scope);
                video.dataset.startMs = '';
                video.dataset.endMs = '';
                video.dataset.durationSeconds = '';
                video.dataset.fallbackStreamUrl = '';
                video.dataset.reviewStreamUrl = '';
                video.dataset.directStreamUrl = '';
                video.dataset.reviewStatusLabel = 'Playback stream';
                video.dataset.directStatusLabel = 'Direct stream';
                video.removeAttribute('poster');
                setStageVideoLoadVisibility(video, true);

                if (sourceNode instanceof HTMLSourceElement) {
                    sourceNode.removeAttribute('src');
                }

                video.removeAttribute('src');

                try {
                    video.pause();
                    video.load();
                } catch (error) {
                }
            }

            if (audio instanceof HTMLAudioElement) {
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
            }

            state.stageAudioLastSyncAt = 0;

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

        const directUrl = String(segment.streamUrl || '');

        video.dataset.recordingId = String(segment.id || '');
        video.dataset.startMs = String(segment.startMs || '');
        video.dataset.endMs = String(segment.endMs || '');
        video.dataset.durationSeconds = String(segment.durationSeconds || '');
        video.dataset.fallbackStreamUrl = '';
        video.dataset.reviewStreamUrl = '';
        video.dataset.directStreamUrl = directUrl;
        video.dataset.reviewStatusLabel = 'Playback stream';
        video.dataset.directStatusLabel = 'Playback stream';
        video.removeAttribute('poster');
        setStageVideoLoadVisibility(video, true);

        applyStageControllerStateToVideo(scope, video);
        updateClipProgress(scope);
        prewarmStageNeighbors(scope, segment);
    };

    const scheduleRailRender = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        readRailBootstrapData(scope);

        if (Number.isInteger(state.rail.renderFrame)) {
            return;
        }

        state.rail.renderFrame = window.requestAnimationFrame(() => {
            state.rail.renderFrame = null;
            updateVisibleRange(scope);
            renderRailWindow(scope);
            requestRailWindow(scope);
        });
    };

    const requestRailWindow = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const host = timelineRail(scope);

        if (!(host instanceof HTMLElement)) {
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
        queueRailWindowRequest(cameraId, requestRange);
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

    const spriteFrameCapacity = (columns, rows) => Math.max(1, Math.max(1, columns) * Math.max(1, rows));

    const normalizeSpriteFrameIndex = (frameIndex, frameCount, columns, rows) => clamp(
        Number(frameIndex || 0),
        0,
        Math.max(0, Math.min(Math.max(0, Number(frameCount || 0) - 1), spriteFrameCapacity(columns, rows) - 1)),
    );

    const spriteFrameCell = (frameIndex, frameCount, columns, rows) => {
        const normalizedColumns = Math.max(1, Number(columns || 1));
        const normalizedRows = Math.max(1, Number(rows || 1));
        const normalizedFrameIndex = normalizeSpriteFrameIndex(frameIndex, frameCount, normalizedColumns, normalizedRows);

        return {
            column: normalizedFrameIndex % normalizedColumns,
            frameIndex: normalizedFrameIndex,
            row: Math.min(normalizedRows - 1, Math.floor(normalizedFrameIndex / normalizedColumns)),
        };
    };

    const spriteBackgroundAxisPosition = (cellIndex, cellCount) => cellCount <= 1
        ? '0%'
        : `${(cellIndex / (cellCount - 1)) * 100}%`;

    const clearSpriteFrameBackground = (layer) => {
        if (!(layer instanceof HTMLElement)) {
            return;
        }

        layer.style.backgroundImage = '';
        layer.style.backgroundPosition = '';
        layer.style.backgroundSize = '';
        delete layer.dataset.frameIndex;
        delete layer.dataset.frameKey;
        delete layer.dataset.spriteUrl;
    };

    const applySpriteFrameBackground = (layer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey) => {
        if (!(layer instanceof HTMLElement) || spriteUrl === '') {
            clearSpriteFrameBackground(layer);

            return;
        }

        const normalizedColumns = Math.max(1, Number(columns || 1));
        const normalizedRows = Math.max(1, Number(rows || 1));
        const cell = spriteFrameCell(frameIndex, frameCount, normalizedColumns, normalizedRows);

        layer.style.backgroundImage = `url("${spriteUrl}")`;
        layer.style.backgroundSize = `${normalizedColumns * 100}% ${normalizedRows * 100}%`;
        layer.style.backgroundPosition = `${spriteBackgroundAxisPosition(cell.column, normalizedColumns)} ${spriteBackgroundAxisPosition(cell.row, normalizedRows)}`;
        layer.dataset.frameIndex = String(cell.frameIndex);
        layer.dataset.spriteUrl = spriteUrl;
        layer.dataset.frameKey = frameKey;
    };

    const applyScrubPreviewLayerFrame = (layer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey) => {
        applySpriteFrameBackground(layer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey);
    };

    const trimRememberedSpriteMap = (entries) => {
        while (entries.size > maximumRememberedSprites) {
            const oldestKey = entries.keys().next().value;

            if (oldestKey === undefined) {
                return;
            }

            entries.delete(oldestKey);
        }
    };

    const rememberLoadedSprite = (spriteUrl) => {
        state.scrubSpriteCache.delete(spriteUrl);
        state.scrubSpriteCache.set(spriteUrl, Date.now());
        state.scrubSpriteFailures.delete(spriteUrl);
        trimRememberedSpriteMap(state.scrubSpriteCache);
    };

    const rememberFailedSprite = (spriteUrl) => {
        state.scrubSpritePending.delete(spriteUrl);
        state.scrubSpriteFailures.delete(spriteUrl);
        state.scrubSpriteFailures.set(spriteUrl, Date.now());
        trimRememberedSpriteMap(state.scrubSpriteFailures);
    };

    const preloadScrubSprite = (spriteUrl, options = {}) => {
        if (spriteUrl === '') {
            return Promise.resolve(false);
        }

        const expectedWidth = Math.max(0, Number(options.expectedWidth || 0));
        const expectedHeight = Math.max(0, Number(options.expectedHeight || 0));
        const priority = options.priority === 'high' ? 'high' : 'low';

        if (state.scrubSpriteCache.has(spriteUrl)) {
            rememberLoadedSprite(spriteUrl);

            return Promise.resolve(true);
        }

        const failedAt = Number(state.scrubSpriteFailures.get(spriteUrl) || 0);

        if (failedAt > 0 && (Date.now() - failedAt) < spriteFailureRetryDelayMs) {
            return Promise.resolve(false);
        }

        state.scrubSpriteFailures.delete(spriteUrl);

        const pendingLoad = state.scrubSpritePending.get(spriteUrl);

        if (pendingLoad) {
            return pendingLoad;
        }

        const preload = new Promise((resolve) => {
            const image = new Image();

            image.decoding = 'async';
            image.fetchPriority = priority;
            image.onload = async () => {
                const widthMatches = expectedWidth < 1 || image.naturalWidth === expectedWidth;
                const heightMatches = expectedHeight < 1 || image.naturalHeight === expectedHeight;

                if (!widthMatches || !heightMatches) {
                    rememberFailedSprite(spriteUrl);
                    resolve(false);

                    return;
                }

                try {
                    await image.decode();
                } catch (error) {
                    rememberFailedSprite(spriteUrl);
                    resolve(false);
                    return;
                }
                rememberLoadedSprite(spriteUrl);
                state.scrubSpritePending.delete(spriteUrl);
                resolve(true);
            };
            image.onerror = () => {
                rememberFailedSprite(spriteUrl);
                resolve(false);
            };
            image.src = spriteUrl;
        });

        state.scrubSpritePending.set(spriteUrl, preload);

        return preload;
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

    const applyTimelineScale = (scope, anchor = null) => {
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
            const restoredScrollTop = anchor
                ? ((anchor.anchorMs - timelineStartMs(scope)) / timelineDurationMs(scope)) * nextTrackHeight - anchor.anchorOffsetY
                : (state.pendingViewportRestore && Number.isFinite(state.viewportScrollTop)
                    ? Number(state.viewportScrollTop) : viewport.scrollTop);

            viewport.scrollTop = clamp(restoredScrollTop, 0, Math.max(0, nextTrackHeight - viewport.clientHeight));
            state.viewportScrollTop = viewport.scrollTop;
            state.pendingViewportRestore = false;
        }

        updateFocusCursor(scope, currentFocusMs(scope));
        renderRailWindow(scope);
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
    };

    const handleViewportWheel = (event) => {
        const viewport = event.currentTarget;
        const scope = rootFor(viewport);

        if (!(viewport instanceof HTMLElement)
            || !(scope instanceof HTMLElement)
            || event.deltaY === 0
            || state.drag
            || (!event.ctrlKey && !event.metaKey)) {
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

        applyZoomScale(scope, nextZoomScale, {
            anchorMs,
            anchorOffsetY,
        });

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
            scheduleRailRender(currentRoot);
            requestRailWindow(currentRoot);
            updateTimelineTickLabelVisibility(currentRoot);
        });

        observedElements.forEach((element) => {
            state.resizeObserver?.observe(element);
        });
    };

    const refreshScope = (scope) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        const nextActiveCameraId = activeCameraId(scope);
        const cameraDidChange = state.activeCameraId !== null && state.activeCameraId !== nextActiveCameraId;

        if (cameraDidChange) {
            state.pendingViewportRestore = false;
            state.viewportScrollTop = null;
            resetStagePrewarm();
        }

        bindRailViewport(scope);
        bindResizeObserver(scope);
        readRailBootstrapData(scope);
        applyTimelineScale(scope);
        void syncStageSegmentToFocus(scope, currentFocusMs(scope), {
            fetchIfMissing: true,
        });
        bindStageVideo(scope);
        bindStageAudio(scope);
        syncStagePlaybackState(scope);
        syncStageAudioState(scope);
        updateStageVolumeUi(scope);
        updateFocusCursor(scope, currentFocusMs(scope));
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
        const shell = railShell(scope);
        const viewport = railViewport(scope);
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

        const frameKey = `${spriteUrl}:${frameIndex}`;

        state.scrubPreviewRequestId += 1;

        const requestId = String(state.scrubPreviewRequestId);
        const activeLayerIndex = activeScrubPreviewLayerIndex(frame);
        const activeLayer = layers[activeLayerIndex] || layers[0] || null;
        const hasActiveSprite = activeLayer instanceof HTMLElement && (activeLayer.dataset.spriteUrl || '') !== '';
        const activeSpriteUrl = activeLayer instanceof HTMLElement ? (activeLayer.dataset.spriteUrl || '') : '';
        const maxPreviewWidth = Math.max(
            160,
            Math.min(
                360,
                Math.max(160, window.innerWidth - 48),
                shell instanceof HTMLElement ? Math.max(160, shell.clientWidth - 24) : 360,
            ),
        );
        const minimumPreviewWidth = Math.min(220, maxPreviewWidth);
        const previewScale = clamp(260 / Math.max(frameWidth, 1), 1.25, 2.1);
        const scaledFrameWidth = Math.round(clamp(frameWidth * previewScale, minimumPreviewWidth, maxPreviewWidth));
        const scaledFrameHeight = Math.round((scaledFrameWidth / Math.max(frameWidth, 1)) * frameHeight);

        state.scrubPreviewVisible = true;
        state.scrubPreviewFocusMs = focusMs;

        frame.style.width = `${scaledFrameWidth}px`;
        frame.style.height = `${scaledFrameHeight}px`;
        frame.style.aspectRatio = `${frameWidth} / ${frameHeight}`;
        preview.dataset.requestId = requestId;

        setText(camera, segment.dataset.cameraName || 'Camera');
        updateFocusLabels(scope, focusMs);

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
                applyScrubPreviewLayerFrame(latestActiveLayer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey);
                latestPreview.removeAttribute('hidden');

                return;
            }

            const nextLayerIndex = latestLayers.length > 1
                ? (latestActiveIndex === 0 ? 1 : 0)
                : latestActiveIndex;
            const nextLayer = latestLayers[nextLayerIndex] || latestActiveLayer;

            applyScrubPreviewLayerFrame(nextLayer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey);
            setActiveScrubPreviewLayer(latestFrame, latestLayers.indexOf(nextLayer));
            latestPreview.removeAttribute('hidden');
        };

        if (activeSpriteUrl === spriteUrl) {
            applyScrubPreviewLayerFrame(activeLayer, spriteUrl, frameIndex, frameCount, columns, rows, frameKey);
            preview.removeAttribute('hidden');
        } else if (state.scrubSpriteCache.has(spriteUrl)) {
            renderLoadedSprite();
        } else {
            if (hasActiveSprite) {
                preview.removeAttribute('hidden');
            }

            void preloadScrubSprite(spriteUrl, {
                expectedHeight: frameHeight * rows,
                expectedWidth: frameWidth * columns,
                priority: 'high',
            }).then((loaded) => {
                if (!loaded) {
                    return;
                }

                renderLoadedSprite();
            });
        }

        if (shell instanceof HTMLElement && viewport instanceof HTMLElement) {
            const focusOffsetPx = railTrackOffsetPxForMs(scope, focusMs) - viewport.scrollTop;
            const previewHeight = Math.max(preview.offsetHeight || 0, scaledFrameHeight + 64);
            const shellHeight = Math.max(shell.clientHeight, viewport.clientHeight, previewHeight + 24);
            const nextTop = clamp(focusOffsetPx - (previewHeight / 2), 12, Math.max(12, shellHeight - previewHeight - 12));

            preview.style.top = `${nextTop}px`;
        }

        preview.style.left = '';
        preview.style.right = '';
    };

    const shouldIgnoreMouseEvent = (event) => event instanceof MouseEvent
        && (typeof PointerEvent === 'undefined' || !(event instanceof PointerEvent))
        && (Date.now() - state.lastPointerEventAt) < 120;

    const isTouchPointerEvent = (event) => typeof PointerEvent !== 'undefined'
        && event instanceof PointerEvent
        && event.pointerType === 'touch';

    const updateLocalFocus = (scope, focusMs) => {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        scope.dataset.focusMs = String(Math.round(focusMs));

        updateFocusCursor(scope, focusMs);
        updateFocusLabels(scope, focusMs);
        setText(scope.querySelector('[data-role="rail-focus-time"]'), formatFocusLabel(focusMs, scope).slice(11, 19));
        updateClipProgress(scope);
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
        persistReviewInUrl(scope);
        const viewport = railViewport(scope);

        if (viewport instanceof HTMLElement) {
            state.viewportScrollTop = viewport.scrollTop;
            state.pendingViewportRestore = true;
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
            seekStageVideoToFocus(scope, video, pendingStageFocusMs(video, currentFocusMs(scope)));
            updateClipProgress(scope);
            applyStageControllerStateToVideo(scope, video);
        };

        const handleError = () => {
            if (!video.dataset.recordingId) return;
            const fallbackUrl = nextStageFallbackUrl(video);

            if (fallbackUrl === '') {
                clearStageSourceLoadWatchdog();
                clearPendingStageSourceLoad(video);
                setStageVideoLoadVisibility(video, true);
                setStageControllerState(scope, { isPlaying: false });
                setPlaybackNotice(scope, 'Playback could not start. Retry or choose another clip.', 'error');

                return;
            }

            loadStageVideoSource(scope, video, fallbackUrl, {
                resumeAt: Number(video.currentTime || 0),
                shouldPlay: stageControllerSnapshot(scope, video).isPlaying,
            });
        };

        const handleEnded = () => {
            if (readNumber(video, 'endMs', 0) >= timelineEndMs(scope)) {
                setStageControllerState(scope, { isPlaying: false });
                setPlaybackNotice(scope, 'End of the selected date range.', 'empty');
                return;
            }
            const nextFocus = clamp(
                readNumber(video, 'endMs', currentFocusMs(scope)),
                timelineStartMs(scope),
                timelineMaximumFocusMs(scope),
            );

            hideScrubPreview(scope);
            updateLocalFocus(scope, nextFocus);
            centerViewportOnFocus(scope, nextFocus);
            setStageControllerState(scope, {
                isPlaying: true,
            });
            void syncStageSegmentToFocus(scope, nextFocus, {
                fetchIfMissing: true,
                resumePlayback: true,
            });
            commitFocus(scope, nextFocus);
        };

        const handlePlay = () => {
            if (!video.dataset.recordingId) return;
            const playerState = syncStageControllerFromVideo(scope, video);
            syncCompanionAudioTime(video, stageAudio(scope), true);
            applyStageCompanionAudioState(scope, video, playerState, stageAudio(scope));
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handlePause = () => {
            if (state.clipScrub || !video.dataset.recordingId || stageSourceIsLoading(video)) return;
            const playerState = syncStageControllerFromVideo(scope, video);
            applyStageCompanionAudioState(scope, video, playerState, stageAudio(scope));
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleVolumeChange = () => {
            syncStageControllerFromVideo(scope, video);
        };

        const handleTimeUpdate = () => {
            if (state.drag || state.clipScrub || !video.dataset.recordingId || stageSourceIsLoading(video)) {
                return;
            }

            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleSeeked = () => {
            if (state.clipScrub || !video.dataset.recordingId) return;
            syncCompanionAudioTime(video, stageAudio(scope), true);
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        if (video.readyState >= 1) {
            seekToFocus();
        } else {
            video.addEventListener('loadedmetadata', seekToFocus, { once: true });
        }

        const handleWaiting = () => { if (video.dataset.recordingId) setPlaybackNotice(scope, 'Buffering recording…'); };
        const handleReady = () => {
            if (!video.dataset.recordingId) return;
            updateClipProgress(scope);
            clearStageSourceLoadWatchdog();
            setPlaybackNotice(scope);
        };
        const handleLoadStart = () => {
            clearStageSourceLoadWatchdog();
            if (!video.dataset.recordingId) return;
            setPlaybackNotice(scope, 'Loading recording…');
            state.stageSourceLoadTimer = window.setTimeout(() => {
                if (root() === scope && video.readyState < 2) {
                    setPlaybackNotice(scope, 'This recording is taking too long to load. Retry playback.', 'error');
                }
            }, 30000);
        };
        video.addEventListener('loadstart', handleLoadStart);
        video.addEventListener('waiting', handleWaiting);
        video.addEventListener('playing', handleReady);
        video.addEventListener('canplay', handleReady);
        video.addEventListener('error', handleError, true);
        video.addEventListener('ended', handleEnded);
        video.addEventListener('play', handlePlay);
        video.addEventListener('pause', handlePause);
        video.addEventListener('volumechange', handleVolumeChange);
        video.addEventListener('timeupdate', handleTimeUpdate);
        video.addEventListener('seeked', handleSeeked);

        state.boundVideo = video;
        state.boundVideoCleanup = () => {
            clearStageSourceLoadWatchdog();
            clearPendingStageSourceLoad(video);
            video.removeEventListener('waiting', handleWaiting);
            video.removeEventListener('playing', handleReady);
            video.removeEventListener('canplay', handleReady);
            video.removeEventListener('loadedmetadata', seekToFocus);
            video.removeEventListener('error', handleError, true);
            video.removeEventListener('loadstart', handleLoadStart);
            video.removeEventListener('ended', handleEnded);
            video.removeEventListener('play', handlePlay);
            video.removeEventListener('pause', handlePause);
            video.removeEventListener('volumechange', handleVolumeChange);
            video.removeEventListener('timeupdate', handleTimeUpdate);
            video.removeEventListener('seeked', handleSeeked);

            // Navigation or DOM replacement can leave an old media element detached.
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

        if (typeof PointerEvent !== 'undefined' && event instanceof PointerEvent) {
            state.lastPointerEventAt = Date.now();
        }

        const scope = rootFor(event.target);
        if (scope && event.target instanceof HTMLInputElement && event.target.dataset.role === 'clip-seek') {
            if (!event.target.disabled) beginClipScrub(scope);
            return;
        }
        const viewport = event.target instanceof Element ? event.target.closest('[data-role="rail-viewport"]') : null;
        const scrubbable = event.target instanceof Element
            ? event.target.closest('[data-role="rail-viewport"], [data-role="rail-track"], [data-role="rail-segments"], [data-role="rail-segment"], [data-role="rail-tick"], [data-role="focus-cursor"]')
            : null;
        const focusHandle = event.target instanceof Element
            ? event.target.closest('[data-role="focus-cursor"]')
            : null;

        if (!(scope instanceof HTMLElement) || !(viewport instanceof HTMLElement) || !(scrubbable instanceof HTMLElement)) {
            return;
        }

        // Touch gestures on the rail belong to native inertial scrolling. Scrubbing
        // remains available from the explicit blue focus handle, while clips and
        // time labels retain their normal tap/click behavior.
        if (isTouchPointerEvent(event) && !(focusHandle instanceof HTMLElement)) {
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

        if (event.cancelable) {
            event.preventDefault();
        }
    };

    const cancelDrag = (event) => {
        if (!state.drag) {
            return;
        }

        const scope = state.drag.root;
        const viewport = state.drag.viewport;

        if (typeof viewport.releasePointerCapture === 'function' && Number.isFinite(Number(event?.pointerId))) {
            try {
                viewport.releasePointerCapture(event.pointerId);
            } catch (error) {
            }
        }

        delete viewport.dataset.dragging;
        state.drag = null;
        hideScrubPreview(scope);
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
        void syncStageSegmentToFocus(scope, nextFocus, {
            fetchIfMissing: true,
        });
        state.drag = null;
        commitFocus(scope, nextFocus);
    };

    const handleKeyDown = (event) => {
        if (!(event.target instanceof Element) || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const cursor = event.target.closest('[data-role="focus-cursor"]');
        const scope = rootFor(event.target);

        if (!(cursor instanceof HTMLElement) || !(scope instanceof HTMLElement)) {
            return;
        }

        const focusMs = currentFocusMs(scope);
        const arrowStepMs = event.shiftKey ? 60_000 : 1_000;
        let nextFocus = focusMs;

        if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
            nextFocus -= arrowStepMs;
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
            nextFocus += arrowStepMs;
        } else if (event.key === 'PageUp') {
            nextFocus -= 15 * 60_000;
        } else if (event.key === 'PageDown') {
            nextFocus += 15 * 60_000;
        } else if (event.key === 'Home') {
            nextFocus = timelineStartMs(scope);
        } else if (event.key === 'End') {
            nextFocus = timelineMaximumFocusMs(scope);
        } else {
            return;
        }

        nextFocus = clamp(nextFocus, timelineStartMs(scope), timelineMaximumFocusMs(scope));
        updateLocalFocus(scope, nextFocus);
        centerViewportOnFocus(scope, nextFocus);
        hideScrubPreview(scope);
        void syncStageSegmentToFocus(scope, nextFocus, {
            fetchIfMissing: true,
        });
        commitFocus(scope, nextFocus);
        event.preventDefault();
    };

    const handleInput = (event) => {
        const target = event.target;
        const scope = rootFor(target);
        if (scope && target instanceof HTMLInputElement && target.dataset.role === 'clip-seek') {
            seekWithinClip(scope, Number(target.value));
            if (!state.clipScrub) commitFocus(scope, currentFocusMs(scope));
            return;
        }

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

        const action = event.target.closest('button[data-role]')?.dataset.role;
        if (action === 'clip-detail') {
            applyZoomScale(scope, maximumZoomScale(scope), { anchorMs: currentFocusMs(scope) });
            event.preventDefault();
            return;
        }
        if (['center-focus', 'previous-clip', 'next-clip', 'seek-back', 'seek-forward', 'rail-retry', 'playback-retry', 'fullscreen'].includes(action)) {
            event.preventDefault();
            if (action === 'center-focus') {
                centerViewportOnFocus(scope, currentFocusMs(scope));
                scope.querySelector('[data-role="focus-cursor"]')?.focus({ preventScroll: true });
            } else if (action === 'previous-clip' || action === 'next-clip') {
                void navigateClip(scope, action === 'previous-clip' ? 'previous' : 'next');
            } else if (action === 'seek-back' || action === 'seek-forward') {
                selectFocus(scope, currentFocusMs(scope) + (action === 'seek-back' ? -10000 : 10000));
            } else if (action === 'rail-retry') {
                requestRailWindow(scope);
            } else if (action === 'playback-retry') {
                const video = stageVideo(scope);
                if (video?.dataset.recordingId && stageDirectStreamUrl(video)) {
                    setPlaybackNotice(scope, 'Loading recording…');
                    setStageControllerState(scope, { isPlaying: true });
                    loadStageVideoSource(scope, video, stageDirectStreamUrl(video), { resumeAt: video.currentTime, shouldPlay: true });
                } else selectFocus(scope, currentFocusMs(scope));
            } else {
                const viewer = scope.querySelector('.recording-review-focus__viewer');
                const video = stageVideo(scope);
                if (viewer?.requestFullscreen) {
                    viewer.requestFullscreen().catch(() => setPlaybackNotice(scope, 'Full screen is unavailable in this browser.', 'info'));
                } else if (video?.webkitEnterFullscreen) video.webkitEnterFullscreen();
            }
            return;
        }

        const tick = event.target.closest('[data-role="rail-tick"]');
        const segment = event.target.closest('[data-role="rail-segment"]');
        const thumbnail = event.target.closest('[data-role="rail-thumbnail"]');
        const playbackToggle = event.target.closest('[data-role="playback-toggle"]');
        const audioToggle = event.target.closest('[data-role="audio-toggle"]');
        const cameraSwitch = event.target.closest('[data-role="camera-switch"]');

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

        if (cameraSwitch instanceof HTMLButtonElement) {
            event.preventDefault();
            switchActiveCamera(scope, cameraSwitch);

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

            applyZoomScale(scope, nextZoomScale, {
                anchorMs: focusFromViewportOffset(scope, anchorOffsetY) ?? currentFocusMs(scope),
                anchorOffsetY,
            });

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
            void syncStageSegmentToFocus(scope, nextFocus, {
                fetchIfMissing: true,
            });
            commitFocus(scope, nextFocus);
            event.preventDefault();
        }
    };

    const destroy = () => {
        state.clipScrub = null;
        state.bootstrappedRoot = null;
        state.cleanupFns.forEach((cleanup) => cleanup());
        state.cleanupFns = [];

        cleanupViewportBinding();
        cleanupResizeObserver();

        abortPendingStageSegmentRequest();
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
        state.stageSegmentRequestId = 0;
        state.viewportScrollTop = null;
        state.zoomScale = null;
        state.stage = defaultStageControllerState();
        resetStagePrewarm();
        clearStageSourceLoadWatchdog();
    };

    const previewDiagnostics = (scope = root()) => {
        if (!(scope instanceof HTMLElement)) {
            return null;
        }

        readRailBootstrapData(scope);

        const segments = Array.isArray(state.rail.segments) ? state.rail.segments : [];

        return {
            activeCameraId: activeCameraId(scope),
            focusMs: currentFocusMs(scope),
            scrubPreviewVisible: state.scrubPreviewVisible,
            segmentCount: segments.length,
            segmentsMissingSprite: segments
                .filter((segment) => String(segment.scrubSpriteUrl || '') === '' || Number(segment.scrubFrameCount || 0) < 1)
                .map((segment) => ({
                    endMs: Number(segment.endMs || 0),
                    id: segment.id ?? null,
                    startMs: Number(segment.startMs || 0),
                    thumbnailUrl: String(segment.thumbnailUrl || ''),
                })),
            segmentsWithSprite: segments.filter((segment) => String(segment.scrubSpriteUrl || '') !== '' && Number(segment.scrubFrameCount || 0) > 0).length,
        };
    };

    const bootstrap = () => {
        const scope = root();
        if (scope && state.bootstrappedRoot === scope) return;
        destroy();

        if (!(scope instanceof HTMLElement)) {
            return;
        }

        state.bootstrappedRoot = scope;
        state.activeCameraId = activeCameraId(scope);
        updateLocalFocus(scope, currentFocusMs(scope));
        const fullscreen = scope.querySelector('[data-role="fullscreen"]');
        if (fullscreen) fullscreen.hidden = !document.fullscreenEnabled && !stageVideo(scope)?.webkitEnterFullscreen;
        bindRailViewport(scope);
        applyTimelineScale(scope);
        centerViewportOnFocus(scope, currentFocusMs(scope));
        refreshScope(scope);

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('pointermove', handlePointerMove);
        document.addEventListener('pointerup', clearDrag);
        document.addEventListener('pointerup', finishClipScrub);
        document.addEventListener('pointercancel', finishClipScrub);
        document.addEventListener('change', finishClipScrub);
        window.addEventListener('blur', finishClipScrub);
        document.addEventListener('pointercancel', cancelDrag);
        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('mousemove', handlePointerMove);
        document.addEventListener('mouseup', clearDrag);
        document.addEventListener('keydown', handleKeyDown, true);
        document.addEventListener('input', handleInput, true);
        document.addEventListener('click', handleClick, true);

        state.cleanupFns.push(() => document.removeEventListener('pointerdown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('pointermove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('pointerup', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('pointerup', finishClipScrub));
        state.cleanupFns.push(() => document.removeEventListener('pointercancel', finishClipScrub));
        state.cleanupFns.push(() => document.removeEventListener('change', finishClipScrub));
        state.cleanupFns.push(() => window.removeEventListener('blur', finishClipScrub));
        state.cleanupFns.push(() => document.removeEventListener('pointercancel', cancelDrag));
        state.cleanupFns.push(() => document.removeEventListener('mousedown', handlePointerDown));
        state.cleanupFns.push(() => document.removeEventListener('mousemove', handlePointerMove));
        state.cleanupFns.push(() => document.removeEventListener('mouseup', clearDrag));
        state.cleanupFns.push(() => document.removeEventListener('keydown', handleKeyDown, true));
        state.cleanupFns.push(() => document.removeEventListener('input', handleInput, true));
        state.cleanupFns.push(() => document.removeEventListener('click', handleClick, true));
    };

    window.BigBrothaRecordingReviewModule = {
        bootstrap,
        destroy,
        inspectPreviewState: () => previewDiagnostics(root()),
    };

    document.addEventListener('livewire:navigated', bootstrap);
    document.addEventListener('livewire:navigating', destroy);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    } else {
        bootstrap();
    }
})();
