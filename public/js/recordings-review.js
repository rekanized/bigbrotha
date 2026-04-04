(() => {
    if (window.BigBrothasRecordingReviewModule) {
        window.BigBrothasRecordingReviewModule.bootstrap();

        return;
    }

    const state = {
        activeCameraId: null,
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
        scrubPreviewFocusMs: null,
        scrubPreviewRequestId: 0,
        scrubSpriteCache: new Map(),
        scrubSpritePending: new Map(),
        scrubPreviewVisible: false,
        stageMuted: null,
        stagePaused: null,
        stageVolume: 1,
        pendingViewportRestore: false,
        viewportScrollTop: null,
        zoomScale: null,
        zoomSyncTimer: null,
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const clampVolume = (value) => clamp(Number(value) || 0, 0, 1);
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
    const timelineHours = (scope) => Math.max(1, timelineDurationMs(scope) / 3600000);
    const timelineMaximumFocusMs = (scope) => Math.max(timelineStartMs(scope), timelineEndMs(scope) - 1000);
    const currentFocusMs = (scope) => clamp(readNumber(scope, 'focusMs', timelineStartMs(scope)), timelineStartMs(scope), timelineMaximumFocusMs(scope));
    const activeCameraId = (scope) => scope instanceof HTMLElement ? String(scope.dataset.activeCameraId || '') : '';
    const railBaseHourHeightPx = (scope) => Math.max(1, readNumber(scope, 'railBaseHourHeightPx', 88));
    const railMinTrackHeightPx = (scope) => Math.max(1, readNumber(scope, 'railMinTrackHeightPx', 1800));
    const minimumZoomScale = (scope) => Math.max(0.25, readNumber(scope, 'zoomMinScale', 1));
    const maximumZoomScale = (scope) => Math.max(minimumZoomScale(scope), readNumber(scope, 'zoomMaxScale', 8));
    const zoomStepFactor = (scope) => Math.max(1.01, readNumber(scope, 'zoomStepFactor', 1.18));
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

        const track = railTrack(scope);
        const viewport = railViewport(scope);

        if (!(track instanceof HTMLElement)) {
            return;
        }

        const zoomScale = setCurrentZoomScale(scope, currentZoomScale(scope));
        const nextTrackHeight = scaledTrackHeight(scope, zoomScale);

        track.style.height = `${nextTrackHeight}px`;
        track.style.setProperty('--recording-review-zoom-scale', String(zoomScale));
        layoutRailSegments(scope);
        layoutRailThumbnails(scope);

        if (viewport instanceof HTMLElement) {
            const restoredScrollTop = state.pendingViewportRestore && Number.isFinite(state.viewportScrollTop)
                ? Number(state.viewportScrollTop)
                : viewport.scrollTop;

            viewport.scrollTop = clamp(restoredScrollTop, 0, Math.max(0, nextTrackHeight - viewport.clientHeight));
            state.viewportScrollTop = viewport.scrollTop;
            state.pendingViewportRestore = false;
        }

        updateVisibleRange(scope);
        updateFocusCursor(scope, currentFocusMs(scope));
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
        updateVisibleRange(scope);
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

        const nextActiveCameraId = activeCameraId(scope);
        const cameraDidChange = state.activeCameraId !== null && state.activeCameraId !== nextActiveCameraId;

        withObserverPaused(() => {
            if (cameraDidChange) {
                state.pendingViewportRestore = false;
                state.viewportScrollTop = null;
            }

            bindRailViewport(scope);
            applyTimelineScale(scope);
            bindStageVideo(scope);
            syncStagePlaybackState(scope);
            syncStageAudioState(scope);
            updateStageVolumeUi(scope);
            updateFocusCursor(scope, currentFocusMs(scope));
            warmScrubSprites(scope);

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
            component.call('selectFocus', Math.round(focusMs), normalizedZoomScale(scope));
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
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handlePause = () => {
            if (!video.ended) {
                state.stagePaused = true;
            }

            syncStagePlaybackUi(scope, true);
            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleTimeUpdate = () => {
            if (state.drag) {
                return;
            }

            updateLocalFocus(scope, focusMsForVideoPlayback(scope, video));
        };

        const handleSeeked = () => {
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
        video.addEventListener('timeupdate', handleTimeUpdate);
        video.addEventListener('seeked', handleSeeked);

        state.boundVideo = video;
        state.boundVideoCleanup = () => {
            video.removeEventListener('error', handleError);
            video.removeEventListener('ended', handleEnded);
            video.removeEventListener('play', handlePlay);
            video.removeEventListener('pause', handlePause);
            video.removeEventListener('timeupdate', handleTimeUpdate);
            video.removeEventListener('seeked', handleSeeked);
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
        state.drag = null;
        commitFocus(scope, nextFocus);
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

        cleanupViewportBinding();

        if (state.observer) {
            state.observer.disconnect();
            state.observer = null;
        }

        state.observerScope = null;

        cleanupVideoBinding();
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