(() => {
    if (window.bigBrothaGlobalHeaderInitialized) {
        return;
    }

    window.bigBrothaGlobalHeaderInitialized = true;

    const drawers = () => document.querySelectorAll('[data-global-navigation]');

    const closeDrawers = (except = null) => {
        drawers().forEach((drawer) => {
            if (drawer !== except) {
                drawer.removeAttribute('open');
            }
        });
    };

    document.addEventListener('click', (event) => {
        const eventTarget = event.target instanceof Element ? event.target : null;

        if (!eventTarget) {
            return;
        }

        const selectedLink = eventTarget.closest('[data-global-navigation] a');

        if (selectedLink) {
            closeDrawers();

            return;
        }

        const activeDrawer = eventTarget.closest('[data-global-navigation]');

        if (!activeDrawer && !eventTarget.closest('[data-global-header]')) {
            closeDrawers();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const openDrawer = document.querySelector('[data-global-navigation][open]');

        if (!openDrawer) {
            return;
        }

        openDrawer.removeAttribute('open');
        openDrawer.querySelector('summary')?.focus();
    });

    document.addEventListener('livewire:navigated', () => closeDrawers());

    const desktopNavigation = window.matchMedia('(min-width: 1181px)');
    const closeDrawersAtDesktopWidth = (event) => {
        if (event.matches) {
            closeDrawers();
        }
    };

    if ('addEventListener' in desktopNavigation) {
        desktopNavigation.addEventListener('change', closeDrawersAtDesktopWidth);
    } else {
        desktopNavigation.addListener(closeDrawersAtDesktopWidth);
    }
})();
