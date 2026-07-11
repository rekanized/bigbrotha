(() => {
    let activeModal = null;
    let returnFocus = null;
    let returnFocusKey = null;

    const focusableSelector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    const releaseModal = () => {
        if (!activeModal) {
            return;
        }

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
            return;
        }

        returnFocus ??= document.activeElement;
        activeModal = modal;
        document.body.classList.add('camera-editor-open');

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
            activeModal.querySelector('[data-camera-editor-close]')?.click();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...activeModal.querySelectorAll(focusableSelector)]
            .filter((element) => element.getClientRects().length > 0);

        if (focusable.length === 0) {
            event.preventDefault();
            activeModal.querySelector('[role="dialog"]')?.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
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
