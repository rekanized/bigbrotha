/* Run await runTimelinePreviewRequestTests() on any same-origin page.
 * Uses an isolated iframe and controlled images; never requests saved media.
 */
window.runTimelinePreviewRequestTests = async () => {
    const source = await fetch('/js/recordings-review.js', { cache: 'no-store' }).then(response => response.text());
    const frame = document.createElement('iframe');
    document.body.append(frame);
    const win = frame.contentWindow;
    const checks = [];
    const assert = (condition, name) => {
        if (!condition) throw new Error(name);
        checks.push(name);
    };
    const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
    const images = [];
    const timers = new Map();
    let nextTimer = 0;
    win.setTimeout = (callback) => { timers.set(++nextTimer, callback); return nextTimer; };
    win.clearTimeout = (id) => timers.delete(id);
    win.Image = class {
        constructor() { images.push(this); this.naturalWidth = 320; this.naturalHeight = 180; }
        decode() { return this.rejectDecode ? Promise.reject(new Error('Invalid image')) : Promise.resolve(); }
        removeAttribute(name) { if (name === 'src') this.src = ''; }
    };
    try {
        // Expose the real closure only in this isolated test copy.
        win.eval(source.replace('window.BigBrothaRecordingReviewModule = {',
            'window.previewFixture = { preloadScrubSprite, hydrateRailThumbnail, state, destroy }; window.BigBrothaRecordingReviewModule = {'));
        const { preloadScrubSprite: load, hydrateRailThumbnail: hydrate, state, destroy } = win.previewFixture;
        const loads = Array.from({ length: 20 }, (_, i) => load('/sprite/'+i));
        assert(images.length === 3, 'A scroll burst starts at most three image requests');
        assert(load('/sprite/0') === loads[0], 'Concurrent consumers share one image request');
        load('/sprite/10', { priority: 'high' });
        await images[0].onload();
        await flush();
        assert(images[3].src === '/sprite/10', 'A hovered preview jumps ahead of queued thumbnails');
        assert(images[3].fetchPriority === 'high', 'Promoted previews retain high fetch priority');
        assert(await load('/sprite/0'), 'A successfully decoded image is reused');
        assert(images.length === 4, 'Cached previews do not make another request');

        let needed = true;
        const stale = load('/stale', { priority: 'high', isNeeded: () => needed });
        needed = false;
        images[1].onerror();
        await flush();
        assert(await stale === false && !images.some(image => image.src === '/stale'), 'Removed thumbnails are skipped before network work starts');
        assert(await load('/sprite/1') === false, 'Failed images have a retry cooldown');
        images[2].naturalWidth = 0;
        images[2].rejectDecode = true;
        await images[2].onload();
        await flush();
        assert(await loads[2] === false, 'Decode failures release their request slot');

        const timedOutImage = images[3];
        const lateOnload = timedOutImage.onload;
        timers.values().next().value();
        await flush();
        assert(timedOutImage.src === '', 'A stalled image is cancelled on timeout');
        const activeAfterTimeout = state.scrubSpriteActiveLoads;
        await lateOnload();
        assert(state.scrubSpriteActiveLoads === activeAfterTimeout, 'A late load cannot release a request slot twice');
        assert(state.scrubSpriteActiveLoads === 3, 'Failures and timeouts keep the remaining queue moving within the cap');
        destroy();
        assert(state.scrubSpriteQueue.length === 0, 'Navigation discards queued preview requests');
        while (state.scrubSpriteActiveLoads) {
            images.find(image => image.onerror)?.onerror();
            await flush();
        }
        await Promise.all(loads);

        const thumbnail = win.document.createElement('div');
        Object.assign(thumbnail.dataset, {
            role: 'rail-thumbnail', thumbnailSpriteUrl: '/hydration-sprite', thumbnailUrl: '/fallback-thumbnail',
            scrubFrameCount: '1', scrubFrameWidth: '320', scrubFrameHeight: '180', scrubColumns: '1', scrubRows: '1',
        });
        thumbnail.innerHTML = '<span data-role="rail-thumbnail-frame"></span>';
        win.document.body.append(thumbnail);
        hydrate(thumbnail);
        assert(!thumbnail.querySelector('img'), 'Loading a sprite does not also request a fallback thumbnail');
        images.at(-1).onerror();
        await flush();
        assert(thumbnail.querySelector('img')?.getAttribute('src') === '/fallback-thumbnail', 'A failed sprite still displays its fallback thumbnail');
        const invalid = load('/wrong-size', { expectedWidth: 640 });
        await images.at(-1).onload();
        assert(await invalid === false, 'Unexpected image dimensions are rejected');
        return { passed: checks.length, checks };
    } finally {
        frame.remove();
    }
};
