// Exercise the actual vendored reader with isolated WebRTC and HTTP doubles.
window.runMediaMtxReaderTests = async (Reader) => {
    const passed = [];
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
        passed.push(message);
    };
    const probes = [];
    const requests = [];
    const readers = [];
    const errors = [];
    class PeerConnection {
        constructor() { probes.push(this); }
        addTransceiver() {}
        createOffer() {
            // Unsupported probes resolve false, as they do in the real reader.
            return new Promise((resolve, reject) => { this.finish = () => reject(new Error('unsupported')); });
        }
        close() { this.closed = true; }
    }
    const fetch = (url, options) => {
        requests.push({ url, options });
        // Leave ICE discovery pending so no real receiver or network is needed.
        return new Promise(() => {});
    };
    const originalPeerConnection = window.RTCPeerConnection;
    const originalFetch = window.fetch;
    const originalSetTimeout = window.setTimeout;
    window.RTCPeerConnection = PeerConnection;
    window.fetch = fetch;
    const tick = () => new Promise(resolve => setTimeout(resolve, 0));
    try {
        for (let index = 0; index < 8; index++) {
            readers.push(new Reader({ url: `/camera-${index}/whep`, token: `token-${index}`, onError: error => errors.push(error) }));
        }
        assert(probes.length === 3, 'Eight concurrent readers share three capability probes');
        readers[0].close();
        probes.forEach(probe => probe.finish());
        await tick();
        assert(probes.every(probe => probe.closed), 'All temporary capability peer connections are closed');
        assert(requests.length === 7 && !requests.some(request => request.url === '/camera-0/whep'), 'Closing one reader during probing does not cancel the others or start the closed reader');
        assert(errors.length === 0, 'Unsupported optional codecs do not fail playback');
        assert(requests.every(({ url, options }) => options.method === 'OPTIONS' && options.headers.Authorization === `Bearer token-${url.match(/camera-(\d+)/)[1]}`), 'Readers retain separate authenticated ICE discovery requests');
        readers.push(new Reader({ url: '/later/whep', token: 'later-token' }));
        await tick();
        assert(probes.length === 3 && requests.length === 8, 'Later reconnects reuse the completed capability result');
        readers.forEach(reader => reader.close());
        assert(requests.every(request => request.options.signal.aborted), 'Closing readers aborts their pending ICE discovery requests');

        const peers = [];
        const sdp = 'v=0\r\na=ice-ufrag:fixture\r\na=ice-pwd:fixture-password\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\na=rtpmap:96 H264/90000\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=rtpmap:111 opus/48000/2\r\na=fmtp:111 minptime=10\r\n';
        class Receiver {
            constructor() { peers.push(this); }
            addTransceiver() {}
            createDataChannel() {}
            async createOffer() { return { type: 'offer', sdp }; }
            async setLocalDescription() {}
            async setRemoteDescription() { this.answered = true; }
            close() { this.closed = true; }
        }
        window.RTCPeerConnection = Receiver;
        const response = (status, location = null) => ({ ok: status >= 200 && status < 300, status, headers: new Headers(location ? { Location: location } : {}), text: async () => sdp });
        let sessionNumber = 0;
        let post = () => Promise.resolve(response(201, `./session-${++sessionNumber}`));
        let patch = () => Promise.resolve(response(204));
        let options = () => Promise.resolve(response(204));
        let deletion = () => Promise.resolve(response(200));
        const signaling = [];
        window.fetch = (url, request) => {
            signaling.push({ url, ...request });
            return ({ POST: post, PATCH: patch, OPTIONS: options, DELETE: deletion })[request.method]();
        };
        const start = (extra = {}) => {
            const reader = new Reader({ url: 'https://relay.example.test/camera/whep', token: 'fixture-token', onError: error => errors.push(error), ...extra });
            readers.push(reader);
            return reader;
        };
        const receiver = start();
        await tick();
        const first = peers.at(-1);
        assert(first.answered, 'WHEP negotiation installs the answer on its receiver');
        first.onicecandidate({ candidate: { sdpMLineIndex: 0, candidate: 'candidate:fixture' } });
        await tick();
        const candidate = signaling.find(request => request.method === 'PATCH');
        assert(candidate.headers.Authorization === 'Bearer fixture-token' && candidate.body.includes('candidate:fixture'), 'Trickle ICE requests authenticate with the session token');
        receiver.close();
        receiver.close();
        const deletes = signaling.filter(request => request.method === 'DELETE');
        assert(first.closed && deletes.length === 1 && deletes[0].url.endsWith('/camera/session-1'), 'Repeated close deletes the allocated WHEP session exactly once');
        assert(deletes[0].keepalive && deletes[0].headers.Authorization === 'Bearer fixture-token' && candidate.signal.aborted, 'Page teardown preserves authenticated deletion while aborting obsolete trickle requests');

        let resolvePost;
        post = () => new Promise(resolve => { resolvePost = resolve; });
        const late = start();
        await tick();
        const latePeer = peers.at(-1);
        late.close();
        resolvePost(response(201, './late-session'));
        await tick();
        assert(signaling.filter(request => request.method === 'DELETE' && request.url.endsWith('/late-session')).length === 1 && !latePeer.answered, 'A POST completed after close releases its server session without installing the answer');

        let restart;
        window.setTimeout = (callback, delay, ...args) => {
            if (delay === 2000) { restart = callback; return -1; }
            return originalSetTimeout(callback, delay, ...args);
        };
        post = () => Promise.resolve(response(201, `./session-${++sessionNumber}`));
        let rejectPatch;
        patch = () => new Promise((resolve, reject) => { rejectPatch = reject; });
        let tracks = 0;
        const recovering = start({ onTrack: () => tracks++ });
        await tick();
        const obsolete = peers.at(-1);
        const oldTrack = obsolete.ontrack;
        obsolete.onicecandidate({ candidate: { sdpMLineIndex: 0, candidate: 'candidate:obsolete' } });
        obsolete.connectionState = 'failed';
        obsolete.onconnectionstatechange();
        const errorCount = errors.length;
        restart();
        await tick();
        const replacement = peers.at(-1);
        rejectPatch(new Error('late failed PATCH'));
        oldTrack({});
        await tick();
        assert(replacement !== obsolete && replacement.answered && !replacement.closed && errors.length === errorCount && tracks === 0, 'Late trickle errors and track callbacks cannot disturb a replacement receiver');
        recovering.close();

        const requestsBeforeMalformed = signaling.length;
        post = () => Promise.resolve(response(201));
        const malformed = start();
        await tick();
        assert(errors.at(-1).includes('missing WHEP session location') && peers.at(-1).closed
            && signaling.slice(requestsBeforeMalformed).every(request => request.method !== 'DELETE'), 'A missing Location fails cleanly without inventing a session URL');
        malformed.close();
        post = () => Promise.resolve(response(201, `./session-${++sessionNumber}`));

        const peerCount = peers.length;
        options = () => Promise.resolve(response(401));
        const unauthorized = start();
        await tick();
        assert(peers.length === peerCount && errors.at(-1).includes('401'), 'Failed ICE authorization reports its status before allocating a receiver');
        unauthorized.close();

        options = () => Promise.resolve(response(204));
        deletion = () => Promise.reject(new Error('offline during teardown'));
        const offlineClose = start();
        await tick();
        const errorsBeforeClose = errors.length;
        offlineClose.close();
        await tick();
        assert(errors.length === errorsBeforeClose, 'A failed teardown request never restarts an intentionally closed receiver');
        return passed;
    } finally {
        readers.forEach(reader => reader.close());
        window.RTCPeerConnection = originalPeerConnection;
        window.fetch = originalFetch;
        window.setTimeout = originalSetTimeout;
    }
};
