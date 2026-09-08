/* Run await runTimelineScrollTests() on a populated Timeline Review page.
 * Exercises real scrolling and media painting without changing saved data.
 */
window.runTimelineScrollTests = async () => {
    const root = document.querySelector('[data-recording-review-root]');
    const viewport = root?.querySelector('[data-role=rail-viewport]');
    if (!viewport) throw new Error('Open a populated Timeline Review page first.');
    const video = root.querySelector('video');
    const frames = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const checks = [];
    const assert = (condition, name) => {
        if (!condition) throw new Error(name);
        checks.push(name);
    };
    const layers = [...root.querySelectorAll('[data-role=rail-ticks], [data-role=rail-segments], [data-role=rail-thumbnails]')];
    const snapshot = () => new Map(layers.flatMap(layer => [...layer.children].map(node => [node.dataset.railKey, {
        node, frame: node.querySelector('[data-role=rail-thumbnail-frame]'),
        sprite: node.querySelector('[data-role=rail-thumbnail-sprite]'),
        ready: node.querySelector('[data-role=rail-thumbnail-frame]')?.dataset.renderState === 'ready',
    }])));
    const railPath = new URL(root.querySelector('[data-role=timeline-rail]').dataset.railUrl, location.href).pathname;
    const originalTop = viewport.scrollTop;
    const originalFetch = window.fetch;
    const wasPlaying = !video.paused;
    let delayedRequests = 0;
    let sourceLoads = 0;
    let detachedRetained = 0;
    let compared = 0;
    let replaced = 0;
    let cleared = 0;
    let readyCompared = 0;
    let shifted = 0;
    const timings = [];
    const onLoad = () => sourceLoads++;
    const observer = new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.removedNodes) {
                if (node.nodeType === 1 && node.dataset.railKey && node.isConnected) detachedRetained++;
            }
        }
    });
    try {
        video.pause();
        root.querySelector('#review-timeline').scrollIntoView();
        await frames();
        // Existing frames must survive a layout refresh as well as native scroll events.
        const beforeResize = snapshot();
        viewport.style.width = `${viewport.clientWidth - 2}px`;
        await frames();
        viewport.style.width = '';
        await frames();
        const afterResize = snapshot();
        assert([...beforeResize].every(([key, value]) => !afterResize.has(key) || afterResize.get(key).node === value.node),
            'Resize preserves retained timeline elements');
        const selectedPreview = root.querySelector('[data-role=rail-thumbnail].is-active');
        if (selectedPreview) {
            const selectedFrame = selectedPreview.querySelector('[data-role=rail-thumbnail-frame]');
            for (const role of ['clip-detail', 'zoom-reset']) {
                root.querySelector(`[data-role=${role}]`).click();
                await frames();
                assert(root.querySelector('[data-role=rail-thumbnail].is-active') === selectedPreview
                    && selectedPreview.querySelector('[data-role=rail-thumbnail-frame]') === selectedFrame,
                    `${role} preserves the selected preview and its decoded media`);
            }
        }
        video.addEventListener('loadstart', onLoad);
        layers.forEach(layer => observer.observe(layer, { childList: true }));
        window.fetch = async (url, options) => {
            if (new URL(String(url), location.href).pathname === railPath) {
                delayedRequests++;
                await new Promise(resolve => setTimeout(resolve, 800));
            }
            return originalFetch(url, options);
        };
        for (let step = 0; step < 60; step++) {
            const before = snapshot();
            const top = viewport.getBoundingClientRect().top;
            const started = performance.now();
            viewport.scrollTop += (step < 40 ? -1 : 1) * Math.max(30, viewport.clientHeight / 10);
            await frames();
            timings.push(performance.now() - started);
            if (Math.abs(viewport.getBoundingClientRect().top - top) > 1) shifted++;
            for (const [key, value] of snapshot()) {
                const old = before.get(key);
                if (!old) continue;
                compared++;
                if (old.node !== value.node || old.frame !== value.frame || old.sprite !== value.sprite) replaced++;
                if (old.ready) readyCompared++;
                if (old.ready && !value.ready) cleared++;
            }
        }
        // Move beyond the initial loaded range, wait through slow loading and completion.
        const geometry = viewport.getBoundingClientRect().top;
        viewport.scrollTop = originalTop < viewport.scrollHeight / 2 ? viewport.scrollHeight : 0;
        await new Promise(resolve => setTimeout(resolve, 450));
        assert(Math.abs(viewport.getBoundingClientRect().top - geometry) < 1, 'Slow loading does not move the timeline');
        await new Promise(resolve => setTimeout(resolve, 1700));
        assert(Math.abs(viewport.getBoundingClientRect().top - geometry) < 1, 'Completed loading does not move the timeline');
        assert(delayedRequests > 0, 'Delayed timeline-window requests were exercised');
        assert(compared > 0 && replaced === 0, 'Scrolling preserves retained buttons, frames, and sprites');
        assert(detachedRetained === 0, 'Retained timeline elements are never detached and reinserted');
        assert(cleared === 0, 'Ready thumbnails never revert to loading during scrolling');
        assert(shifted === 0, 'Timeline position remains stable throughout scrolling');
        assert(sourceLoads === 0, 'Browsing the timeline never reloads the selected video');
        assert(document.documentElement.scrollWidth <= innerWidth, 'No horizontal page overflow');
        return { passed: checks.length, checks, compared, readyCompared, replaced, cleared, shifted, delayedRequests,
            p95TwoFramesMs: timings.sort((a, b) => a - b)[Math.floor(timings.length * .95)] };
    } finally {
        window.fetch = originalFetch;
        observer.disconnect();
        video.removeEventListener('loadstart', onLoad);
        viewport.style.width = '';
        viewport.scrollTop = originalTop;
        if (wasPlaying) await video.play().catch(() => {});
    }
};
