// Invoke on an empty browser page after public/js/live-wall-ptz.js. All network calls are mocked.
window.runLiveWallPtzTests = async () => {
    const passed = [];
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
        passed.push(message);
    };
    const originalFetch = window.fetch;
    const fixture = document.createElement('article');
    fixture.innerHTML = `<button data-ptz-toggle data-ptz-url="/test-ptz" aria-controls="test-ptz-panel" aria-expanded="false" hidden>PTZ</button>
        <div id="test-ptz-panel" data-ptz-panel popover="manual" hidden>
            <button data-ptz-close>Close</button>
            <div data-ptz-pan-tilt hidden><button data-ptz-command="left">Left</button><button data-ptz-command="stop">Stop</button></div>
            <div data-ptz-zoom hidden><button data-ptz-command="zoom-in">Zoom in</button><button data-ptz-command="stop" data-ptz-zoom-stop>Stop</button></div>
            <p data-ptz-status></p>
        </div>`;
    document.body.append(fixture);
    const toggle = fixture.querySelector('[data-ptz-toggle]');
    const panel = fixture.querySelector('[data-ptz-panel]');
    let capabilities = { supported: false, pan_tilt: false, zoom: false };
    let commandResponse = { message: 'Movement sent.' };
    let commandStatus = 200;
    let deferredMove;
    const commands = [];
    window.fetch = async (url, options) => {
        assert(options.credentials === 'same-origin', 'PTZ requests keep session credentials');
        if (options.method !== 'POST') return new Response(JSON.stringify(capabilities), { status: 200 });
        assert('X-CSRF-TOKEN' in options.headers, 'Movement requests carry a CSRF header');
        const command = JSON.parse(options.body).command;
        commands.push(command);
        if (command === 'left' && deferredMove) await deferredMove.promise;
        return new Response(JSON.stringify(commandResponse), { status: commandStatus });
    };
    const tick = () => new Promise(resolve => window.setTimeout(resolve, 0));
    const waitFor = async (condition) => {
        for (let attempt = 0; attempt < 100 && !condition(); attempt++) {
            await new Promise(resolve => window.setTimeout(resolve, 10));
        }
    };
    try {
        await window.BigBrothaLiveWallPtz.bootstrap();
        assert(toggle.hidden, 'Unsupported cameras keep their PTZ button hidden');
        capabilities = { supported: true, pan_tilt: true, zoom: false };
        await window.BigBrothaLiveWallPtz.bootstrap();
        assert(!toggle.hidden, 'Supported cameras reveal the PTZ button');
        assert(!fixture.querySelector('[data-ptz-pan-tilt]').hidden && fixture.querySelector('[data-ptz-zoom]').hidden, 'Pan and tilt capability does not promise zoom');
        toggle.click();
        assert(!panel.hidden && toggle.getAttribute('aria-expanded') === 'true', 'PTZ toggle opens the panel');
        assert(panel.matches(':popover-open'), 'PTZ controls use the top layer so small tiles cannot clip them');
        assert(document.activeElement === fixture.querySelector('[data-ptz-close]'), 'Opening controls places keyboard focus inside the panel');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        assert(panel.hidden && document.activeElement === toggle, 'Escape closes the panel and restores focus');
        toggle.click();
        let resolveMove;
        deferredMove = { promise: new Promise(resolve => { resolveMove = resolve; }) };
        fixture.querySelector('[data-ptz-command="left"]').click();
        await tick();
        assert(fixture.querySelector('[data-ptz-command="left"]').disabled, 'Movement stays disabled while a request is pending');
        fixture.querySelector('[data-ptz-command="stop"]').click();
        await tick();
        assert(commands.join(',') === 'left,stop', 'Stop is available while a movement request is pending');
        assert(fixture.querySelector('[data-ptz-command="left"]').disabled, 'Stop does not prematurely unlock a pending movement');
        deferredMove = null;
        resolveMove();
        await new Promise(resolve => window.setTimeout(resolve, 1100));
        assert(!fixture.querySelector('[data-ptz-command="left"]').disabled, 'Movement controls recover after the bounded movement interval');
        capabilities = { supported: true, pan_tilt: false, zoom: true };
        await window.BigBrothaLiveWallPtz.bootstrap();
        toggle.click();
        assert(fixture.querySelector('[data-ptz-pan-tilt]').hidden && !fixture.querySelector('[data-ptz-zoom]').hidden, 'Zoom-only cameras hide direction arrows');
        assert(!fixture.querySelector('[data-ptz-zoom-stop]').hidden, 'Zoom-only cameras retain a Stop button');
        commandStatus = 422;
        commandResponse = { message: 'The camera rejected the PTZ request.' };
        fixture.querySelector('[data-ptz-command="zoom-in"]').click();
        await waitFor(() => fixture.querySelector('[data-ptz-status]').textContent === commandResponse.message);
        assert(fixture.querySelector('[data-ptz-status]').textContent === commandResponse.message, 'Camera errors appear in the control panel');
        commandStatus = 419;
        fixture.querySelector('[data-ptz-command="zoom-in"]').click();
        await waitFor(() => fixture.querySelector('[data-ptz-status]').textContent.includes('session expired'));
        assert(fixture.querySelector('[data-ptz-status]').textContent.includes('session expired'), 'Expired sessions prompt a wall reload');
        capabilities = { supported: false, pan_tilt: false, zoom: false };
        await window.BigBrothaLiveWallPtz.bootstrap();
        assert(toggle.hidden && panel.hidden, 'Rechecking capabilities removes controls when support is no longer available');
        return passed;
    } finally {
        window.fetch = originalFetch;
        fixture.remove();
    }
};
