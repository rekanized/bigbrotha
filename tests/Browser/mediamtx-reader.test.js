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
        return passed;
    } finally {
        readers.forEach(reader => reader.close());
        window.RTCPeerConnection = originalPeerConnection;
        window.fetch = originalFetch;
    }
};
