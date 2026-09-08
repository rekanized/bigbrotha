(() => {
    if (window.BigBrothaLiveWallPlayerModule) {
        window.BigBrothaLiveWallPlayerModule.bootstrap();

        return;
    }

    const state = {
        activeAudioPlayer: null,
        focusedTile: null,
        focusReturnTarget: null,
        focusScrollPosition: null,
        pointerStart: null,
        lastTap: {
            tile: null,
            time: 0,
        },
        lastTouchFocusToggle: {
            tile: null,
            time: 0,
        },
        masterVolume: 1,
        players: [],
        managedPlayers: new Set(),
        readerScriptPromise: null,
        readerScriptUrl: '',
        documentSuspendTimeout: null,
        healthCheckInterval: null,
        intersectionObserver: null,
        reconnectBaseDelayMs: 5000,
        reconnectMaxDelayMs: 30000,
    };

    const offscreenSuspendDelayMs = 30000;
    const hiddenPageSuspendDelayMs = 10000;
    const stalledVideoThresholdMs = 20000;
    const connectionTimeoutMs = 65000;

    const focusableTileSelector = '[data-live-wall-grid] .wall-monitor-tile';

    const normalizeCodecName = (value, fallback) => {
        if (typeof value !== 'string') {
            return fallback;
        }

        const normalized = value.trim().toLowerCase();

        return normalized !== '' ? normalized : fallback;
    };

    const readPositiveInteger = (value, fallback) => {
        const normalized = Number(value);

        return Number.isInteger(normalized) && normalized > 0 ? normalized : fallback;
    };

    const displayCodecName = (codec) => {
        const normalized = normalizeCodecName(codec, '');

        return normalized === 'h264' ? 'H.264' : normalized === 'opus' ? 'Opus' : normalized.toUpperCase();
    };

    const clearFocusedTile = () => {
        const returnTarget = state.focusReturnTarget;
        const scrollPosition = state.focusScrollPosition;
        state.focusReturnTarget = null;
        state.focusScrollPosition = null;

        document.querySelectorAll(focusableTileSelector).forEach((tile) => {
            tile.dataset.layoutState = 'grid';
            tile.inert = false;
            tile.removeAttribute('role');
            tile.removeAttribute('aria-modal');
            tile.removeAttribute('aria-label');
            const button = tile.querySelector('[data-role="focus-toggle"]');
            button?.setAttribute('aria-pressed', 'false');
            button?.setAttribute('aria-label', `Focus ${tile.dataset.cameraName}`);
            if (button) button.title = 'Focus camera';
        });

        document.querySelectorAll('[data-live-wall-grid]').forEach((grid) => {
            grid.dataset.layoutMode = 'grid';
        });

        document.body.classList.remove('live-wall-focus-mode');
        document.querySelectorAll('.global-header, .live-wall-wall-switcher').forEach(element => { element.inert = false; });
        if (returnTarget?.isConnected) returnTarget.focus({ preventScroll: true });
        if (scrollPosition) window.scrollTo({ ...scrollPosition, behavior: 'instant' });
        state.focusedTile = null;
        state.players.forEach((player, index) => player.setFocusEligible(true, Math.min(1800, index * 150)));
    };

    const setFocusedTile = (tile) => {
        if (!(tile instanceof HTMLElement)) {
            clearFocusedTile();

            return;
        }

        if (!tile.querySelector('[data-role="focus-toggle"]')) return;
        state.focusScrollPosition = { left: window.scrollX, top: window.scrollY };

        document.querySelectorAll(focusableTileSelector).forEach((candidate) => {
            candidate.dataset.layoutState = candidate === tile ? 'focused' : 'dimmed';
            candidate.inert = candidate !== tile;
        });

        document.querySelectorAll('[data-live-wall-grid]').forEach((grid) => {
            grid.dataset.layoutMode = 'focused';
        });

        state.focusReturnTarget = document.activeElement;
        tile.setAttribute('role', 'dialog');
        tile.setAttribute('aria-modal', 'true');
        tile.setAttribute('aria-label', `${tile.dataset.cameraName} focused camera`);
        const button = tile.querySelector('[data-role="focus-toggle"]');
        button?.setAttribute('aria-pressed', 'true');
        button?.setAttribute('aria-label', 'Return to camera grid');
        if (button) button.title = 'Return to camera grid (Escape)';
        document.body.classList.add('live-wall-focus-mode');
        document.querySelectorAll('.global-header, .live-wall-wall-switcher').forEach(element => { element.inert = true; });
        button?.focus({ preventScroll: true });
        state.focusedTile = tile;
        state.players.forEach((player) => player.setFocusEligible(player.tile === tile));
    };

    const toggleFocusedTile = (tile) => {
        if (!(tile instanceof HTMLElement)) {
            return;
        }

        if (state.focusedTile === tile) {
            clearFocusedTile();

            return;
        }

        setFocusedTile(tile);
    };

    const isInteractiveTarget = (target) => {
        if (!(target instanceof Element)) {
            return false;
        }

        return Boolean(target.closest('button, a, input, label, summary, details'));
    };

    const handleTileDoubleClick = (event) => {
        const tile = event.target instanceof Element ? event.target.closest('.wall-monitor-tile') : null;

        if (!(tile instanceof HTMLElement) || isInteractiveTarget(event.target)) {
            return;
        }

        const recentTouchToggle = state.lastTouchFocusToggle.tile === tile
            && (Date.now() - state.lastTouchFocusToggle.time) <= 450;

        if (recentTouchToggle) {
            state.lastTouchFocusToggle = {
                tile: null,
                time: 0,
            };

            return;
        }

        toggleFocusedTile(tile);
    };

    const handleTilePointerDown = (event) => {
        state.pointerStart = { id: event.pointerId, x: event.clientX, y: event.clientY, time: Date.now() };
    };

    const handleTilePointerUp = (event) => {
        if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
        const start = state.pointerStart;
        state.pointerStart = null;
        const tile = event.target instanceof Element ? event.target.closest(focusableTileSelector) : null;
        const now = Date.now();
        // A scroll, long press, or cancelled gesture must never focus a camera.
        if (!start || start.id !== event.pointerId || now - start.time > 320
            || Math.hypot(event.clientX - start.x, event.clientY - start.y) > 12
            || !tile || isInteractiveTarget(event.target)) {
            state.lastTap = { tile: null, time: 0 };
            return;
        }
        const repeated = state.lastTap.tile === tile && now - state.lastTap.time <= 320
            && Math.hypot(event.clientX - state.lastTap.x, event.clientY - state.lastTap.y) <= 24;
        state.lastTap = { tile, time: now, x: event.clientX, y: event.clientY };
        if (repeated) {
            toggleFocusedTile(tile);
            state.lastTouchFocusToggle = { tile, time: now };
            state.lastTap = { tile: null, time: 0 };
        }
    };

    const handleWallClick = (event) => {
        if (!(event.target instanceof Element)) return;
        const button = event.target.closest('[data-role="focus-toggle"]');
        if (button) toggleFocusedTile(button.closest(focusableTileSelector));
        document.querySelectorAll('.live-wall-wall-switcher__mobile-drawer[open]').forEach(drawer => {
            if (!drawer.contains(event.target)) drawer.open = false;
        });
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            const drawer = document.querySelector('.live-wall-wall-switcher__mobile-drawer[open]');
            if (drawer) {
                drawer.open = false;
                drawer.querySelector('summary')?.focus();
            } else if (state.focusedTile) {
                clearFocusedTile();
            }
        }
        if (event.key === 'Tab' && state.focusedTile) {
            const controls = [...state.focusedTile.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled)')];
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first?.focus();
            }
        }
    };

    const clampVolume = (value) => {
        const normalized = Number(value);

        if (!Number.isFinite(normalized)) {
            return 1;
        }

        return Math.min(1, Math.max(0, normalized));
    };

    const syncAudioSelection = () => {
        state.players.forEach((player) => player.applyAudioSelection());
    };

    const setActiveAudioPlayer = (player) => {
        if (state.activeAudioPlayer === player) {
            return;
        }

        const previousPlayer = state.activeAudioPlayer;
        state.activeAudioPlayer = player;
        syncAudioSelection();

        if (previousPlayer) {
            previousPlayer.setIntersecting(previousPlayer.isIntersecting);
        }
    };

    const setMasterVolume = (value) => {
        state.masterVolume = clampVolume(value);
        updateMasterVolumeUi();
        syncAudioSelection();
    };

    const updateMasterVolumeUi = () => {
        const volumePercent = Math.round(state.masterVolume * 100);

        document.querySelectorAll('[data-role="master-volume-slider"]').forEach((element) => {
            if (element instanceof HTMLInputElement) {
                element.value = String(volumePercent);
            }
        });

        document.querySelectorAll('[data-role="master-volume-value"]').forEach((element) => {
            element.textContent = `${volumePercent}%`;
        });
    };

    const handleMasterVolumeInput = (event) => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement) || target.dataset.role !== 'master-volume-slider') {
            return;
        }

        setMasterVolume(Number(target.value) / 100);
    };

    const handlePlaybackUnlock = () => {
        state.managedPlayers.forEach((player) => player.resumeAfterUserActivation());
    };

    class BigBrothaWhepPlayer {
        constructor(root) {
            this.root = root;
            const bootstrapContainer = root.closest('[data-reader-url], [data-whep-url], [data-access-token], [data-access-token-expires-in], [data-access-token-issued-at]');
            this.bootstrapUrl = root.dataset.sessionUrl || bootstrapContainer?.dataset.sessionUrl || '';
            this.bootstrapReaderUrl = root.dataset.readerUrl || bootstrapContainer?.dataset.readerUrl || '';
            this.bootstrapWhepUrl = root.dataset.whepUrl || bootstrapContainer?.dataset.whepUrl || '';
            this.bootstrapAccessToken = root.dataset.accessToken || bootstrapContainer?.dataset.accessToken || '';
            this.bootstrapAccessTokenExpiresIn = root.dataset.accessTokenExpiresIn || bootstrapContainer?.dataset.accessTokenExpiresIn || '';
            this.bootstrapAccessTokenIssuedAt = root.dataset.accessTokenIssuedAt || bootstrapContainer?.dataset.accessTokenIssuedAt || '';
            this.label = root.dataset.playerLabel || 'camera';
            this.expectedVideoCodec = normalizeCodecName(root.dataset.expectedVideoCodec, 'h264');
            this.expectedAudioCodec = normalizeCodecName(root.dataset.expectedAudioCodec, 'opus');
            this.expectedAudioChannels = readPositiveInteger(root.dataset.expectedAudioChannels, 2);
            this.expectedAudioSampleRate = readPositiveInteger(root.dataset.expectedAudioSampleRate, 48000);
            this.video = root.querySelector('[data-role="video"]');
            this.message = root.querySelector('[data-role="message"]');
            this.tile = root.closest('.wall-monitor-tile');
            this.audioToggle = root.querySelector('[data-role="audio-toggle"]');
            this.audioToggleLabel = root.querySelector('[data-role="audio-toggle-label"]');
            this.audioIndicator = root.querySelector('[data-role="audio-indicator"]');
            this.isAudioSelectable = this.audioToggle !== null && this.audioIndicator !== null;
            this.hasStream = false;
            this.hasVideoTrack = false;
            this.hasPresentedVideo = false;
            this.hasAudioTrack = false;
            this.mediaStream = new MediaStream();
            this.reader = null;
            this.closed = false;
            this.hasRetriedFreshSession = false;
            this.awaitingUserActivation = false;
            this.retryTimeout = null;
            this.initialStartTimeout = null;
            this.offscreenSuspendTimeout = null;
            this.sessionAbortController = null;
            this.connectionAttempt = 0;
            this.failureCount = 0;
            this.suspendReasons = new Set();
            this.isIntersecting = true;
            this.lastVideoTime = 0;
            this.lastVideoProgressAt = Date.now();
            this.connectionStartedAt = null;
            this.videoFrameCallback = null;
            this.lastDecodedFrames = 0;

            this.handleAudioToggle = this.handleAudioToggle.bind(this);
            this.handleNativeVolumeChange = () => {
                if (!this.video?.controls || !this.hasAudioTrack || this.closed) return;
                if (this.video.muted) {
                    if (state.activeAudioPlayer === this) setActiveAudioPlayer(null);
                } else {
                    setMasterVolume(this.video.volume);
                    setActiveAudioPlayer(this);
                }
            };
            this.handlePlaybackState = (event) => {
                if (event.type === 'playing') this.lastVideoProgressAt = Date.now();
                this.updateStatusMessage();
            };
            this.video?.addEventListener('volumechange', this.handleNativeVolumeChange);
            this.video?.addEventListener('pause', this.handlePlaybackState);
            this.video?.addEventListener('playing', this.handlePlaybackState);

            if (this.isAudioSelectable) {
                this.audioToggle.addEventListener('click', this.handleAudioToggle);
            }
        }

        start(delayMs = 0) {
            if (this.bootstrapUrl === '' || this.video === null || this.message === null) {
                return;
            }

            this.closed = false;
            state.managedPlayers.add(this);
            this.root.bigBrothaWhepPlayer = this;
            this.clearRetryTimeout();

            this.hasStream = false;
            this.hasVideoTrack = false;
            this.hasAudioTrack = false;
            this.awaitingUserActivation = false;

            if (this.video) {
                this.video.autoplay = true;
                this.video.defaultMuted = true;
                this.video.muted = true;
                this.video.playsInline = true;
            }

            if (this.isAudioSelectable) {
                this.syncAudioUi();
            }

            if (this.root.dataset.playerLifecycle === 'viewport' && state.intersectionObserver) {
                state.intersectionObserver.observe(this.root);
            }

            if (document.hidden) {
                this.suspendReasons.add('document');
                this.setMessage('Paused while the live wall is in the background.');
            }

            if (!navigator.onLine) {
                this.suspendReasons.add('offline');
                this.setMessage('Paused while the browser is offline.');
            }

            this.initialStartTimeout = window.setTimeout(() => {
                this.initialStartTimeout = null;
                this.connectIfEligible();
            }, Math.max(0, delayMs));
        }

        async connect() {
            this.destroyConnection();
            const attempt = ++this.connectionAttempt;
            this.connectionStartedAt = Date.now();
            this.setMessage('Loading camera feed...');

            const bootstrapSession = this.readBootstrapSession();
            const hintedReaderUrl = bootstrapSession?.reader_url || this.bootstrapReaderUrl;
            const hintedReaderLoad = hintedReaderUrl !== ''
                ? this.loadReaderScript(hintedReaderUrl)
                : Promise.resolve();

            // Observe both promises immediately, including a script failure
            // while the authenticated session request is still pending.
            const [session] = await Promise.all([
                bootstrapSession || this.fetchSession(attempt),
                hintedReaderLoad,
            ]);

            if (!this.isCurrentAttempt(attempt)) {
                return;
            }

            const readerUrl = session.reader_url || this.deriveReaderUrl(session.whep_url);

            if (hintedReaderUrl === '' || hintedReaderUrl !== readerUrl) {
                await this.loadReaderScript(readerUrl);
            } else {
                await hintedReaderLoad;
            }

            if (!this.isCurrentAttempt(attempt)) {
                return;
            }

            if (typeof window.MediaMTXWebRTCReader !== 'function') {
                throw new Error('The MediaMTX WebRTC reader could not be loaded.');
            }

            const usedBootstrapSession = bootstrapSession !== null && session === bootstrapSession;

            let reader = null;
            reader = new window.MediaMTXWebRTCReader({
                url: session.whep_url,
                token: session.access_token,
                onError: (error) => {
                    if (reader && this.isCurrentAttempt(attempt) && this.reader === reader) {
                        this.handleReaderError(error, usedBootstrapSession, attempt);
                    }
                },
                onTrack: (event) => {
                    if (reader && this.isCurrentAttempt(attempt) && this.reader === reader) {
                        this.handleTrack(event);
                    }
                },
            });
            this.reader = reader;
        }

        async fetchSession(attempt) {
            this.sessionAbortController = new AbortController();
            const response = await fetch(this.bootstrapUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: this.sessionAbortController.signal,
            });

            if (!this.isCurrentAttempt(attempt)) {
                throw new DOMException('Superseded player session.', 'AbortError');
            }

            if (!response.ok) {
                throw new Error(await this.readError(response, 'The secure player session could not be started.'));
            }

            const session = await response.json();
            this.applyStreamHints(session?.stream || null);

            return session;
        }

        readBootstrapSession() {
            if (this.hasRetriedFreshSession) {
                return null;
            }

            if (this.bootstrapWhepUrl === '' || this.bootstrapAccessToken === '') {
                return null;
            }

            if (this.bootstrapSessionIsStale()) {
                return null;
            }

            return {
                whep_url: this.bootstrapWhepUrl,
                reader_url: this.bootstrapReaderUrl || this.deriveReaderUrl(this.bootstrapWhepUrl),
                access_token: this.bootstrapAccessToken,
            };
        }

        handleReaderError(error, usedBootstrapSession, attempt) {
            const normalizedError = error instanceof Error ? error : new Error(String(error));

            if (usedBootstrapSession && !this.hasRetriedFreshSession && this.shouldRetryWithFreshSession(normalizedError)) {
                this.hasRetriedFreshSession = true;
                this.connectIfEligible();

                return;
            }

            this.handleFailure(normalizedError, attempt);
        }

        handleTrack(event) {
            if (this.closed || this.video === null) {
                return;
            }

            this.mergeIncomingTrack(event);

            if (this.video.srcObject !== this.mediaStream) {
                this.video.srcObject = this.mediaStream;
            }

            this.refreshTrackState();

            if (this.hasVideoTrack) {
                this.lastVideoTime = this.video.currentTime;
                this.lastVideoProgressAt = Date.now();
                this.watchVideoFrames();
            }

            if (this.isAudioSelectable) {
                this.applyAudioSelection();
            }

            const attempt = this.connectionAttempt;
            this.tryPlay().catch((error) => {
                this.handleFailure(error, attempt);
            });
        }

        handleAudioToggle() {
            if (!this.isAudioSelectable || this.video === null || !this.hasAudioTrack) {
                return;
            }

            if (state.activeAudioPlayer === this) {
                setActiveAudioPlayer(null);

                return;
            }

            setActiveAudioPlayer(this);
            this.tryPlay().catch(() => undefined);
        }

        mergeIncomingTrack(event) {
            const incomingTracks = [];

            if (event.track instanceof MediaStreamTrack) {
                incomingTracks.push(event.track);
            }

            if (Array.isArray(event.streams)) {
                event.streams.forEach((stream) => {
                    stream.getTracks().forEach((track) => incomingTracks.push(track));
                });
            }

            incomingTracks.forEach((track) => {
                const alreadyAttached = this.mediaStream.getTracks().some((existingTrack) => existingTrack.id === track.id);

                if (!alreadyAttached) {
                    this.mediaStream.addTrack(track);
                    const attachedAttempt = this.connectionAttempt;
                    track.addEventListener('ended', () => {
                        this.removeTrack(track.id);

                        if (track.kind === 'video' && this.isCurrentAttempt(attachedAttempt)) {
                            this.handleFailure(new Error(`${this.label} stopped delivering its video track.`), attachedAttempt);
                        }
                    }, { once: true });
                }
            });
        }

        removeTrack(trackId) {
            const track = this.mediaStream.getTracks().find((currentTrack) => currentTrack.id === trackId);

            if (track) {
                this.mediaStream.removeTrack(track);
            }

            this.refreshTrackState();
            this.applyAudioSelection();
        }

        refreshTrackState() {
            const tracks = this.mediaStream.getTracks();

            this.hasStream = tracks.length > 0;
            this.hasVideoTrack = tracks.some((track) => track.kind === 'video' && track.readyState === 'live');
            this.hasAudioTrack = tracks.some((track) => track.kind === 'audio' && track.readyState === 'live');

            if (state.activeAudioPlayer === this && !this.hasAudioTrack) {
                state.activeAudioPlayer = null;
            }

            this.updateStatusMessage();
        }

        applyAudioSelection() {
            if (!this.isAudioSelectable || this.video === null) {
                return;
            }

            const isActive = state.activeAudioPlayer === this && this.hasAudioTrack;

            if (this.video.defaultMuted !== !isActive) this.video.defaultMuted = !isActive;
            if (this.video.muted !== !isActive) this.video.muted = !isActive;
            // Preserve the native volume slider while muted so unmuting remains audible.
            if (this.video.volume !== state.masterVolume) this.video.volume = state.masterVolume;

            if (isActive) {
                this.tryPlay().catch(() => undefined);
            }

            this.syncAudioUi();
        }

        syncAudioUi() {
            if (!this.isAudioSelectable || this.audioToggle === null || this.audioIndicator === null) {
                return;
            }

            const isActive = state.activeAudioPlayer === this && this.hasAudioTrack;
            const nextState = isActive ? 'active' : 'muted';

            this.root.dataset.audioState = nextState;

            if (this.tile) {
                this.tile.dataset.audioState = nextState;
            }

            if (!this.hasStream) {
                this.audioIndicator.textContent = 'Connecting';
            } else if (!this.hasVideoTrack) {
                this.audioIndicator.textContent = `Waiting for ${displayCodecName(this.expectedVideoCodec)}`;
            } else if (!this.hasAudioTrack) {
                this.audioIndicator.textContent = 'No audio';
            } else {
                this.audioIndicator.textContent = isActive ? 'Audio selected' : 'Muted';
            }

            this.audioToggle.disabled = !this.hasAudioTrack;
            this.audioToggle.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            this.audioToggle.setAttribute('aria-label', isActive ? `Stop listening to ${this.label}` : `Listen to ${this.label}`);

            if (this.audioToggleLabel) {
                if (!this.hasAudioTrack) {
                    this.audioToggleLabel.textContent = 'Audio unavailable';
                } else {
                    this.audioToggleLabel.textContent = isActive ? 'Mute audio' : 'Enable audio';
                }
            }
        }

        handleFailure(error, attempt = null) {
            if (this.closed || this.isAbortError(error) || (attempt !== null && !this.isCurrentAttempt(attempt))) {
                return;
            }

            this.destroyConnection();
            this.failureCount += 1;
            const failureMessage = error instanceof Error ? error.message : `Unable to play ${this.label}.`;
            const reconnectDelay = this.reconnectDelay();
            this.setMessage(`${failureMessage} Retrying automatically in ${Math.ceil(reconnectDelay / 1000)} seconds.`);
            this.syncAudioUi();
            this.scheduleReconnect(reconnectDelay);
        }

        destroyConnection() {
            this.hasPresentedVideo = false;
            this.clearRetryTimeout();
            this.connectionAttempt += 1;
            this.connectionStartedAt = null;

            if (this.videoFrameCallback !== null) {
                this.video?.cancelVideoFrameCallback?.(this.videoFrameCallback);
                this.videoFrameCallback = null;
            }
            this.lastDecodedFrames = 0;

            if (this.sessionAbortController) {
                this.sessionAbortController.abort();
                this.sessionAbortController = null;
            }

            if (this.reader && typeof this.reader.close === 'function') {
                this.reader.close();
                this.reader = null;
            }

            if (this.video && this.video.srcObject) {
                this.video.srcObject = null;
            }

            this.mediaStream.getTracks().forEach((track) => {
                this.mediaStream.removeTrack(track);
                track.stop();
            });

            this.hasStream = false;
            this.hasVideoTrack = false;
            this.hasAudioTrack = false;
            this.awaitingUserActivation = false;
        }

        close() {
            this.closed = true;
            state.managedPlayers.delete(this);
            this.video?.removeEventListener?.('volumechange', this.handleNativeVolumeChange);
            this.video?.removeEventListener?.('pause', this.handlePlaybackState);
            this.video?.removeEventListener?.('playing', this.handlePlaybackState);
            this.clearRetryTimeout();

            if (this.initialStartTimeout !== null) {
                window.clearTimeout(this.initialStartTimeout);
                this.initialStartTimeout = null;
            }

            if (this.offscreenSuspendTimeout !== null) {
                window.clearTimeout(this.offscreenSuspendTimeout);
                this.offscreenSuspendTimeout = null;
            }

            state.intersectionObserver?.unobserve(this.root);
            delete this.root.bigBrothaWhepPlayer;

            if (this.isAudioSelectable) {
                this.audioToggle.removeEventListener('click', this.handleAudioToggle);
            }

            if (state.activeAudioPlayer === this) {
                state.activeAudioPlayer = null;
            }

            this.destroyConnection();
            this.syncAudioUi();
        }

        resumeAfterUserActivation() {
            if (!this.awaitingUserActivation || this.closed) {
                return;
            }

            this.tryPlay().catch(() => undefined);
        }

        async tryPlay() {
            if (this.video === null) {
                return false;
            }

            const attempt = this.connectionAttempt;

            try {
                await this.video.play();
                if (!this.isCurrentAttempt(attempt)) {
                    return false;
                }
                this.awaitingUserActivation = false;
                this.updateStatusMessage();

                return true;
            } catch (error) {
                if (!this.isCurrentAttempt(attempt)) {
                    return false;
                }
                if (this.isAutoplayBlocked(error)) {
                    this.awaitingUserActivation = true;
                    this.updateStatusMessage();

                    return false;
                }

                throw error;
            }
        }

        connectIfEligible() {
            if (this.closed || this.suspendReasons.size > 0 || !navigator.onLine) {
                return;
            }

            const connection = this.connect();
            const attempt = this.connectionAttempt;
            connection.catch((error) => this.handleFailure(error, attempt));
        }

        suspend(reason, message) {
            const wasEligible = this.suspendReasons.size === 0;
            this.suspendReasons.add(reason);

            if (wasEligible) {
                if (this.initialStartTimeout !== null) {
                    window.clearTimeout(this.initialStartTimeout);
                    this.initialStartTimeout = null;
                }

                if (state.activeAudioPlayer === this) {
                    state.activeAudioPlayer = null;
                    syncAudioSelection();
                }

                this.destroyConnection();
                this.setMessage(message);
                this.syncAudioUi();
            }
        }

        resume(reason, delayMs = 0) {
            const wasSuspendedForReason = this.suspendReasons.delete(reason);

            if (wasSuspendedForReason && !this.closed && this.suspendReasons.size === 0) {
                this.failureCount = 0;

                if (this.initialStartTimeout !== null) {
                    window.clearTimeout(this.initialStartTimeout);
                }

                this.initialStartTimeout = window.setTimeout(() => {
                    this.initialStartTimeout = null;
                    this.connectIfEligible();
                }, Math.max(0, delayMs));
            }
        }

        setFocusEligible(isEligible, delayMs = 0) {
            if (isEligible) {
                this.resume('focus', delayMs);
            } else {
                this.suspend('focus', 'Paused while another camera is focused.');
            }
        }

        setIntersecting(isIntersecting) {
            if (isIntersecting && !this.isIntersecting) this.lastVideoProgressAt = Date.now();
            this.isIntersecting = isIntersecting;

            if (this.offscreenSuspendTimeout !== null) {
                window.clearTimeout(this.offscreenSuspendTimeout);
                this.offscreenSuspendTimeout = null;
            }

            if (isIntersecting) {
                this.resume('offscreen');

                return;
            }

            if (state.activeAudioPlayer === this) {
                return;
            }

            this.offscreenSuspendTimeout = window.setTimeout(() => {
                this.offscreenSuspendTimeout = null;

                if (!this.isIntersecting && state.activeAudioPlayer !== this) {
                    this.suspend('offscreen', 'Paused while this camera is off screen.');
                }
            }, offscreenSuspendDelayMs);
        }

        watchVideoFrames() {
            if (this.videoFrameCallback !== null || typeof this.video?.requestVideoFrameCallback !== 'function') {
                return;
            }

            const attempt = this.connectionAttempt;
            const onFrame = () => {
                if (!this.isCurrentAttempt(attempt)) {
                    return;
                }

                this.markVideoProgress(Date.now());
                this.videoFrameCallback = this.video.requestVideoFrameCallback(onFrame);
            };
            this.videoFrameCallback = this.video.requestVideoFrameCallback(onFrame);
        }

        markVideoProgress(now) {
            this.lastVideoProgressAt = now;
            this.failureCount = 0;
            if (!this.hasPresentedVideo) {
                this.hasPresentedVideo = true;
                this.updateStatusMessage();
            }
        }

        checkHealth(now) {
            if (this.closed || this.suspendReasons.size > 0 || !this.video || this.awaitingUserActivation || document.hidden) {
                return;
            }

            // Browsers may stop presenting frames outside the viewport while audio continues.
            if (this.root.dataset.playerLifecycle === 'viewport' && !this.isIntersecting) return;
            if (this.video.controls && this.video.paused && this.hasPresentedVideo) return;

            if (!this.hasVideoTrack) {
                if (this.connectionStartedAt !== null && now - this.connectionStartedAt >= connectionTimeoutMs) {
                    this.handleFailure(new Error(`${this.label} timed out while starting its video stream.`));
                }

                return;
            }

            // The media clock can keep advancing with audio while video is frozen.
            // Prefer actual presented/decoded frames over HTMLMediaElement.currentTime.
            if (typeof this.video.requestVideoFrameCallback !== 'function') {
                const quality = this.video.getVideoPlaybackQuality?.();
                const progress = quality ? quality.totalVideoFrames - quality.droppedVideoFrames : this.video.currentTime;
                const previous = quality ? this.lastDecodedFrames : this.lastVideoTime;

                if (progress > previous) {
                    this.lastDecodedFrames = quality ? progress : this.lastDecodedFrames;
                    this.lastVideoTime = this.video.currentTime;
                    this.markVideoProgress(now);
                }
            }

            if ((now - this.lastVideoProgressAt) >= stalledVideoThresholdMs) {
                this.handleFailure(new Error(`${this.label} stopped delivering video frames.`));
            }
        }

        isCurrentAttempt(attempt) {
            return !this.closed && this.suspendReasons.size === 0 && this.connectionAttempt === attempt;
        }

        isAbortError(error) {
            return error?.name === 'AbortError';
        }

        reconnectDelay() {
            const exponent = Math.max(0, Math.min(3, this.failureCount - 1));
            const baseDelay = Math.min(state.reconnectMaxDelayMs, state.reconnectBaseDelayMs * (2 ** exponent));
            const jitter = 0.85 + (Math.random() * 0.3);

            return Math.round(baseDelay * jitter);
        }

        isAutoplayBlocked(error) {
            const errorName = typeof error?.name === 'string' ? error.name : '';
            const errorMessage = `${error instanceof Error ? error.message : error}`.toLowerCase();

            return errorName === 'NotAllowedError'
                || errorMessage.includes('notallowederror')
                || errorMessage.includes('autoplay')
                || errorMessage.includes('user gesture');
        }

        updateStatusMessage() {
            if (!this.message) {
                return;
            }

            if (!this.hasStream) {
                return;
            }

            if (!this.hasVideoTrack) {
                this.setMessage(`Waiting for ${displayCodecName(this.expectedVideoCodec)} video track…`);

                return;
            }

            if (this.awaitingUserActivation) {
                this.setMessage(this.autoplayBlockedMessage());

                return;
            }

            if (this.video?.paused && this.hasPresentedVideo) {
                this.setMessage('', 'Paused');
                return;
            }
            if (!this.hasPresentedVideo) {
                this.setMessage('Waiting for the first video frame…', 'Connecting');
                return;
            }
            this.setMessage('', 'Live');
        }

        autoplayBlockedMessage() {
            const audioHint = this.hasAudioTrack
                ? `${displayCodecName(this.expectedAudioCodec)} audio is ready. Interact once with the page to continue playback.`
                : 'Interact once with the page to continue playback.';

            return `${displayCodecName(this.expectedVideoCodec)} video is ready. ${audioHint}`;
        }

        applyStreamHints(stream) {
            if (!stream || typeof stream !== 'object') {
                return;
            }

            this.expectedVideoCodec = normalizeCodecName(stream.video_codec, this.expectedVideoCodec);
            this.expectedAudioCodec = normalizeCodecName(stream.audio_codec, this.expectedAudioCodec);
            this.expectedAudioChannels = readPositiveInteger(stream.audio_channels, this.expectedAudioChannels);
            this.expectedAudioSampleRate = readPositiveInteger(stream.audio_sample_rate, this.expectedAudioSampleRate);
            this.updateStatusMessage();
        }

        deriveReaderUrl(whepUrl) {
            return new URL('./reader.js', whepUrl).toString();
        }

        bootstrapSessionIsStale() {
            const issuedAt = Number(this.bootstrapAccessTokenIssuedAt);
            const expiresIn = Number(this.bootstrapAccessTokenExpiresIn);

            if (!Number.isFinite(issuedAt) || issuedAt <= 0 || !Number.isFinite(expiresIn) || expiresIn <= 0) {
                return false;
            }

            const refreshLeadTime = Math.min(20, Math.max(5, Math.floor(expiresIn / 6)));
            const expiresAt = issuedAt + expiresIn - refreshLeadTime;

            return Math.floor(Date.now() / 1000) >= expiresAt;
        }

        shouldRetryWithFreshSession(error) {
            const message = `${error instanceof Error ? error.message : error}`.toLowerCase();

            return message.includes('401')
                || message.includes('403')
                || message.includes('authentication')
                || message.includes('unauthorized')
                || message.includes('forbidden');
        }

        async loadReaderScript(readerUrl) {
            if (typeof window.MediaMTXWebRTCReader === 'function') {
                return;
            }

            if (state.readerScriptPromise instanceof Promise) {
                await state.readerScriptPromise;

                return;
            }

            const script = document.querySelector('[data-mediamtx-reader]') || document.createElement('script');
            state.readerScriptUrl = readerUrl;
            state.readerScriptPromise = new Promise((resolve, reject) => {
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
                const failed = () => finish(new Error('The MediaMTX reader script could not be loaded.'));
                const loaded = () => typeof window.MediaMTXWebRTCReader === 'function' ? finish() : failed();
                const timeout = window.setTimeout(failed, 15000);
                script.addEventListener('load', loaded, { once: true });
                script.addEventListener('error', failed, { once: true });
                if (!script.isConnected) {
                    script.src = readerUrl;
                    script.dataset.mediamtxReader = readerUrl;
                    document.head.appendChild(script);
                } else if (script.dataset.loaded === 'true') {
                    loaded();
                }
            }).catch((error) => {
                state.readerScriptPromise = null;
                state.readerScriptUrl = '';
                throw error;
            });

            await state.readerScriptPromise;
        }

        scheduleReconnect(delayMs) {
            if (this.closed) {
                return;
            }

            this.clearRetryTimeout();
            this.retryTimeout = window.setTimeout(() => {
                this.retryTimeout = null;

                if (this.closed) {
                    return;
                }

                this.hasRetriedFreshSession = false;
                this.connectIfEligible();
            }, delayMs);
        }

        clearRetryTimeout() {
            if (this.retryTimeout !== null) {
                window.clearTimeout(this.retryTimeout);
                this.retryTimeout = null;
            }
        }

        setMessage(message, status = null) {
            const label = status || (this.suspendReasons.size > 0 ? 'Paused' : this.failureCount > 0 ? 'Retrying' : 'Connecting');
            if (this.tile) this.tile.dataset.streamState = label.toLowerCase();
            const badge = this.root.querySelector('[data-role="stream-status"]');
            if (badge && badge.textContent !== label) badge.textContent = label;
            if (this.message) {
                this.message.textContent = message;
            }
        }

        async readError(response, fallbackMessage) {
            if (response.status === 401 || response.status === 419) {
                return 'Your sign-in session has expired. Sign in again to resume the stream.';
            }

            if (response.status === 404) {
                const unavailableMessage = await this.readStructuredErrorMessage(response);

                return unavailableMessage || 'This stream is not available right now.';
            }

            if (response.status === 503) {
                return 'The shared media relay is unavailable right now.';
            }

            const structuredMessage = await this.readStructuredErrorMessage(response);

            if (structuredMessage !== '') {
                return structuredMessage;
            }

            const body = (await response.text()).trim();
            const normalizedBody = body.replace(/\s+/g, ' ').trim();

            if (normalizedBody === '') {
                return fallbackMessage;
            }

            if (normalizedBody.startsWith('{') || normalizedBody.startsWith('[') || normalizedBody.startsWith('<!DOCTYPE') || normalizedBody.startsWith('<html')) {
                return fallbackMessage;
            }

            return normalizedBody.length > 240 ? fallbackMessage : normalizedBody;
        }

        async readStructuredErrorMessage(response) {
            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('application/json')) {
                return '';
            }

            try {
                const payload = await response.clone().json();
                const message = typeof payload?.message === 'string' ? payload.message.trim() : '';

                return message;
            } catch (error) {
                return '';
            }
        }
    }

    const closePlayers = () => {
        const players = state.players;
        state.players = [];
        state.activeAudioPlayer = null;
        players.forEach((player) => player.close());
        clearFocusedTile();
        state.pointerStart = null;
        state.lastTap = {
            tile: null,
            time: 0,
        };
        state.lastTouchFocusToggle = {
            tile: null,
            time: 0,
        };
    };

    const bootstrapPlayers = () => {
        closePlayers();

        state.players = Array.from(document.querySelectorAll('[data-webrtc-player]'))
            .filter((element) => element instanceof HTMLElement && element.dataset.webrtcPlayerSkipAuto !== 'true')
            .map((element) => {
                const player = new BigBrothaWhepPlayer(element);
                element.bigBrothaWhepPlayer = player;

                return player;
            });

        updateMasterVolumeUi();
        state.players.forEach((player, index) => player.start(Math.min(1800, index * 150)));
    };

    const initializeIntersectionObserver = () => {
        if (typeof window.IntersectionObserver !== 'function') {
            return;
        }

        state.intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const player = entry.target.bigBrothaWhepPlayer;

                if (player instanceof BigBrothaWhepPlayer) {
                    player.setIntersecting(entry.isIntersecting || entry.intersectionRatio > 0);
                }
            });
        }, {
            root: null,
            rootMargin: '120px 0px',
            threshold: 0.01,
        });
    };

    const handleVisibilityChange = () => {
        if (state.documentSuspendTimeout !== null) {
            window.clearTimeout(state.documentSuspendTimeout);
            state.documentSuspendTimeout = null;
        }

        if (!document.hidden) {
            [...state.managedPlayers].forEach((player, index) => player.resume('document', Math.min(1800, index * 150)));

            return;
        }

        state.documentSuspendTimeout = window.setTimeout(() => {
            state.documentSuspendTimeout = null;

            if (document.hidden) {
                state.managedPlayers.forEach((player) => player.suspend('document', 'Paused while the live wall is in the background.'));
            }
        }, hiddenPageSuspendDelayMs);
    };

    const handleOnline = () => {
        [...state.managedPlayers].forEach((player, index) => player.resume('offline', Math.min(1800, index * 150)));
    };

    const handleOffline = () => {
        state.managedPlayers.forEach((player) => player.suspend('offline', 'Paused while the browser is offline.'));
    };

    const initialize = () => {
        initializeIntersectionObserver();
        document.addEventListener('input', handleMasterVolumeInput);
        document.addEventListener('dblclick', handleTileDoubleClick);
        document.addEventListener('click', handleWallClick);
        document.addEventListener('pointerdown', handleTilePointerDown, { passive: true });
        document.addEventListener('pointercancel', () => { state.pointerStart = null; state.lastTap = { tile: null, time: 0 }; });
        document.addEventListener('pointerdown', handlePlaybackUnlock, { passive: true });
        document.addEventListener('pointerup', handleTilePointerUp);
        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('keydown', handlePlaybackUnlock);
        document.addEventListener('livewire:navigating', closePlayers);
        document.addEventListener('livewire:navigated', bootstrapPlayers);
        document.addEventListener('visibilitychange', handleVisibilityChange);
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
        window.addEventListener('pagehide', closePlayers);
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) bootstrapPlayers();
        });
        window.addEventListener('beforeunload', closePlayers);

        state.healthCheckInterval = window.setInterval(() => {
            const now = Date.now();
            state.managedPlayers.forEach((player) => player.checkHealth(now));
        }, 5000);

        updateMasterVolumeUi();
        bootstrapPlayers();
    };

    window.BigBrothaWhepPlayer = BigBrothaWhepPlayer;
    window.BigBrothaLiveWallPlayerModule = {
        bootstrap: bootstrapPlayers,
        close: closePlayers,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });

        return;
    }

    initialize();
})();
