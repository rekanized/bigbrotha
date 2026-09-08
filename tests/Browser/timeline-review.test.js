/* Run await runTimelineReviewTests() on /recordings/timeline in Chromium.
 * Uses an isolated native DOM fixture; no saved cameras or recordings are changed.
 */
window.runTimelineReviewTests = async () => {
    const markup = document.querySelector('[data-recording-review-root]');
    if (!markup) throw new Error('Open a populated Timeline Review page first.');
    const scriptSource = await fetch('/js/recordings-review.js', { cache: 'no-store' }).then(response => response.text());
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:-10000px;width:1000px;height:800px';
    frame.src = '/up';
    await new Promise(resolve => { frame.onload = resolve; document.body.append(frame); });
    const win = frame.contentWindow;
    const doc = frame.contentDocument;
    const checks = [];
    const assert = (condition, name) => {
        if (!condition) throw new Error(name);
        checks.push(name);
    };
    const tick = () => new Promise(resolve => setTimeout(resolve, 100));
    const start = Date.UTC(2026, 8, 7);
    const segment = (id, cameraId, offset) => ({
        id, cameraId, startMs: start + offset, endMs: start + offset + 60000,
        durationSeconds: 60, streamUrl: '/fixture/'+id+'.mp4', cameraName: 'Camera '+cameraId,
        captureMode: 'continuous', timeLabel: '12:00 – 12:01', modeLabel: 'Continuous',
    });
    const first = segment(101, 1, 3600000);
    const later = segment(102, 1, 7200000);
    let pending = null;
    let failStage = false;
    let deferMedia = false;
    let deferStage = false;
    let failRail = false;
    let railRequests = 0;
    const exceptions = [];
    win.addEventListener('error', event => exceptions.push(event.message));
    win.addEventListener('unhandledrejection', event => exceptions.push(String(event.reason)));
    try {
        // Media state is deterministic; the fixture never opens camera/storage streams.
        const proto = win.HTMLMediaElement.prototype;
        Object.defineProperty(proto, 'readyState', { get: () => 4 });
        Object.defineProperty(proto, 'currentTime', { get() { return this.fixtureTime || 0; }, set(value) { if (this.fixtureTime !== value) { this.fixtureTime = value; win.setTimeout(() => this.dispatchEvent(new win.Event('seeked')), 0); } } });
        Object.defineProperty(proto, 'paused', { get() { return this.fixturePaused !== false; } });
        Object.defineProperty(proto, 'currentSrc', { get() { return this.querySelector('source')?.src || this.getAttribute('src') || ''; } });
        proto.play = function () { const changed = this.paused; this.fixturePaused = false; if (changed) win.setTimeout(() => this.dispatchEvent(new win.Event('play')), 0); return Promise.resolve(); };
        proto.pause = function () { const changed = !this.paused; this.fixturePaused = true; if (changed) win.setTimeout(() => this.dispatchEvent(new win.Event('pause')), 0); };
        proto.load = function () { if (deferMedia) return; win.setTimeout(() => { this.dispatchEvent(new win.Event('loadeddata')); this.dispatchEvent(new win.Event('canplay')); }, 0); };
        const root = doc.importNode(markup, true);
        root.querySelectorAll('img').forEach(img => { img.onload = null; img.onerror = null; img.remove(); });
        Object.assign(root.dataset, { dayStartMs: start, dayEndMs: start + 86400000, focusMs: first.startMs, activeCameraId: '1' });
        const rail = root.querySelector('[data-role=timeline-rail]');
        Object.assign(rail.dataset, { cameraId: '1', initialWindowStartMs: start, initialWindowEndMs: start + 86400000, railUrl: '/fixture/rail', stageUrl: '/fixture/stage', activeSegmentId: '101' });
        rail.querySelector('[data-role=rail-segments-json]').textContent = JSON.stringify([first, later]);
        rail.querySelector('[data-role=rail-ticks-json]').textContent = '[]';
        rail.querySelector('[data-role=rail-viewport]').style.height = '400px';
        const cameraList = root.querySelector('.recording-review-switcher__list');
        const template = cameraList.querySelector('button').cloneNode(true);
        cameraList.replaceChildren();
        for (const id of [1, 2, 3]) {
            const button = template.cloneNode(true);
            Object.assign(button.dataset, { cameraId: id, cameraName: 'Camera '+id, railUrl: '/fixture/rail', stageUrl: '/fixture/stage' });
            cameraList.append(button);
        }
        const video = root.querySelector('video');
        Object.assign(video.dataset, { recordingId: first.id, startMs: first.startMs, endMs: first.endMs, directStreamUrl: first.streamUrl });
        video.querySelector('source').src = first.streamUrl;
        video.volume = .7;
        doc.body.append(root);
        win.fetch = async url => {
            const request = new URL(url);
            if (request.pathname.endsWith('/rail')) {
                railRequests++;
                if (failRail) throw new Error('Offline rail');
                return { ok: true, json: async () => ({ cameraId: +root.dataset.activeCameraId, segments: [], windowStartMs: start, windowEndMs: start + 86400000 }) };
            }
            if (failStage) throw new Error('Offline stage');
            const cameraId = +root.dataset.activeCameraId;
            const selected = request.searchParams.get('direction') === 'next' ? later
                : request.searchParams.get('direction') === 'previous' ? first : (cameraId === 1 ? [first, later].find(clip => clip.startMs <= +request.searchParams.get('focus_ms') && +request.searchParams.get('focus_ms') < clip.endMs) : null);
            if (deferStage) return new Promise(resolve => { pending = () => resolve({ ok: true, json: async () => ({ cameraId, segment: null }) }); });
            return { ok: true, json: async () => ({ cameraId, segment: selected }) };
        };
        doc.body.append(Object.assign(doc.createElement('script'), { textContent: scriptSource }));
        await tick();
        const click = role => root.querySelector('[data-role='+role+']').click();
        const focus = () => +root.dataset.focusMs;
        const notice = () => root.querySelector('[data-role=playback-notice]');
        const cursor = root.querySelector('[data-role=focus-cursor]');
        const key = (value, shiftKey = false) => cursor.dispatchEvent(new win.KeyboardEvent('keydown', { key: value, shiftKey, bubbles: true, cancelable: true }));
        assert(video.volume === .7, 'Initial volume is preserved');
        win.BigBrothaRecordingReviewModule.bootstrap();
        doc.dispatchEvent(new win.Event('livewire:navigated'));
        assert(video.volume === .7, 'Repeated initialization preserves volume');
        video.dispatchEvent(new win.Event('play'));
        click('playback-toggle');
        await tick();
        assert(video.paused, 'Pause control pauses the video');
        const clipSeek = root.querySelector('[data-role=clip-seek]');
        const inputClipTime = seconds => {
            clipSeek.value = String(seconds);
            clipSeek.dispatchEvent(new win.Event('input', { bubbles: true }));
        };
        assert(!clipSeek.disabled && Number(clipSeek.max) < 60, 'Playline is bounded to the selected clip');
        inputClipTime(25);
        await tick();
        assert(video.paused && video.currentTime === 25 && focus() === first.startMs + 25000, 'Playline seeks a paused clip and synchronizes the timeline');
        assert(root.querySelector('[data-role=clip-elapsed]').textContent === '0:25', 'Playline shows elapsed clip time');
        click('playback-toggle');
        await tick();
        clipSeek.dispatchEvent(new win.PointerEvent('pointerdown', { pointerId: 20, pointerType: 'touch', bubbles: true }));
        inputClipTime(35);
        assert(video.paused, 'Dragging the playline pauses playback while positioning');
        clipSeek.dispatchEvent(new win.Event('change', { bubbles: true }));
        await tick();
        assert(!video.paused && focus() === first.startMs + 35000, 'Releasing the playline restores playing state');
        click('playback-toggle');
        await tick();
        clipSeek.dispatchEvent(new win.PointerEvent('pointerdown', { pointerId: 21, pointerType: 'touch', bubbles: true }));
        inputClipTime(999);
        doc.dispatchEvent(new win.PointerEvent('pointercancel', { pointerId: 21, bubbles: true }));
        await tick();
        assert(video.paused && video.dataset.recordingId === '101' && video.currentTime < 60, 'Cancelled scrub preserves pause and never advances into another clip');
        assert(root.querySelector('[data-role=rail-focus-time]').textContent.length === 8, 'Timeline handle displays the selected time');
        click('clip-detail');
        assert(Number(root.dataset.zoomScale) === Number(root.dataset.zoomMaxScale), 'Detail view opens the finest timeline scale');
        click('zoom-reset');
        inputClipTime(0);
        await tick();
        click('seek-forward');
        assert(focus() === first.startMs + 10000, 'Forward control moves exactly ten seconds');
        assert(new URL(win.location.href).searchParams.get('active_camera_id') === '1', 'URL preserves active camera');
        assert(new URL(win.location.href).searchParams.has('focus_at'), 'URL preserves selected time');
        click('seek-back');
        assert(focus() === first.startMs, 'Back control moves exactly ten seconds');
        key('ArrowDown', true);
        await tick();
        assert(focus() === first.endMs, 'Shift and arrow moves one minute');
        assert(video.dataset.recordingId === '', 'A recording gap clears the old clip');
        assert(clipSeek.disabled, 'An empty time disables the clip playline');
        video.dispatchEvent(new win.Event('error'));
        assert(notice().hidden, 'Clearing a clip does not produce a false playback error');
        click('previous-clip');
        await tick();
        assert(video.dataset.recordingId === '101', 'Previous clip works across a gap');
        click('next-clip');
        await tick();
        assert(video.dataset.recordingId === '102', 'Next clip works across a gap');
        click('zoom-in');
        assert(+root.dataset.zoomScale > 1, 'Zoom increases scale');
        click('zoom-reset');
        assert(+root.dataset.zoomScale === 1, 'Reset restores scale');
        click('center-focus');
        assert(doc.activeElement === cursor, 'Find selected time focuses the scrub handle');
        video.dispatchEvent(new win.Event('error'));
        assert(notice().dataset.state === 'error' && !root.querySelector('[data-role=playback-retry]').hidden, 'Media failure offers playback retry');
        deferMedia = true;
        click('playback-retry');
        assert(notice().dataset.state === 'loading', 'Retry starts a fresh media load');
        click('playback-toggle');
        const loadingFocus = focus();
        click('seek-forward');
        video.dispatchEvent(new win.Event('loadeddata'));
        video.dispatchEvent(new win.Event('canplay'));
        await tick();
        assert(notice().hidden, 'Ready media clears recovery feedback');
        assert(video.paused, 'Pause during loading remains paused when media arrives');
        assert(focus() === loadingFocus + 10000, 'Seek during loading wins over the original target');
        deferMedia = false;
        failStage = true;
        key('End');
        await tick();
        assert(notice().dataset.state === 'error', 'A failed stage request is distinct from an empty time');
        failStage = false;
        click('playback-retry');
        await tick();
        assert(notice().hidden && video.dataset.recordingId === '', 'Retry can recover to a legitimate empty time');
        // An old request that ignores abort must not replace a newer camera at the same time.
        click('previous-clip');
        await tick();
        deferStage = true;
        root.querySelector('[data-camera-id="2"][data-role=camera-switch]').click();
        await tick();
        const resolveOld = pending;
        deferStage = false;
        root.querySelector('[data-camera-id="1"][data-role=camera-switch]').click();
        await tick();
        resolveOld();
        await tick();
        assert(root.dataset.activeCameraId === '1' && video.dataset.recordingId === '101', 'Late responses cannot replace the selected camera or clip');
        assert(rail.querySelector('[data-role=rail-viewport]').getAttribute('aria-label').includes('Camera 1'), 'Timeline accessible name follows camera switching');
        failRail = true;
        root.querySelector('[data-camera-id="3"][data-role=camera-switch]').click();
        await tick(); await tick();
        assert(!root.querySelector('[data-role=rail-retry]').hidden, 'Failed timeline request offers retry');
        const beforeRetry = railRequests;
        failRail = false;
        click('rail-retry');
        await tick(); await tick();
        assert(railRequests > beforeRetry && root.querySelector('[data-role=rail-retry]').hidden, 'Timeline retry loads the missing window');
        const before = focus();
        const viewport = rail.querySelector('[data-role=rail-viewport]');
        const touch = new win.PointerEvent('pointerdown', { pointerType: 'touch', pointerId: 1, clientY: 40, bubbles: true, cancelable: true });
        viewport.dispatchEvent(touch);
        assert(!touch.defaultPrevented && focus() === before, 'Ordinary touch scrolling does not scrub or trap the gesture');
        win.BigBrothaRecordingReviewModule.destroy();
        assert(video.paused && video.muted && !video.getAttribute('src'), 'Navigation cleanup stops detached playback');
        assert(exceptions.length === 0, 'No uncaught exceptions: '+exceptions.join(', '));
        return { passed: checks.length, checks };
    } finally {
        win.BigBrothaRecordingReviewModule?.destroy();
        frame.remove();
    }
};
