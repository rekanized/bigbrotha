/* Run await runCameraMotionEditorTests() on the application origin in Chromium.
 * Native browser fixtures; no frontend dependencies or build step.
 */
window.runCameraMotionEditorTests = async (source) => {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:0;top:0;width:1000px;height:700px;z-index:99999';
    frame.src = "/up";
    await new Promise(resolve => { frame.onload = resolve; document.body.append(frame); });
    const win = frame.contentWindow;
    const doc = frame.contentDocument;
    const checks = [];
    const assert = (condition, label) => { if (!condition) throw new Error(label); checks.push(label); };
    const tick = (ms = 40) => new Promise(resolve => setTimeout(resolve, ms));
    const requests = [];
    const writes = {};
    const players = [];
    let saved = null;
    let releaseResponse = null;
    let sequence = 0;
    const response = () => ({
        status: 'ready', settings_saved: false, recording_event_active: false,
        segment: { sample_id: String(++sequence) },
        decision: { detected: true, effective_trigger_pixels: 8, changed_indexes: [0, 1] },
        activity: { detected: false, activity_ratio: 0, effective_trigger_pixels: 0, changed_indexes: [] },
    });
    win.fetch = async (url, options) => {
        requests.push(JSON.parse(options.body));
        const payload = await new Promise(resolve => { releaseResponse = resolve; });
        return new win.Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };
    win.BigBrothaWhepPlayer = class {
        constructor(root) { this.root = root; players.push(this); }
        start() { this.started = true; }
        close() { this.closed = true; }
    };
    win.Livewire = { find: () => ({
        $set: (key, value, live) => { writes[key] = value; assert(live === false, 'Draft update stays local'); },
        call: async (method, mask, threshold) => { saved = { method, mask, threshold }; },
    }) };
    const fixture = () => {
        const host = doc.createElement('section');
        host.setAttribute('wire:id', 'fixture');
        host.innerHTML = `<input type="number" value="8" data-role="motion-trigger-pixels-input">
            <strong data-role="motion-trigger-pixels-value"></strong><span data-role="motion-trigger-limit"></span>
            <button data-role="camera-save-button">Save</button>
            <div data-motion-editor data-grid-width="16" data-grid-height="9" data-cluster-bonus-multiplier="0"
                data-session-url-base="/session" data-analysis-url="/analysis" data-analysis-interval-ms="650">
                <script type="application/json" data-role="motion-mask-json">{"runs":[]}</script>
                <div data-role="motion-player" style="width:800px;height:500px"><video data-role="video"></video><span data-role="message"></span></div>
                <canvas data-role="mask-canvas" style="position:absolute"></canvas><canvas data-role="activity-canvas"></canvas>
                <button data-role="paint-button">Paint</button><button data-role="erase-button">Erase</button>
                <button data-role="reset-button">Full</button><button data-role="clear-button">Clear</button>
                <button data-role="preview-retry">Reconnect</button><input data-role="brush-input" type="range" min="1" max="12" value="1">
                <span data-role="brush-value"></span><span data-role="motion-status"></span>
                <span data-role="motion-activity-value"></span><span data-role="motion-trigger-pixels"></span>
                <span data-role="motion-pixels-needed"></span><span data-role="motion-selected-pixels"></span>
                <span data-role="motion-state-value"></span><p data-role="motion-analysis-note"></p><p data-role="motion-sample-age"></p>
            </div>`;
        doc.body.append(host);
        return host;
    };
    try {
        const script = doc.createElement('script');
        script.textContent = source || await (await fetch('/js/camera-motion-editor.js')).text();
        doc.body.append(script);
        const host = fixture();
        await tick();
        assert(players.length === 1 && players[0].started, 'Opening modal starts one preview');
        assert(host.querySelector('[data-role=motion-selected-pixels]').textContent === '0', 'An empty saved mask stays empty');
        assert(requests.length === 1, 'One analysis request is in flight');
        host.querySelector('[data-role=reset-button]').click();
        await tick();
        assert(players.length === 1, 'Metric updates do not restart video');
        assert(host.querySelector('[data-role=motion-trigger-limit]').textContent === '144', 'Zero cluster bonus is respected');
        assert(requests.length === 1, 'Editing while analysis is in flight does not overlap requests');
        releaseResponse(response());
        await tick(80);
        assert(requests.length === 2 && requests[1].mask.selected_pixels === 144, 'Stale draft response is followed by the latest draft');
        const latest = response();
        releaseResponse(latest);
        await tick();
        assert(host.querySelector('[data-role=motion-trigger-pixels]').textContent === '0', 'Overlay shows latest quiet sample instead of earlier segment peak');
        const originalNow = win.Date.now;
        win.Date.now = () => originalNow() + 4000;
        await tick(1100);
        assert(host.querySelector('[data-role=motion-status]').dataset.state === 'waiting', 'Stale detector samples expire instead of remaining armed indefinitely');
        win.Date.now = originalNow;
        // Exact contain geometry, including pillarboxing.
        const video = host.querySelector('video');
        Object.defineProperty(video, 'videoWidth', { value: 640 });
        Object.defineProperty(video, 'videoHeight', { value: 480 });
        video.dispatchEvent(new win.Event('resize'));
        const canvas = host.querySelector('[data-role=mask-canvas]');
        assert(Math.abs(parseFloat(canvas.style.width) - 666.6667) < .1 && parseFloat(canvas.style.left) > 66, 'Mask aligns with contained 4:3 video');
        host.querySelector('[data-role=clear-button]').click();
        canvas.setPointerCapture = () => {};
        const rect = canvas.getBoundingClientRect();
        const pointer = (type, x, pointerId = 1) => new win.PointerEvent(type, { pointerId, button: 0, bubbles: true, clientX: rect.left + rect.width * x, clientY: rect.top + rect.height / 2 });
        canvas.dispatchEvent(pointer('pointerdown', .1));
        canvas.dispatchEvent(pointer('pointermove', .9));
        doc.dispatchEvent(pointer('pointercancel', .9));
        const mask = writes['form.recording_motion_mask'];
        const selected = new Set(mask.runs.flatMap(([a, b]) => Array.from({ length: b - a + 1 }, (_, i) => a + i)));
        assert(Array.from({ length: 13 }, (_, i) => 4 * 16 + i + 1).every(i => selected.has(i)), 'Fast strokes paint continuously between pointer events');
        const before = mask.selected_pixels;
        canvas.dispatchEvent(pointer('pointermove', .01));
        assert(writes['form.recording_motion_mask'].selected_pixels === before, 'Pointer cancellation ends painting');
        const threshold = host.querySelector('[data-role=motion-trigger-pixels-input]');
        threshold.value = '7'; threshold.dispatchEvent(new win.Event('input', { bubbles: true }));
        assert(writes['form.recording_motion_trigger_pixels'] === 7, 'Threshold joins deferred Livewire draft');
        const replacement = threshold.cloneNode(true); replacement.value = '1'; threshold.replaceWith(replacement);
        await tick();
        assert(replacement.value === '7', 'Livewire control replacement preserves draft threshold');
        host.querySelector('[data-role=camera-save-button]').click(); await tick();
        assert(saved?.method === 'saveCameraFromMotionEditor' && saved.threshold === 7 && saved.mask.selected_pixels === before, 'Save submits current mask and threshold together');
        host.querySelector('[data-role=preview-retry]').click(); await tick();
        assert(players.length === 2 && players[0].closed && players[1].started, 'Reconnect closes old preview and starts one receiver');
        host.remove(); await tick();
        assert(players[1].closed, 'Closing modal releases preview');
        releaseResponse?.(response()); await tick();
        const count = requests.length;
        await tick(750);
        assert(requests.length === count, 'Detached modal does not resume analysis');
        const reopened = fixture(); await tick();
        assert(players.length === 3 && players[2].started, 'Reopening creates a working new preview');
        releaseResponse(response()); await tick();
        reopened.remove(); await tick();
        return { passed: checks.length, checks: [...new Set(checks)] };
    } finally { frame.remove(); }
};
