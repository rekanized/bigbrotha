(() => {
    if (window.BigBrothaLiveWallPlayerModule) {
        window.BigBrothaLiveWallPlayerModule.bootstrap();

        return;
    }

    const state = {
        activeAudioPlayer: null,
        focusedTile: null,
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
        readerScriptPromise: null,
        readerScriptUrl: '',
    };

    const focusableTileSelector = '[data-live-wall-grid] .wall-monitor-tile';

    const clearFocusedTile = () => {
        if (!(state.focusedTile instanceof HTMLElement)) {
            state.focusedTile = null;
        }

        document.querySelectorAll(focusableTileSelector).forEach((tile) => {
            tile.dataset.layoutState = 'grid';
        });

        document.querySelectorAll('[data-live-wall-grid]').forEach((grid) => {
            grid.dataset.layoutMode = 'grid';
        });

        document.body.classList.remove('live-wall-focus-mode');
        state.focusedTile = null;
    };

    const setFocusedTile = (tile) => {
        if (!(tile instanceof HTMLElement)) {
            clearFocusedTile();

            return;
        }

        document.querySelectorAll(focusableTileSelector).forEach((candidate) => {
            candidate.dataset.layoutState = candidate === tile ? 'focused' : 'dimmed';
        });

        document.querySelectorAll('[data-live-wall-grid]').forEach((grid) => {
            grid.dataset.layoutMode = 'focused';
        });

        document.body.classList.add('live-wall-focus-mode');
        state.focusedTile = tile;
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

    const handleTilePointerUp = (event) => {
        const pointerType = typeof event.pointerType === 'string' ? event.pointerType : '';

        if (pointerType !== 'touch' && pointerType !== 'pen') {
            return;
        }

        const tile = event.target instanceof Element ? event.target.closest('.wall-monitor-tile') : null;

        if (!(tile instanceof HTMLElement) || isInteractiveTarget(event.target)) {
            return;
        }

        const now = Date.now();
        const isRepeatedTap = state.lastTap.tile === tile && (now - state.lastTap.time) <= 320;

        state.lastTap = {
            tile,
            time: now,
        };

        if (isRepeatedTap) {
            toggleFocusedTile(tile);
            state.lastTouchFocusToggle = {
                tile,
                time: now,
            };
            state.lastTap = {
                tile: null,
                time: 0,
            };
        }
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            clearFocusedTile();
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

        state.activeAudioPlayer = player;
        syncAudioSelection();
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
            this.video = root.querySelector('[data-role="video"]');
            this.message = root.querySelector('[data-role="message"]');
            this.tile = root.closest('.wall-monitor-tile');
            this.audioToggle = root.querySelector('[data-role="audio-toggle"]');
            this.audioIndicator = root.querySelector('[data-role="audio-indicator"]');
            this.isAudioSelectable = this.audioToggle !== null && this.audioIndicator !== null;
            this.hasStream = false;
            this.hasAudioTrack = false;
            this.mediaStream = new MediaStream();
            this.reader = null;
            this.closed = false;
            this.hasRetriedFreshSession = false;

            this.handleAudioToggle = this.handleAudioToggle.bind(this);

            if (this.isAudioSelectable) {
                this.audioToggle.addEventListener('click', this.handleAudioToggle);
            }
        }

        start() {
            if (this.bootstrapUrl === '' || this.video === null || this.message === null) {
                return;
            }

            this.closed = false;

                if (this.isAudioSelectable) {
                this.video.defaultMuted = true;
                this.hasStream = false;
                this.hasAudioTrack = false;
                this.video.muted = true;
                this.syncAudioUi();
            }

            this.connect().catch((error) => {
                this.handleFailure(error);
            });
        }

        async connect() {
            this.destroyConnection();
            this.setMessage('Loading secure stream…');

            const bootstrapSession = this.readBootstrapSession();
            const hintedReaderUrl = bootstrapSession?.reader_url || this.bootstrapReaderUrl;
            const hintedReaderLoad = hintedReaderUrl !== ''
                ? this.loadReaderScript(hintedReaderUrl)
                : Promise.resolve();

            const session = bootstrapSession || await this.fetchSession();

            if (this.closed) {
                return;
            }

            const readerUrl = session.reader_url || this.deriveReaderUrl(session.whep_url);

            if (hintedReaderUrl === '' || hintedReaderUrl !== readerUrl) {
                await this.loadReaderScript(readerUrl);
            } else {
                await hintedReaderLoad;
            }

            if (this.closed) {
                return;
            }

            if (typeof window.MediaMTXWebRTCReader !== 'function') {
                throw new Error('The MediaMTX WebRTC reader could not be loaded.');
            }

            const usedBootstrapSession = bootstrapSession !== null && session === bootstrapSession;

            this.reader = new window.MediaMTXWebRTCReader({
                url: session.whep_url,
                token: session.access_token,
                onError: (error) => {
                    if (!this.closed) {
                        this.handleReaderError(error, usedBootstrapSession);
                    }
                },
                onTrack: (event) => {
                    this.handleTrack(event);
                },
            });
        }

        async fetchSession() {
            const response = await fetch(this.bootstrapUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(await this.readError(response, 'The secure player session could not be started.'));
            }

            return response.json();
        }

        readBootstrapSession() {
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

        handleReaderError(error, usedBootstrapSession) {
            const normalizedError = error instanceof Error ? error : new Error(String(error));

            if (usedBootstrapSession && !this.hasRetriedFreshSession && this.shouldRetryWithFreshSession(normalizedError)) {
                this.hasRetriedFreshSession = true;
                this.connect().catch((retryError) => {
                    this.handleFailure(retryError);
                });

                return;
            }

            this.handleFailure(normalizedError);
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

            if (this.isAudioSelectable) {
                this.applyAudioSelection();
            }

            this.video.play().catch(() => undefined);

            this.setMessage('');
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
            this.video.play().catch(() => undefined);
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
                    track.addEventListener('ended', () => {
                        this.removeTrack(track.id);
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
            this.hasAudioTrack = tracks.some((track) => track.kind === 'audio' && track.readyState === 'live');

            if (state.activeAudioPlayer === this && !this.hasAudioTrack) {
                state.activeAudioPlayer = null;
            }
        }

        applyAudioSelection() {
            if (!this.isAudioSelectable || this.video === null) {
                return;
            }

            const isActive = state.activeAudioPlayer === this && this.hasAudioTrack;

            this.video.defaultMuted = !isActive;
            this.video.muted = !isActive;
            this.video.volume = isActive ? state.masterVolume : 0;

            if (isActive) {
                this.video.play().catch(() => undefined);
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
            } else if (!this.hasAudioTrack) {
                this.audioIndicator.textContent = 'No audio';
            } else {
                this.audioIndicator.textContent = isActive ? 'Audio selected' : 'Muted';
            }

            this.audioToggle.disabled = !this.hasAudioTrack;
            this.audioToggle.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            this.audioToggle.setAttribute('aria-label', isActive ? `Stop listening to ${this.label}` : `Listen to ${this.label}`);
        }

        handleFailure(error) {
            if (this.closed) {
                return;
            }

            this.setMessage(error instanceof Error ? error.message : `Unable to play ${this.label}.`);
        }

        destroyConnection() {
            if (this.reader && typeof this.reader.close === 'function') {
                this.reader.close();
                this.reader = null;
            }

            if (this.video && this.video.srcObject) {
                this.video.srcObject = null;
            }

            this.mediaStream.getTracks().forEach((track) => {
                this.mediaStream.removeTrack(track);
            });

            this.hasStream = false;
            this.hasAudioTrack = false;
        }

        close() {
            this.closed = true;

            if (this.isAudioSelectable) {
                this.audioToggle.removeEventListener('click', this.handleAudioToggle);
            }

            if (state.activeAudioPlayer === this) {
                state.activeAudioPlayer = null;
            }

            this.destroyConnection();
            this.syncAudioUi();
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

            const existing = document.querySelector('[data-mediamtx-reader]');

            if (existing) {
                state.readerScriptUrl = existing.dataset.mediamtxReader || readerUrl;
                state.readerScriptPromise = new Promise((resolve, reject) => {
                    if (existing.dataset.loaded === 'true') {
                        resolve();

                        return;
                    }

                    existing.addEventListener('load', () => resolve(), { once: true });
                    existing.addEventListener('error', () => reject(new Error('The MediaMTX reader script could not be loaded.')), { once: true });
                }).catch((error) => {
                    state.readerScriptPromise = null;
                    state.readerScriptUrl = '';

                    throw error;
                });

                await state.readerScriptPromise;

                return;
            }

            state.readerScriptUrl = readerUrl;
            state.readerScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = readerUrl;
                script.defer = true;
                script.dataset.mediamtxReader = readerUrl;
                script.addEventListener('load', () => {
                    script.dataset.loaded = 'true';
                    resolve();
                }, { once: true });
                script.addEventListener('error', () => reject(new Error('The MediaMTX reader script could not be loaded.')), { once: true });
                document.head.appendChild(script);
            }).catch((error) => {
                state.readerScriptPromise = null;
                state.readerScriptUrl = '';

                throw error;
            });

            await state.readerScriptPromise;
        }

        setMessage(message) {
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
        clearFocusedTile();
        state.players.forEach((player) => player.close());
        state.players = [];
        state.activeAudioPlayer = null;
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
            .map((element) => new BigBrothaWhepPlayer(element));

        updateMasterVolumeUi();
        state.players.forEach((player) => player.start());
    };

    const initialize = () => {
        document.addEventListener('input', handleMasterVolumeInput);
        document.addEventListener('dblclick', handleTileDoubleClick);
        document.addEventListener('pointerup', handleTilePointerUp);
        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('livewire:navigating', closePlayers);
        document.addEventListener('livewire:navigated', bootstrapPlayers);
        window.addEventListener('beforeunload', closePlayers);

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