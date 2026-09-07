/* Run await runOperatorUiTests() in Chromium on the application origin.
 * Native DOM fixtures exercise keyboard behavior without a frontend toolchain.
 */
window.runOperatorUiTests = async () => {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:800px';
    document.body.append(frame);
    const doc = frame.contentDocument;
    const win = frame.contentWindow;
    const checks = [];
    const tick = () => new Promise(resolve => setTimeout(resolve, 60));
    const assert = (condition, name) => {
        if (!condition) throw new Error(name);
        checks.push(name);
    };
    const load = (path) => new Promise((resolve, reject) => {
        const script = doc.createElement('script');
        const url = new URL(path, document.baseURI);
        url.searchParams.set('ui-test', Date.now().toString());
        script.src = url.href;
        script.onload = resolve;
        script.onerror = reject;
        doc.body.append(script);
    });
    const key = (value, shiftKey = false) => doc.dispatchEvent(new win.KeyboardEvent('keydown', {
        key: value, shiftKey, bubbles: true, cancelable: true,
    }));
    try {
        doc.body.innerHTML = `<header data-global-header>
            <div class="global-header__desktop-navigation"><details><summary>Admin</summary><a href="#users">Users</a></details></div>
            <details data-global-navigation><summary>Menu</summary><a href="#fleet">Fleet</a></details>
        </header><button id="outside">Outside</button><button data-camera-editor-trigger="fixture">Edit camera</button>`;
        await load('/js/global-header.js');
        const group = doc.querySelector('.global-header__desktop-navigation details');
        const drawer = doc.querySelector('[data-global-navigation]');
        group.open = true;
        doc.querySelector('#outside').click();
        assert(!group.open, 'Desktop menu closes on outside click');
        group.open = true;
        key('Escape');
        assert(!group.open && doc.activeElement === group.querySelector('summary'), 'Desktop Escape restores focus');
        drawer.open = true;
        await tick();
        assert(drawer.querySelector('summary').getAttribute('aria-label') === 'Close primary navigation', 'Drawer label reflects open state');
        key('Escape');
        assert(!drawer.open && doc.activeElement === drawer.querySelector('summary'), 'Mobile Escape restores focus');
        await load('/js/camera-editor-modal.js');
        const trigger = doc.querySelector('[data-camera-editor-trigger]');
        trigger.focus();
        trigger.click();
        const modal = doc.createElement('div');
        modal.dataset.cameraEditorModal = '';
        modal.innerHTML = `<button tabindex="-1" class="backdrop">Backdrop</button>
            <section role="dialog" tabindex="-1">
                <header class="camera-editor__masthead" style="height:100px"><button data-camera-editor-close>Close</button></header>
                <label>Name<input></label><footer class="camera-editor__actions" style="height:60px"><button id="save">Save</button></footer>
            </section>`;
        doc.body.append(modal);
        await tick();
        const panel = modal.querySelector('[role=dialog]');
        assert(doc.activeElement === panel, 'Dialog receives initial focus');
        key('Tab', true);
        assert(doc.activeElement.id === 'save', 'Initial Shift Tab stays inside dialog');
        const first = modal.querySelector('[data-camera-editor-close]');
        first.focus();
        key('Tab', true);
        assert(doc.activeElement.id === 'save', 'Backdrop is excluded from keyboard loop');
        key('Tab');
        assert(doc.activeElement === first, 'Last control wraps to first control');
        assert(panel.style.scrollPaddingTop === '116px' && panel.style.scrollPaddingBottom === '76px', 'Scroll insets account for sticky dialog controls');
        modal.querySelector('.camera-editor__masthead').style.height = '140px';
        await tick();
        assert(panel.style.scrollPaddingTop === '156px', 'Scroll insets follow resized header');
        const actions = modal.querySelector('.camera-editor__actions');
        const replacement = actions.cloneNode(true);
        replacement.style.height = '80px';
        actions.replaceWith(replacement);
        await tick();
        assert(panel.style.scrollPaddingBottom === '96px', 'Scroll insets follow Livewire replacing the footer');
        first.addEventListener('click', () => modal.remove());
        key('Escape');
        await tick();
        assert(!doc.querySelector('[role=dialog]') && doc.activeElement === trigger && !doc.body.classList.contains('camera-editor-open'), 'Dialog closes and restores focus and scrolling');
        return { passed: checks.length, checks };
    } finally {
        frame.remove();
    }
};
