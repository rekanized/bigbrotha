class BigBrothasWhepPlayer {
    constructor(root) {
        this.root = root;
        this.bootstrapUrl = root.dataset.sessionUrl || '';
        this.label = root.dataset.playerLabel || 'camera';
        this.video = root.querySelector('[data-role="video"]');
        this.message = root.querySelector('[data-role="message"]');
        this.reader = null;
        this.closed = false;
    }

    start() {
        if (this.bootstrapUrl === '' || this.video === null || this.message === null) {
            return;
        }

        this.connect().catch((error) => {
            this.handleFailure(error);
        });
    }

    async connect() {
        this.destroyConnection();
        this.setMessage('Loading secure stream…');

        const session = await this.fetchSession();
        const readerUrl = session.reader_url || this.deriveReaderUrl(session.whep_url);
        await this.loadReaderScript(readerUrl);

        if (typeof window.MediaMTXWebRTCReader !== 'function') {
            throw new Error('The MediaMTX WebRTC reader could not be loaded.');
        }

        this.reader = new window.MediaMTXWebRTCReader({
            url: session.whep_url,
            token: session.access_token,
            onError: (error) => {
                if (!this.closed) {
                    this.handleFailure(new Error(error));
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

    handleTrack(event) {
        this.video.srcObject = event.streams[0];
        this.video.play().catch(() => undefined);
        this.setMessage('');
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
    }

    close() {
        this.closed = true;
        this.destroyConnection();
    }

    deriveReaderUrl(whepUrl) {
        return new URL('./reader.js', whepUrl).toString();
    }

    async loadReaderScript(readerUrl) {
        if (typeof window.MediaMTXWebRTCReader === 'function') {
            return;
        }

        const existing = document.querySelector(`script[data-mediamtx-reader="${readerUrl}"]`);

        if (existing) {
            await new Promise((resolve, reject) => {
                if (existing.dataset.loaded === 'true') {
                    resolve();

                    return;
                }

                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('The MediaMTX reader script could not be loaded.')), { once: true });
            });

            return;
        }

        await new Promise((resolve, reject) => {
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
        });
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

        if (response.status === 503) {
            return 'The shared media relay is not running right now.';
        }

        const body = (await response.text()).trim();

        return body !== '' ? body : fallbackMessage;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const players = Array.from(document.querySelectorAll('[data-webrtc-player]')).map((element) => new BigBrothasWhepPlayer(element));

    players.forEach((player) => player.start());

    window.addEventListener('beforeunload', () => {
        players.forEach((player) => player.close());
    });
});