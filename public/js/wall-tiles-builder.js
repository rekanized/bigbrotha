(() => {
    if (window.BigBrothasWallTilesBuilderModule) {
        window.BigBrothasWallTilesBuilderModule.bootstrap();

        return;
    }

    class BigBrothasWallTilesBuilder {
        constructor() {
            this.instances = new Map();
            this.observer = null;
            this.pendingBootstrap = null;
            this.bound = false;
        }

        bootstrap() {
            if (this.bound) {
                this.syncLists();

                return;
            }

            this.syncLists();
            this.observer = new MutationObserver(() => this.syncLists());
            this.observer.observe(document.body, {
                childList: true,
                subtree: true,
            });
            document.addEventListener('livewire:navigated', () => this.syncLists());
            this.bound = true;
        }

        syncLists() {
            if (!window.Sortable || typeof window.Sortable.create !== 'function') {
                if (this.pendingBootstrap !== null) {
                    return;
                }

                this.pendingBootstrap = window.setTimeout(() => {
                    this.pendingBootstrap = null;
                    this.syncLists();
                }, 50);

                return;
            }

            if (this.pendingBootstrap !== null) {
                window.clearTimeout(this.pendingBootstrap);
                this.pendingBootstrap = null;
            }

            const liveLists = new Set(
                Array.from(document.querySelectorAll('[data-sortable-list]')).filter((element) => element instanceof HTMLElement),
            );

            this.instances.forEach((instance, list) => {
                if (liveLists.has(list)) {
                    return;
                }

                instance.destroy();
                this.instances.delete(list);
            });

            liveLists.forEach((list) => {
                if (this.instances.has(list)) {
                    return;
                }

                const sortable = window.Sortable.create(list, {
                    animation: 190,
                    easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                    forceFallback: false,
                    fallbackOnBody: true,
                    fallbackTolerance: 3,
                    swapThreshold: 0.6,
                    direction: (_event, target, dragged) => this.resolveDirection(target, dragged),
                    handle: '[data-drag-handle]',
                    draggable: '[data-tile-draggable]',
                    ghostClass: 'wall-grid-builder__tile--ghost',
                    chosenClass: 'wall-grid-builder__tile--dragging',
                    dragClass: 'wall-grid-builder__tile--dragging',
                    onStart: () => this.handleSortStart(list),
                    onMove: (event) => this.handleSortMove(event, list),
                    onEnd: (event) => this.handleSortEnd(event, list),
                });

                this.instances.set(list, sortable);
            });
        }

        handleSortEnd(event, list) {
            if (!(list instanceof HTMLElement)) {
                return;
            }

            this.clearSortingState(list);

            const fromIndex = Number.isInteger(event.oldIndex) ? event.oldIndex : null;
            const toIndex = Number.isInteger(event.newIndex) ? event.newIndex : null;

            if (!Number.isInteger(fromIndex) || !Number.isInteger(toIndex) || fromIndex === toIndex) {
                return;
            }

            const componentRoot = list.closest('[wire\\:id]');

            if (!(componentRoot instanceof HTMLElement)) {
                return;
            }

            const componentId = componentRoot.getAttribute('wire:id');

            if (!componentId) {
                return;
            }

            const component = window.Livewire?.find(componentId);

            if (!component || typeof component.call !== 'function') {
                return;
            }

            component.call('reorderTiles', fromIndex, toIndex);
        }

        handleSortStart(list) {
            if (!(list instanceof HTMLElement)) {
                return;
            }

            list.dataset.sorting = 'true';
            this.clearHoverTargets(list);
        }

        handleSortMove(event, list) {
            if (!(list instanceof HTMLElement)) {
                return true;
            }

            this.clearHoverTargets(list);

            const related = event.related;
            const direction = this.resolveDirection(related, event.dragged);

            if (related instanceof HTMLElement && related.matches('[data-tile-draggable]') && related !== event.dragged) {
                related.classList.add('wall-grid-builder__tile--drop-target');

                if (direction === 'horizontal') {
                    related.classList.add(event.willInsertAfter ? 'wall-grid-builder__tile--insert-after-horizontal' : 'wall-grid-builder__tile--insert-before-horizontal');
                } else {
                    related.classList.add(event.willInsertAfter ? 'wall-grid-builder__tile--insert-after-vertical' : 'wall-grid-builder__tile--insert-before-vertical');
                }
            }

            return true;
        }

        resolveDirection(related, dragged) {
            if (!(related instanceof HTMLElement) || !(dragged instanceof HTMLElement)) {
                return 'vertical';
            }

            const relatedRect = related.getBoundingClientRect();
            const draggedRect = dragged.getBoundingClientRect();
            const verticalOverlap = Math.min(draggedRect.bottom, relatedRect.bottom) - Math.max(draggedRect.top, relatedRect.top);
            const horizontalOverlap = Math.min(draggedRect.right, relatedRect.right) - Math.max(draggedRect.left, relatedRect.left);

            return verticalOverlap >= horizontalOverlap ? 'horizontal' : 'vertical';
        }

        clearHoverTargets(list) {
            if (!(list instanceof HTMLElement)) {
                return;
            }

            list.querySelectorAll(
                '.wall-grid-builder__tile--drop-target, '
                + '.wall-grid-builder__tile--insert-before-horizontal, '
                + '.wall-grid-builder__tile--insert-after-horizontal, '
                + '.wall-grid-builder__tile--insert-before-vertical, '
                + '.wall-grid-builder__tile--insert-after-vertical'
            ).forEach((element) => {
                element.classList.remove(
                    'wall-grid-builder__tile--drop-target',
                    'wall-grid-builder__tile--insert-before-horizontal',
                    'wall-grid-builder__tile--insert-after-horizontal',
                    'wall-grid-builder__tile--insert-before-vertical',
                    'wall-grid-builder__tile--insert-after-vertical',
                );
            });
        }

        clearSortingState(list) {
            if (!(list instanceof HTMLElement)) {
                return;
            }

            list.removeAttribute('data-sorting');
            this.clearHoverTargets(list);
        }
    }

    window.BigBrothasWallTilesBuilderModule = new BigBrothasWallTilesBuilder();
    window.BigBrothasWallTilesBuilderModule.bootstrap();
})();