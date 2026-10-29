(() => {
    if (window.BigBrothaCameraLinks) return;
    window.BigBrothaCameraLinks = true;

    const copyWithSelection = (url) => {
        const activeElement = document.activeElement;
        const field = document.createElement('textarea');
        field.value = url;
        field.readOnly = true;
        field.style.cssText = 'position:fixed;top:0;left:-9999px;';
        document.body.appendChild(field);

        try {
            field.select();
            return document.execCommand('copy');
        } finally {
            field.remove();
            activeElement?.focus({ preventScroll: true });
        }
    };

    // Delegation also handles fleet rows replaced by Livewire.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-camera-link]');
        if (!button || button.disabled) return;

        const url = button.dataset.copyCameraLink;
        const status = button.parentElement.querySelector('[data-camera-link-status]');
        button.disabled = true;
        status.textContent = '';

        try {
            let copied = false;
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(url);
                    copied = true;
                } catch {
                    // HTTP deployments and denied permissions can use selection copying.
                }
            }
            if (!copied) copied = copyWithSelection(url);
            if (!copied) throw new Error('Clipboard unavailable');
            status.textContent = 'Link copied';
        } catch {
            status.textContent = 'Copy unavailable. Copy the link below:';
            const field = document.createElement('input');
            field.type = 'text';
            field.readOnly = true;
            field.value = url;
            field.setAttribute('aria-label', 'Camera live link');
            status.appendChild(field);
            field.focus();
            field.select();
        } finally {
            button.disabled = false;
        }
    });
})();
