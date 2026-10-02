(() => {
    'use strict';
    if (window.BigBrothaLiveWallPtz) return;

    let generation = 0;
    const controllers = new Set();
    const closePanel = (toggle, panel) => {
        if (typeof panel.hidePopover === 'function' && panel.matches(':popover-open')) panel.hidePopover();
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };
    const positionPanel = (toggle, panel) => {
        const rect = toggle.getBoundingClientRect();
        const left = Math.max(8, Math.min(rect.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - 8));
        const top = Math.max(8, Math.min(rect.bottom + 8, window.innerHeight - panel.offsetHeight - 8));
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    };
    const fetchJson = async (url, options = {}) => {
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(url, {
                credentials: 'same-origin', signal: controller.signal,
                ...options, headers: { Accept: 'application/json', ...options.headers },
            });
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                throw new Error('Your session expired. Reload the wall to continue.');
            }
            if (response.status === 429) throw new Error('Please wait before moving the camera again.');
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Camera controls are unavailable.');
            return data;
        } finally {
            window.clearTimeout(timeout);
            controllers.delete(controller);
        }
    };
    const checkCamera = async (toggle, currentGeneration) => {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!panel) return;
        toggle.hidden = true;
        closePanel(toggle, panel);
        try {
            const capabilities = await fetchJson(toggle.dataset.ptzUrl);
            if (currentGeneration !== generation || !toggle.isConnected || !capabilities.supported) return;
            toggle.hidden = false;
            panel.querySelector('[data-ptz-pan-tilt]').hidden = !capabilities.pan_tilt;
            panel.querySelector('[data-ptz-zoom]').hidden = !capabilities.zoom;
            panel.querySelector('[data-ptz-zoom-stop]').hidden = capabilities.pan_tilt;
        } catch {
            // A failed probe must not advertise controls. Retry transient failures later.
            if (currentGeneration === generation && toggle.isConnected) {
                window.setTimeout(() => {
                    if (currentGeneration === generation && toggle.isConnected) checkCamera(toggle, currentGeneration);
                }, 60000);
            }
        }
    };
    const bootstrap = async () => {
        const currentGeneration = ++generation;
        const buttons = [...document.querySelectorAll('[data-ptz-toggle]')];
        // Limit discovery concurrency so a large wall does not exhaust PHP workers.
        const queue = [...buttons];
        await Promise.all([0, 1].map(async () => {
            while (queue.length && currentGeneration === generation) {
                await checkCamera(queue.shift(), currentGeneration);
            }
        }));
    };
    const handleClick = async (event) => {
        if (!(event.target instanceof Element)) return;
        const toggle = event.target.closest('[data-ptz-toggle]');
        if (toggle) {
            const panel = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!panel) return;
            const opening = panel.hidden;
            document.querySelectorAll('[data-ptz-toggle]').forEach(other => {
                const otherPanel = document.getElementById(other.getAttribute('aria-controls'));
                if (otherPanel) closePanel(other, otherPanel);
            });
            panel.hidden = !opening;
            toggle.setAttribute('aria-expanded', String(opening));
            if (opening) {
                if (panel.hasAttribute('popover') && typeof panel.showPopover === 'function') {
                    // The browser's top layer keeps controls outside tile clipping.
                    panel.showPopover();
                    positionPanel(toggle, panel);
                } else {
                    // Older browsers use the wall's existing focused camera view.
                    const tile = toggle.closest('.wall-monitor-tile');
                    if (tile && tile.dataset.layoutState !== 'focused') tile.querySelector('[data-role="focus-toggle"]')?.click();
                }
                panel.querySelector('[data-ptz-close]').focus();
            }
            return;
        }
        const panel = event.target.closest('[data-ptz-panel]');
        if (!panel) {
            document.querySelectorAll('[data-ptz-toggle][aria-expanded="true"]').forEach(owner => {
                const openPanel = document.getElementById(owner.getAttribute('aria-controls'));
                if (openPanel) closePanel(owner, openPanel);
            });
            return;
        }
        const owner = [...document.querySelectorAll('[data-ptz-toggle]')].find(button => button.getAttribute('aria-controls') === panel.id);
        if (!owner) return;
        if (event.target.closest('[data-ptz-close]')) {
            closePanel(owner, panel);
            owner.focus();
            return;
        }
        const button = event.target.closest('[data-ptz-command]');
        if (!button || button.disabled) return;
        const command = button.dataset.ptzCommand;
        if (panel.dataset.ptzBusy === 'true' && command !== 'stop') return;
        const status = panel.querySelector('[data-ptz-status]');
        const movingButtons = [...panel.querySelectorAll('[data-ptz-command]:not([data-ptz-command="stop"])')];
        if (command !== 'stop') {
            panel.dataset.ptzBusy = 'true';
            movingButtons.forEach(control => { control.disabled = true; });
        }
        status.textContent = command === 'stop' ? 'Stopping…' : 'Moving…';
        try {
            const result = await fetchJson(owner.dataset.ptzUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ command }),
            });
            status.textContent = result.message;
            // The device enforces its timeout even if this page closes mid-request.
            if (command !== 'stop') await new Promise(resolve => window.setTimeout(resolve, 1000));
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'Camera did not respond. Try again.' : (error.message || 'Camera controls are unavailable.');
        } finally {
            if (command !== 'stop') {
                panel.dataset.ptzBusy = 'false';
                movingButtons.forEach(control => { control.disabled = false; });
            }
        }
    };
    document.addEventListener('click', handleClick);
    // Capture Escape before the wall exits focused camera mode.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const toggle = document.querySelector('[data-ptz-toggle][aria-expanded="true"]');
        if (!toggle) return;
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (panel) closePanel(toggle, panel);
        toggle.focus();
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);
    const teardown = () => {
        ++generation;
        controllers.forEach(controller => controller.abort());
        controllers.clear();
    };
    const reposition = () => {
        const toggle = document.querySelector('[data-ptz-toggle][aria-expanded="true"]');
        if (!toggle) return;
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (panel && typeof panel.showPopover === 'function' && panel.matches(':popover-open')) positionPanel(toggle, panel);
    };
    window.addEventListener('resize', reposition);
    document.addEventListener('scroll', reposition, { capture: true, passive: true });
    document.addEventListener('livewire:navigating', teardown);
    document.addEventListener('livewire:navigated', bootstrap);
    window.addEventListener('pagehide', teardown);
    window.addEventListener('pageshow', event => { if (event.persisted) bootstrap(); });
    window.BigBrothaLiveWallPtz = { bootstrap };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    else bootstrap();
})();
