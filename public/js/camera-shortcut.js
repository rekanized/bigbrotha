(() => {
    const help = document.querySelector('[data-camera-shortcut]');
    if (!help) return;

    const button = help.querySelector('[data-install-camera]');
    const status = help.querySelector('[data-shortcut-status]');
    const standalone = window.matchMedia('(display-mode: standalone)');
    let installPrompt = null;

    const syncDisplay = () => {
        help.hidden = standalone.matches || navigator.standalone === true;
    };
    syncDisplay();
    standalone.addEventListener('change', syncDisplay);

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        button.hidden = false;
    });

    button.addEventListener('click', async () => {
        if (!installPrompt || button.disabled) return;
        const prompt = installPrompt;
        installPrompt = null;
        button.disabled = true;
        status.textContent = '';
        try {
            await prompt.prompt();
            const choice = await prompt.userChoice;
            status.textContent = choice.outcome === 'accepted'
                ? 'Shortcut installation requested. Confirm any remaining prompts on your phone.'
                : 'You can still add this camera using your browser menu above.';
        } catch {
            status.textContent = 'Use your browser menu above to add this camera to your Home Screen.';
        } finally {
            button.hidden = true;
            button.disabled = false;
        }
    });

    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        button.hidden = true;
        status.textContent = 'Camera shortcut installed.';
    });
})();
