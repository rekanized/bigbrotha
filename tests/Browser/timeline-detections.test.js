/* Run await runTimelineDetectionTests() on a populated Timeline Review page.
 * Keep the browser tab in the foreground for native focus checks.
 * Real DOM/CSS, synthetic labels and mocked media/requests; no saved data changes.
 */
window.runTimelineDetectionTests = async () => {
    const markup = document.querySelector('[data-recording-review-root]');
    if (!markup) throw new Error('Open a populated Timeline Review page first.');
    const source = await fetch('/js/recordings-review.js', { cache: 'no-store' }).then(r => r.text());
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:-10000px;width:1000px;height:900px';
    frame.src = '/up';
    await new Promise(resolve => { frame.onload = resolve; document.body.append(frame); });
    const win = frame.contentWindow;
    const doc = frame.contentDocument;
    const checks = [];
    const assert = (condition, name) => { if (!condition) throw new Error(name); checks.push(name); };
    const tick = () => new Promise(resolve => setTimeout(resolve, 160));
    const start = Date.UTC(2026, 10, 13);
    const types = ['person', 'cat', 'dog', 'car', 'motorcycle', 'bus', 'truck'];
    const clip = (id, minutes, labels) => ({ id, cameraId: 1, startMs: start + minutes * 60000,
        endMs: start + (minutes + 1) * 60000, durationSeconds: 60, objectLabels: labels,
        streamUrl: '/fixture/'+id+'.mp4', captureMode: 'motion', modeLabel: 'Movement clip',
        timeLabel: new Date(start + minutes * 60000).toISOString().slice(11, 19),
    });
    const errors = [];
    win.addEventListener('error', e => errors.push(e.message));
    win.addEventListener('unhandledrejection', e => errors.push(String(e.reason)));
    try {
        doc.body.className = 'page-recording-review';
        await Promise.all([...document.querySelectorAll('link[rel=stylesheet]')].map(link => new Promise(resolve => {
            const copy = doc.importNode(link, true);
            copy.onload = copy.onerror = resolve;
            doc.head.append(copy);
        })));
        const media = win.HTMLMediaElement.prototype;
        Object.defineProperty(media, 'readyState', { get: () => 4 });
        Object.defineProperty(media, 'currentTime', { get() { return this.fixtureTime || 0; }, set(value) { this.fixtureTime = value; } });
        Object.defineProperty(media, 'paused', { get() { return this.fixturePaused !== false; } });
        Object.defineProperty(media, 'currentSrc', { get() { return this.querySelector('source')?.src || ''; } });
        media.play = function () { this.fixturePaused = false; return Promise.resolve(); };
        media.pause = function () { this.fixturePaused = true; };
        media.load = function () { win.setTimeout(() => this.dispatchEvent(new win.Event('loadeddata')), 0); };
        const root = doc.importNode(markup, true);
        root.querySelectorAll('img').forEach(img => img.remove());
        doc.body.append(root);
        const rail = root.querySelector('[data-role=timeline-rail]');
        const viewport = root.querySelector('[data-role=rail-viewport]');
        viewport.style.height = '440px';
        const video = root.querySelector('video');
        const template = root.querySelector('[data-role=camera-switch]').cloneNode(true);
        const cameras = root.querySelector('.recording-review-switcher__list');
        cameras.replaceChildren();
        for (const id of [1, 2]) {
            const button = template.cloneNode(true);
            Object.assign(button.dataset, { cameraId: id, cameraName: 'Camera '+id, railUrl: '/fixture/rail', stageUrl: '/fixture/stage' });
            cameras.append(button);
        }
        let records = [];
        let pending = [];
        let holdRail = false;
        win.fetch = async url => {
            const request = new URL(url);
            if (request.pathname.endsWith('/rail')) {
                if (holdRail) await new Promise(resolve => pending.push(resolve));
                return { ok: true, json: async () => ({ cameraId: +root.dataset.activeCameraId, segments: records,
                    windowStartMs: start, windowEndMs: start + 86400000 }) };
            }
            return { ok: true, json: async () => ({ cameraId: +root.dataset.activeCameraId,
                segment: records.find(s => s.startMs <= +request.searchParams.get('focus_ms') && s.endMs > +request.searchParams.get('focus_ms')) || null }) };
        };
        const reset = async (segments, zoom = 1, partial = false) => {
            win.BigBrothaRecordingReviewModule?.destroy();
            records = segments;
            Object.assign(root.dataset, { dayStartMs: start, dayEndMs: start + 86400000, focusMs: segments[0].startMs, activeCameraId: 1, zoomScale: zoom });
            Object.assign(rail.dataset, { cameraId: 1, activeSegmentId: segments[0].id, initialWindowStartMs: start,
                initialWindowEndMs: start + (partial ? 60000 : 86400000), railUrl: '/fixture/rail', stageUrl: '/fixture/stage' });
            rail.querySelector('[data-role=rail-segments-json]').textContent = JSON.stringify(segments);
            rail.querySelector('[data-role=rail-ticks-json]').textContent = '[]';
            Object.assign(video.dataset, { recordingId: segments[0].id, startMs: segments[0].startMs, endMs: segments[0].endMs, directStreamUrl: segments[0].streamUrl });
            video.querySelector('source').src = segments[0].streamUrl;
            if (!win.BigBrothaRecordingReviewModule) doc.body.append(Object.assign(doc.createElement('script'), { textContent: source }));
            else win.BigBrothaRecordingReviewModule.bootstrap();
            await tick();
        };
        const markers = () => [...root.querySelectorAll('[data-role=detection-marker]')];
        const panel = () => root.querySelector('[data-role=detection-panel]');
        const key = value => doc.activeElement.dispatchEvent(new win.KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true }));
        const close = () => root.querySelector('[data-role=detection-close]').click();

        for (const type of types) {
            await reset([clip(1, 60, [type, type])]);
            assert(markers().length === 1 && markers()[0].querySelector('use').getAttribute('href').endsWith('#'+type), type+' has its own specific icon');
            assert(markers()[0].querySelectorAll('svg').length === 1, type+' duplicates are collapsed');
        }
        await reset([clip(1, 60, ['truck', 'person', 'cat', 'dog', 'car', 'motorcycle', 'bus'])]);
        assert(markers()[0].querySelector('.recording-review__object-more').textContent === '+5', 'Overflow counts additional types');
        assert([...markers()[0].querySelectorAll('svg')].map(svg => svg.dataset.object).join(',') === 'person,cat', 'Icons use stable ordering');
        win.focus();
        markers()[0].blur();
        markers()[0].focus({ preventScroll: true });
        assert(!panel().hidden && panel().textContent.includes('Motorcycle'), 'Keyboard focus reveals all names');
        key('Escape');
        assert(panel().hidden && doc.activeElement === markers()[0], 'Escape dismisses preview without losing focus');
        markers()[0].blur();
        markers()[0].dispatchEvent(new win.PointerEvent('pointerover', { bubbles: true, pointerType: 'mouse' }));
        markers()[0].dispatchEvent(new win.PointerEvent('pointerout', { bubbles: true, pointerType: 'mouse' }));
        panel().dispatchEvent(new win.PointerEvent('pointerover', { bubbles: true, pointerType: 'mouse' }));
        await tick();
        assert(!panel().hidden, 'Hover details remain readable while moving into the panel');
        panel().dispatchEvent(new win.PointerEvent('pointerout', { bubbles: true, pointerType: 'mouse' }));
        await tick();
        assert(panel().hidden, 'Hover details dismiss after leaving the marker and panel');
        markers()[0].click();
        assert(root.querySelector('[data-role=selected-objects]').textContent.includes('Truck'), 'Selected clip names remain visible');
        await reset([clip(1, 60, []), clip(2, 120, ['new-object'])]);
        assert(markers().length === 1 && markers()[0].querySelector('use').getAttribute('href').endsWith('#unknown'), 'Unknown label uses a neutral icon and unlabeled clip has no badge');
        assert(markers()[0].getAttribute('aria-label').includes('New-object'), 'Unknown name remains accessible');

        const dense = [clip(1, 60, ['person']), clip(2, 65, ['cat']), clip(3, 70, ['dog'])];
        await reset(dense);
        assert(markers().length === 1 && markers()[0].textContent.includes('3 clips'), 'Nearby clips group with a distinct clip count');
        const original = markers()[0];
        const focusBefore = root.dataset.focusMs;
        original.dispatchEvent(new win.PointerEvent('pointerdown', { bubbles: true, pointerType: 'mouse', button: 0, clientY: 100 }));
        assert(root.dataset.focusMs === focusBefore && !viewport.dataset.dragging, 'Marker pointerdown does not scrub');
        original.click();
        assert(!panel().hidden && panel().querySelectorAll('[data-role=detection-clip]').length === 3, 'Group opens every clip');
        assert(doc.activeElement.dataset.role === 'detection-clip', 'Opening group focuses its first clip');
        key('Escape');
        assert(panel().hidden && doc.activeElement === original, 'Escape restores group focus');
        original.click();
        panel().querySelectorAll('[data-role=detection-clip]')[1].click();
        await tick();
        assert(video.dataset.recordingId === '2' && root.querySelector('[data-role=selected-objects]').textContent.includes('Cat'), 'Group row selects its clip and updates names');
        markers()[0].click();
        doc.body.dispatchEvent(new win.PointerEvent('pointerdown', { bubbles: true }));
        assert(panel().hidden, 'Outside pointer dismisses the group');
        viewport.scrollTop += 10;
        await tick();
        assert(markers()[0] === original, 'Ordinary scroll preserves the group node');
        root.querySelector('[data-role=clip-detail]').click();
        await tick();
        assert(markers().length === 3, 'Detail zoom separates nearby clips');

        holdRail = true;
        await reset([clip(1, 60, ['person', 'car'])], 1, true);
        const retained = markers()[0];
        records = [clip(1, 60, ['dog'])];
        holdRail = false;
        pending.splice(0).forEach(resolve => resolve());
        await tick();
        assert(markers()[0] === retained && markers()[0].querySelector('svg').dataset.object === 'dog', 'Loaded label changes update retained markers');
        assert(root.querySelector('[data-role=rail-thumbnail] [data-object=dog]') && !root.querySelector('[data-role=rail-thumbnail] [data-object=person]'), 'Thumbnail removes stale icons when labels change');
        holdRail = true;
        await reset(dense, 1, true);
        markers()[0].click();
        records = dense.map(segment => ({ ...segment, objectLabels: [] }));
        holdRail = false;
        pending.splice(0).forEach(resolve => resolve());
        await tick();
        assert(markers().length === 0 && panel().hidden && !root.querySelector('[data-role=rail-thumbnail] [data-role=object-icons]'), 'Removing labels clears badges and dismisses obsolete group details');
        assert(doc.activeElement === viewport, 'Removing the open group restores focus to the timeline');
        await reset([clip(1, 60, ['person']), clip(2, 60, ['dog'])]);
        markers()[0].click();
        panel().querySelectorAll('[data-role=detection-clip]')[1].click();
        await tick();
        assert(video.dataset.recordingId === '2', 'Choosing overlapping clips plays the requested recording');
        records = [clip(9, 60, [])];
        root.querySelector('[data-role=camera-switch][data-camera-id="2"]').click();
        await tick();
        await tick();
        assert(markers().length === 0 && panel().hidden && root.querySelector('[data-role=selected-objects]').hidden, 'Camera switch clears stale detection UI');

        await reset(dense);
        frame.style.width = '360px';
        await tick();
        rail.scrollIntoView();
        markers()[0].dispatchEvent(new win.PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' }));
        assert(!viewport.dataset.dragging, 'Touch marker retains native scrolling');
        markers()[0].click();
        const bounds = panel().getBoundingClientRect();
        assert(bounds.left >= 0 && bounds.right <= win.innerWidth && bounds.top >= 0 && bounds.bottom <= win.innerHeight, 'Details panel fits a 360px viewport');
        const markerBounds = markers()[0].getBoundingClientRect();
        assert(markerBounds.height >= 44 && markerBounds.width >= 44, 'Markers provide accessible touch targets');
        assert(doc.documentElement.scrollWidth <= win.innerWidth, 'Phone layout has no horizontal overflow');
        close();
        assert(errors.length === 0, 'No browser errors: '+errors.join(', '));
        return { passed: checks.length, checks };
    } finally {
        win.BigBrothaRecordingReviewModule?.destroy();
        frame.remove();
    }
};
