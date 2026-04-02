(() => {
    if (window.BigBrothasWallTilesBuilderModule) {
        window.BigBrothasWallTilesBuilderModule.bootstrap();

        return;
    }

    class BigBrothasWallTilesBuilder {
        constructor() {
            this.draggedTile = null;
            this.draggedIndex = null;
            this.componentId = null;
            this.bound = false;
        }

        bootstrap() {
            if (this.bound) {
                this.clearDropTargets();

                return;
            }

            document.addEventListener('dragstart', (event) => this.handleDragStart(event));
            document.addEventListener('dragover', (event) => this.handleDragOver(event));
            document.addEventListener('drop', (event) => this.handleDrop(event));
            document.addEventListener('dragend', () => this.resetDragState());
            document.addEventListener('livewire:navigated', () => this.clearDropTargets());
            this.bound = true;
        }

        handleDragStart(event) {
            const tile = event.target instanceof Element ? event.target.closest('[data-tile-draggable]') : null;

            if (!(tile instanceof HTMLElement)) {
                return;
            }

            const list = tile.closest('[data-sortable-list]');
            const componentRoot = tile.closest('[wire\\:id]');

            if (!(list instanceof HTMLElement) || !(componentRoot instanceof HTMLElement)) {
                return;
            }

            this.draggedTile = tile;
            this.draggedIndex = Number.parseInt(tile.dataset.tileIndex || '', 10);
            this.componentId = componentRoot.getAttribute('wire:id');

            if (!Number.isInteger(this.draggedIndex) || !this.componentId) {
                this.resetDragState();

                return;
            }

            tile.classList.add('tile-builder-row--dragging');
            list.dataset.sortableActive = 'true';

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(this.draggedIndex));
            }
        }

        handleDragOver(event) {
            const tile = event.target instanceof Element ? event.target.closest('[data-tile-draggable]') : null;

            if (!(tile instanceof HTMLElement) || tile === this.draggedTile) {
                return;
            }

            event.preventDefault();
            this.clearDropTargets();
            tile.classList.add('tile-builder-row--drop-target');

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
        }

        handleDrop(event) {
            const tile = event.target instanceof Element ? event.target.closest('[data-tile-draggable]') : null;

            if (!(tile instanceof HTMLElement) || !(this.draggedTile instanceof HTMLElement) || tile === this.draggedTile) {
                this.resetDragState();

                return;
            }

            event.preventDefault();

            const targetIndex = Number.parseInt(tile.dataset.tileIndex || '', 10);

            if (!Number.isInteger(targetIndex) || !Number.isInteger(this.draggedIndex) || this.componentId === null) {
                this.resetDragState();

                return;
            }

            const component = window.Livewire?.find(this.componentId);

            if (component) {
                component.call('reorderTiles', this.draggedIndex, targetIndex);
            }

            this.resetDragState();
        }

        clearDropTargets() {
            document.querySelectorAll('.tile-builder-row--drop-target').forEach((element) => {
                element.classList.remove('tile-builder-row--drop-target');
            });

            document.querySelectorAll('[data-sortable-list][data-sortable-active="true"]').forEach((element) => {
                element.removeAttribute('data-sortable-active');
            });
        }

        resetDragState() {
            this.draggedTile?.classList.remove('tile-builder-row--dragging');
            this.clearDropTargets();
            this.draggedTile = null;
            this.draggedIndex = null;
            this.componentId = null;
        }
    }

    window.BigBrothasWallTilesBuilderModule = new BigBrothasWallTilesBuilder();
    window.BigBrothasWallTilesBuilderModule.bootstrap();
})();