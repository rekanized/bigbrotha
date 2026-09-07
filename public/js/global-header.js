(() => {
    if (window.bigBrothaGlobalHeaderInitialized) {
        return;
    }

    window.bigBrothaGlobalHeaderInitialized = true;

    const drawers = () => document.querySelectorAll('[data-global-navigation]');
    const desktopGroups = () => document.querySelectorAll('.global-header__desktop-navigation details');

    const closeDesktopGroups = (except = null) => {
        desktopGroups().forEach((group) => {
            if (group !== except) group.removeAttribute('open');
        });
    };

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

        const selectedGroup = eventTarget.closest('.global-header__desktop-navigation details');
        closeDesktopGroups(eventTarget.closest('a') ? null : selectedGroup);

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

        const openDrawer = document.querySelector('[data-global-navigation][open]')
            ?? document.querySelector('.global-header__desktop-navigation details[open]');

        if (!openDrawer) {
            return;
        }

        openDrawer.removeAttribute('open');
        openDrawer.querySelector('summary')?.focus();
    });

    document.addEventListener('toggle', (event) => {
        if (event.target instanceof Element && event.target.matches('[data-global-navigation]')) {
            event.target.querySelector('summary')?.setAttribute('aria-label', event.target.open ? 'Close primary navigation' : 'Open primary navigation');
        }
    }, true);

    document.addEventListener('livewire:navigated', () => {
        closeDrawers();
        closeDesktopGroups();
    });

    const desktopNavigation = window.matchMedia('(min-width: 1181px)');
    const closeDrawersAtDesktopWidth = (event) => {
        closeDesktopGroups();
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
