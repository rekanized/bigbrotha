// Run in a browser after public/js/live-wall-player.js. No build tools required.
window.runLiveWallPlayerTests = async () => {
    const passed = [];
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
        passed.push(message);
    };
    const makePlayer = () => {
        const root = document.createElement('div');
        root.innerHTML = '<video data-role="video"></video><span data-role="message"></span>';
        const player = new window.BigBrothaWhepPlayer(root);
        player.bootstrapUrl = '/session';
        return player;
    };

    const tokenPlayer = makePlayer();
    tokenPlayer.bootstrapWhepUrl = 'https://example.com/camera/whep';
    tokenPlayer.bootstrapAccessToken = 'rejected-token';
    assert(tokenPlayer.readBootstrapSession()?.access_token === 'rejected-token', 'Initial embedded token is usable');
    tokenPlayer.hasRetriedFreshSession = true;
    assert(tokenPlayer.readBootstrapSession() === null, 'Authentication retry must fetch a fresh token');
    tokenPlayer.close();

    const frozen = makePlayer();
    let frameCallback;
    frozen.video = {
        currentTime: 100,
        requestVideoFrameCallback: callback => { frameCallback = callback; return 1; },
        cancelVideoFrameCallback: () => {},
    };
    frozen.hasVideoTrack = true;
    frozen.lastVideoProgressAt = Date.now() - 21000;
    let failures = 0;
    frozen.handleFailure = () => failures++;
    frozen.watchVideoFrames();
    frozen.checkHealth(Date.now());
    assert(failures === 1, 'Advancing audio clock cannot hide frozen video');
    frameCallback();
    frozen.checkHealth(Date.now());
    assert(failures === 1, 'Presented video frames keep the receiver healthy');
    frozen.close();

    const fallback = makePlayer();
    let frames = 3;
    fallback.video = { currentTime: 100, getVideoPlaybackQuality: () => ({ totalVideoFrames: frames, droppedVideoFrames: 0 }) };
    fallback.hasVideoTrack = true;
    fallback.checkHealth(Date.now());
    fallback.lastVideoProgressAt = Date.now() - 21000;
    fallback.handleFailure = () => failures++;
    fallback.video.currentTime += 10;
    fallback.checkHealth(Date.now());
    assert(failures === 2, 'Decoded-frame fallback detects video freezes with continuing audio');
    frames++;
    fallback.checkHealth(Date.now());
    assert(failures === 2, 'Decoded-frame fallback recognizes progress');
    fallback.close();

    const offscreen = makePlayer();
    offscreen.root.dataset.playerLifecycle = 'viewport';
    offscreen.hasVideoTrack = true;
    offscreen.isIntersecting = false;
    offscreen.lastVideoProgressAt = Date.now() - 60000;
    offscreen.handleFailure = () => { throw new Error('Offscreen presentation caused a reconnect'); };
    offscreen.checkHealth(Date.now());
    assert(offscreen.failureCount === 0, 'Offscreen frame throttling does not cause reconnect churn');
    offscreen.setIntersecting(true);
    assert(Date.now() - offscreen.lastVideoProgressAt < 1000, 'Returning to the viewport gives video time to present again');
    offscreen.close();

    const pending = makePlayer();
    pending.connectionStartedAt = Date.now() - 66000;
    pending.handleFailure = () => failures++;
    pending.checkHealth(Date.now());
    assert(failures === 3, 'Stuck signaling or script loading has a bounded startup deadline');
    pending.close();

    const stale = makePlayer();
    let rejectOld;
    stale.connect = async () => {
        stale.connectionAttempt++;
        await new Promise((resolve, reject) => { rejectOld = reject; });
    };
    stale.connectIfEligible();
    stale.connectionAttempt++;
    rejectOld(new Error('Old session failed'));
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(stale.failureCount === 0 && stale.retryTimeout === null, 'Late errors cannot tear down a newer connection');
    stale.close();

    const play = makePlayer();
    let rejectPlay;
    play.video = { play: () => new Promise((resolve, reject) => { rejectPlay = reject; }) };
    const playing = play.tryPlay();
    play.connectionAttempt++;
    rejectPlay(new DOMException('Playback superseded', 'AbortError'));
    assert(await playing === false, 'A superseded play promise cannot fail the new stream');
    play.close();

    const status = makePlayer();
    status.root.insertAdjacentHTML('beforeend', '<span data-role="stream-status"></span>');
    status.video = { paused: false };
    status.hasStream = status.hasVideoTrack = true;
    status.updateStatusMessage();
    assert(status.message.textContent.includes('first video frame'), 'A negotiated video track does not falsely report live playback');
    status.markVideoProgress(Date.now());
    assert(status.message.textContent === '' && status.root.querySelector('[data-role="stream-status"]').textContent === 'Live', 'Live status requires a presented video frame');
    status.video.controls = true;
    status.video.paused = true;
    status.updateStatusMessage();
    status.lastVideoProgressAt = Date.now() - 60000;
    status.handleFailure = () => { throw new Error('User pause triggered a reconnect'); };
    status.checkHealth(Date.now());
    assert(status.root.querySelector('[data-role="stream-status"]').textContent === 'Paused', 'Native pause stays paused without watchdog reconnection');
    status.close();

    const grid = document.createElement('div');
    grid.dataset.liveWallGrid = '';
    grid.innerHTML = '<article class="wall-monitor-tile" data-camera-name="Front"><button data-role="focus-toggle">Focus</button><button data-role="audio-toggle">Audio</button><video></video></article><article class="wall-monitor-tile"><button>Other camera</button></article>';
    document.body.append(grid);
    const tile = grid.firstElementChild;
    const focus = tile.querySelector('button');
    focus.focus(); focus.click();
    assert(tile.getAttribute('aria-modal') === 'true' && grid.lastElementChild.inert, 'Focused camera is a dialog and other tiles are inert');
    focus.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    assert(document.activeElement === tile.querySelector('[data-role="audio-toggle"]'), 'Backward tab stays inside focused camera controls');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert(!grid.lastElementChild.inert && document.activeElement === focus && !document.body.classList.contains('live-wall-focus-mode'), 'Escape restores the grid and initiating control');
    const video = tile.querySelector('video');
    const gesture = (x1, y1, x2, y2) => {
        video.dispatchEvent(new PointerEvent('pointerdown', { pointerType: 'touch', pointerId: 1, clientX: x1, clientY: y1, bubbles: true }));
        video.dispatchEvent(new PointerEvent('pointerup', { pointerType: 'touch', pointerId: 1, clientX: x2, clientY: y2, bubbles: true }));
    };
    gesture(20, 20, 20, 100); gesture(20, 20, 20, 100);
    assert(!document.body.classList.contains('live-wall-focus-mode'), 'Repeated scroll gestures never focus a tile');
    gesture(20, 20, 20, 20); gesture(100, 100, 100, 100);
    assert(!document.body.classList.contains('live-wall-focus-mode'), 'Two distant taps never focus a tile');
    gesture(100, 100, 100, 100);
    assert(document.body.classList.contains('live-wall-focus-mode'), 'Nearby double taps still focus a camera');
    focus.click(); grid.remove();

    const external = makePlayer();
    external.root.dataset.webrtcPlayerSkipAuto = 'true';
    let connects = 0;
    let healthChecks = 0;
    external.connect = async () => { connects++; };
    external.checkHealth = () => { healthChecks++; };
    external.start();
    await new Promise(resolve => setTimeout(resolve, 30));
    window.dispatchEvent(new Event('offline'));
    assert(external.suspendReasons.has('offline'), 'Manually managed modal player participates in offline suspension');
    window.dispatchEvent(new Event('online'));
    await new Promise(resolve => setTimeout(resolve, 30));
    assert(!external.suspendReasons.has('offline') && connects >= 2, 'Modal player reconnects after returning online');
    await new Promise(resolve => setTimeout(resolve, 5100));
    assert(healthChecks > 0, 'Shared health watchdog includes manually started modal players');
    external.close();
    assert(!external.root.bigBrothaWhepPlayer, 'Closing the modal removes its registered receiver');

    const loader = makePlayer();
    const previousReader = window.MediaMTXWebRTCReader;
    delete window.MediaMTXWebRTCReader;
    try {
        const failure = loader.loadReaderScript('/missing-reader-fixture.js').catch(() => 'failed');
        document.querySelector('[data-mediamtx-reader]').dispatchEvent(new Event('error'));
        assert(await failure === 'failed' && !document.querySelector('[data-mediamtx-reader]'), 'Failed reader scripts are removed so retries can load again');
        const retry = loader.loadReaderScript('/retry-reader-fixture.js');
        window.MediaMTXWebRTCReader = function () {};
        document.querySelector('[data-mediamtx-reader]').dispatchEvent(new Event('load'));
        await retry;
        assert(document.querySelector('[data-mediamtx-reader]').dataset.loaded === 'true', 'Reader loader recovers after a failed script request');
    } finally {
        window.MediaMTXWebRTCReader = previousReader;
        document.querySelector('[data-mediamtx-reader]')?.remove();
        loader.close();
    }

    const automatic = document.createElement('div');
    automatic.dataset.webrtcPlayer = '';
    automatic.dataset.sessionUrl = '/session';
    automatic.innerHTML = '<video data-role="video"></video><span data-role="message"></span>';
    document.body.append(automatic);
    window.BigBrothaLiveWallPlayerModule.bootstrap();
    const original = automatic.bigBrothaWhepPlayer;
    let starts = 0;
    original.connect = async () => { starts++; };
    window.BigBrothaLiveWallPlayerModule.bootstrap();
    await new Promise(resolve => setTimeout(resolve, 30));
    assert(automatic.bigBrothaWhepPlayer === original && starts === 1, 'Repeated bootstrap preserves the receiver and starts it only once');

    original.hasVideoTrack = true;
    original.lastVideoProgressAt = Date.now() - 60000;
    document.dispatchEvent(new Event('visibilitychange'));
    assert(Date.now() - original.lastVideoProgressAt < 1000, 'Returning from a throttled background tab grants a presentation grace period');

    const modal = makePlayer();
    modal.connect = async () => {};
    modal.start();
    window.BigBrothaLiveWallPlayerModule.close();
    assert(modal.closed && original.closed && modal.initialStartTimeout === null, 'Page teardown closes wall and external receivers and cancels pending starts');
    automatic.remove();

    const rejected = makePlayer();
    rejected.bootstrapWhepUrl = 'https://example.com/camera/whep';
    rejected.bootstrapAccessToken = 'rejected-token';
    rejected.hasRetriedFreshSession = true;
    let reusedRejectedToken = false;
    rejected.connect = async () => { reusedRejectedToken = rejected.readBootstrapSession() !== null; };
    rejected.scheduleReconnect(0);
    await new Promise(resolve => setTimeout(resolve, 30));
    assert(!reusedRejectedToken, 'Later retries never reuse a previously rejected embedded token');
    rejected.close();

    const oldSession = makePlayer();
    const previousFetch = window.fetch;
    let resolveBody;
    let bodyRequested;
    const readingBody = new Promise(resolve => { bodyRequested = resolve; });
    window.fetch = async () => ({ ok: true, json: () => {
        bodyRequested();
        return new Promise(resolve => { resolveBody = resolve; });
    } });
    try {
        const result = oldSession.fetchSession(oldSession.connectionAttempt).catch(error => error.name);
        await readingBody;
        oldSession.connectionAttempt++;
        resolveBody({ stream: { video_codec: 'obsolete-codec' } });
        assert(await result === 'AbortError' && oldSession.expectedVideoCodec === 'h264', 'A superseded JSON response cannot overwrite the current stream hints');
    } finally {
        window.fetch = previousFetch;
        oldSession.close();
    }

    const audible = makePlayer();
    let playCalls = 0;
    audible.video = { muted: false, play: async () => {
        playCalls++;
        if (!audible.video.muted) throw new DOMException('Autoplay blocked', 'NotAllowedError');
    } };
    assert(await audible.tryPlay() && audible.video.muted && playCalls === 2 && !audible.awaitingUserActivation, 'Blocked audible autoplay falls back to moving muted video');
    audible.video.play = async () => { throw new DOMException('Autoplay blocked', 'NotAllowedError'); };
    assert(await audible.tryPlay() === false && audible.awaitingUserActivation, 'Blocked muted autoplay waits for user activation without a retry loop');
    audible.close();

    const audioRoot = document.createElement('div');
    audioRoot.innerHTML = '<video data-role="video" controls></video><span data-role="message"></span><button data-role="audio-toggle"></button><span data-role="audio-indicator"></span>';
    const audioPlayer = new window.BigBrothaWhepPlayer(audioRoot);
    audioPlayer.bootstrapUrl = '/session';
    audioPlayer.connect = async () => {};
    audioPlayer.start();
    audioPlayer.hasAudioTrack = true;
    audioPlayer.hasStream = true;
    let audioPlays = 0;
    audioPlayer.video.play = async () => { audioPlays++; };
    audioPlayer.handleAudioToggle();
    assert(!audioPlayer.video.muted && audioRoot.dataset.audioState === 'active', 'External players participate in shared audio selection');
    const beforeVolume = audioPlays;
    audioPlayer.video.volume = 0.5;
    audioPlayer.handleNativeVolumeChange();
    assert(audioPlays === beforeVolume, 'Changing native volume does not resume a paused player');
    audioPlayer.close();

    const ended = makePlayer();
    const canvas = document.createElement('canvas');
    const track = canvas.captureStream().getVideoTracks()[0];
    ended.mergeIncomingTrack({ track });
    ended.connectionAttempt++;
    let staleRemovals = 0;
    ended.removeTrack = () => { staleRemovals++; };
    track.dispatchEvent(new Event('ended'));
    assert(staleRemovals === 0, 'An old track ending cannot mutate a replacement stream');
    ended.close();

    const wall = document.createElement('div');
    wall.innerHTML = Array.from({ length: 12 }, () => '<div data-webrtc-player data-session-url="/session"><video data-role="video"></video><span data-role="message"></span></div>').join('');
    document.body.append(wall);
    const realSetTimeout = window.setTimeout;
    const delays = [];
    window.setTimeout = (callback, delay) => {
        delays.push(delay);
        // Keep connections pending while observing the real lifecycle scheduling.
        return realSetTimeout(callback, 60000);
    };
    try {
        window.BigBrothaLiveWallPlayerModule.bootstrap();
        assert(delays.length === 12 && delays[0] === 0 && delays[7] === 350 && delays[11] === 500, 'Large walls start the eighth tile at 350 ms and cap the stagger at 500 ms');
        window.dispatchEvent(new Event('offline'));
        delays.length = 0;
        window.dispatchEvent(new Event('online'));
        assert(delays.length === 12 && delays[7] === 350 && delays[11] === 500, 'Returning online uses the shorter bounded stagger');
        [...wall.children].forEach(root => root.bigBrothaWhepPlayer.suspend('document'));
        delays.length = 0;
        document.dispatchEvent(new Event('visibilitychange'));
        assert(delays.length === 12 && delays[7] === 350 && delays[11] === 500, 'Returning to the document uses the shorter bounded stagger');
    } finally {
        window.setTimeout = realSetTimeout;
        window.BigBrothaLiveWallPlayerModule.close();
        wall.remove();
    }

    return passed;
};
