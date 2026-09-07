(() => {
    let activeModal = null;
    let returnFocus = null;
    let returnFocusKey = null;
    let chromeObserver = null;
    let chromeElements = [];

    const sizeScrollInsets = () => {
        const panel = activeModal?.querySelector('[role="dialog"]');
        if (!panel) return;
        const masthead = activeModal.querySelector('.camera-editor__masthead');
        const actions = activeModal.querySelector('.camera-editor__actions');
        panel.style.scrollPaddingTop = `${(masthead?.getBoundingClientRect().height ?? 0) + 16}px`;
        panel.style.scrollPaddingBottom = `${(actions?.getBoundingClientRect().height ?? 0) + 16}px`;
    };

    const syncChrome = () => {
        const elements = [...activeModal.querySelectorAll('.camera-editor__masthead, .camera-editor__actions')];
        if (elements.length !== chromeElements.length || elements.some((element, index) => element !== chromeElements[index])) {
            chromeObserver?.disconnect();
            elements.forEach(element => chromeObserver?.observe(element));
            chromeElements = elements;
        }
        sizeScrollInsets();
    };

    const focusableSelector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        'summary',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    const releaseModal = () => {
        if (!activeModal) {
            return;
        }

        chromeObserver?.disconnect();
        chromeObserver = null;
        chromeElements = [];
        activeModal = null;
        document.body.classList.remove('camera-editor-open');

        const connectedReturnFocus = returnFocus?.isConnected
            ? returnFocus
            : (returnFocusKey ? document.querySelector(`[data-camera-editor-trigger="${returnFocusKey}"]`) : null);

        if (connectedReturnFocus instanceof HTMLElement) {
            connectedReturnFocus.focus();
        }

        returnFocus = null;
        returnFocusKey = null;
    };

    const activateModal = (modal) => {
        if (modal === activeModal) {
            syncChrome();
            return;
        }

        returnFocus ??= document.activeElement;
        activeModal = modal;
        document.body.classList.add('camera-editor-open');

        if ('ResizeObserver' in window) {
            chromeObserver = new ResizeObserver(sizeScrollInsets);
        }
        syncChrome();

        window.requestAnimationFrame(() => {
            modal.querySelector('[role="dialog"]')?.focus({ preventScroll: true });
        });
    };

    const syncModal = () => {
        const modal = document.querySelector('[data-camera-editor-modal]');

        if (modal) {
            activateModal(modal);
        } else {
            releaseModal();
        }
    };

    document.addEventListener('keydown', (event) => {
        if (!activeModal) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            activeModal.querySelector('[data-camera-editor-close]')?.click();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...activeModal.querySelectorAll(focusableSelector)]
            .filter((element) => element.tabIndex >= 0 && element.getClientRects().length > 0);

        if (focusable.length === 0) {
            event.preventDefault();
            activeModal.querySelector('[role="dialog"]')?.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === activeModal.querySelector('[role="dialog"]'))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-camera-editor-trigger]')
            : null;

        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        returnFocus = trigger;
        returnFocusKey = trigger.dataset.cameraEditorTrigger ?? null;
    }, true);

    new MutationObserver(syncModal).observe(document.documentElement, {
        childList: true,
        subtree: true,
    });

    document.addEventListener('livewire:navigated', syncModal);
    syncModal();
})();
