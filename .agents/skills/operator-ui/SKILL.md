---
name: operator-ui
description: Edit BigBrotha operator-facing Blade or Livewire screens, direct CSS, and plain browser JavaScript without a frontend build.
---

# Operator UI

The app uses Blade, Livewire 4, standard CSS, and plain browser JS. There is no `package.json` or frontend build. Follow `.github/copilot-instructions.md` and the existing page structure. The old `UI-rules.md` named by historical docs is absent.

- `resources/views/layouts/app.blade.php` owns the page shell, skip link, shared header, CSS link, and Livewire scripts. `layouts/partials/primary-navigation.blade.php` supplies both desktop and mobile navigation. Keep application links and sign-out there; the live wall bottom dock is for wall/audio controls.
- Livewire PHP components in `app/Livewire/` pair with `resources/views/livewire/`. Page controller views under `resources/views/` embed them. Keep persistence/validation in PHP components and services, with JavaScript for browser interactions.
- `public/css/app.css` imports `pages/simplified-theme.css`, then `pages/mobile.css` and `pages/live-wall.css`. Shared colors are in `base/tokens.css`, reusable controls in `components/`, general shell in `layout/`, and page rules in `pages/`. The mobile and wall styles load late on purpose.
- Direct JS entry points include `camera-editor-modal.js`, `camera-motion-editor.js`, `wall-tiles-builder.js`, `live-wall-player.js`, and `recordings-review.js`. Link new direct assets from Blade with the existing cache-version pattern. `public/js/vendor/sortable.min.js` is vendored.
- The one light theme and shared responsive header apply to authenticated operator screens, including immersive Live Wall and Timeline Review. Login/setup intentionally lack that header. Preserve modal focus, keyboard access, safe-area spacing, and touch layout; see `docs/ui-design-audit.md` for audited behavior.

Verify relevant `tests/Feature/CameraFleetManagerTest.php`, `WallTilesManagerTest.php`, `TimelineReviewTest.php`, or auth feature tests. `tests/Browser/` contains manually browser-invoked JS checks; it has no configured package runner. Inspect desktop and narrow layouts for visual changes.
