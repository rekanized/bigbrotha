# Website design and mobile audit — 7 September 2026

The review covers all operator page types, authentication, initial setup, shared navigation, camera dialogs, wall configuration, and playback controls. Changes are deployed to the `bigbrotha` test stack at https://monitor-test.schollinetz.com using its `.env.docker`. Production `actualbigbrotha` retains the previously deployed streaming release.

## Changes

- Consolidated the active design tokens in `base/tokens.css` and shared button styling in `components/button.css`. Removed duplicate theme declarations and retired mobile-navigation rules. The responsive stylesheet now loads after the theme, preventing later desktop rules from undoing mobile fixes.
- Standardized typography, focus outlines, status colors, form fields, rounded navigation surfaces, and touch targets. Reduced-motion preferences are respected. Password-reset fields have persistent labels, and the recording player explicitly supports inline mobile playback.
- Made camera and wall setup guides expandable. Compact mobile metrics bring the inventory and editor into view sooner. Camera metadata is expandable above the actual editable fields. Instructions and metadata remain available.
- Kept the live camera name visible on small screens. Timeline date inputs share a compact row and summary cards use less vertical space. Wall tile settings use the shared light surfaces and readable labels; selects fill available width instead of retaining a narrow desktop cap.
- Fixed camera dialogs appearing behind the global header on tablets and landscape phones. Dialog navigation uses touch-sized links; scroll insets follow the actual sticky header/footer dimensions, including Livewire replacements. Initial Shift+Tab stays inside the dialog, the backdrop is excluded from the tab sequence, and closing restores focus and page scrolling.
- Desktop navigation menus now close on outside interaction and Escape. The mobile menu announces its current action, closes during navigation, and remains scrollable in short viewports.
- Simplified first-run setup and sign-in presentation. Viewport metadata supports device safe areas without disabling zoom.

## Browser coverage

The public test application was checked in Chromium at 1440×900, 1024×768, 768×1024, 390×844, 320×720, and 844×390, with touch emulation for mobile/tablet checks.

| Surface | Coverage |
| --- | --- |
| Camera fleet | Inventory, statistics, guide, existing-camera dialog, section navigation, keyboard loop, dismissal, focus restoration |
| Wall tiles | Saved walls, editor, preview, tile controls, adding a temporary unsaved tile, guide |
| Recordings | Populated list, date-filtered empty state, individual recording details/player |
| Timeline review | Summary, date controls, video, pause, zoom/reset, camera switcher, timeline rail |
| Live wall | Named tile, video playback, mobile controls, volume controls, background state |
| Standalone live player | Responsive player and details |
| Operator access | Existing operators, labels and password forms |
| Admin settings | Authentication/storage forms, queue/runtime sections |
| Audit log | Filters and responsive entries |
| Sign-in and setup | Local and Google-only sign-in layouts, required-field errors, setup validation and successful initial-account creation in an isolated disposable SQLite deployment |

The main route sweep comprised **66 page/viewport checks**, with no detected horizontal overflow, unlabelled visible form controls, or undersized mobile text inputs. Seven operator pages were also checked with the root text size doubled, without horizontal overflow. Six consecutive Livewire navigation transitions retained a single header and closed the mobile menu. No unhandled JavaScript exceptions were observed in these passes.

The browser interaction checks confirmed that a draft tile was added without saving the wall, timeline zoom changed from 1.00× to 1.18×, pause updated the playback state, and the live player received 1280×720 video while its camera label remained visible.

## Regression validation

- Full Docker application suite: **308 passed, 1,643 assertions**.
- Native browser keyboard regression fixture: **12 checks**, covering both menus, initial dialog focus, backward/forward tab wrapping, backdrop exclusion, dynamic sticky controls, replaced footer nodes, and dismissal cleanup.
- Real-browser screenshots and hit testing supplement DOM dimension checks; element bounds alone do not detect a dialog covered by the header.
- Final dialog hit tests passed at 1440×900, 844×390, 390×844, and 390×320: close controls were above the header and section anchors remained below the sticky dialog controls. Final live playback presented 175 frames during the observation.
- `git diff --check` passed.

Run the keyboard fixture by loading `tests/Browser/operator-ui.test.js` on the application origin and awaiting `runOperatorUiTests()`. It uses a disposable iframe and standard browser APIs; no frontend package manager or asset build is required.

Mobile validation uses Chromium viewport/touch emulation. Physical iOS/Safari and Android device testing was not available; these results do not establish perfect behavior on every browser or device. External Google OAuth and storage credentials were not changed for design testing.

The deployed test image is `rekanized/bigbrotha-app:20260907-mobile-design`. All four test services passed their health checks. Temporary browser sessions and the isolated setup database/container were removed after validation.

## Activity history follow-up — 2 October 2026

The audit log now leads with readable events and outcomes, keeps technical data expandable, and adds quick views and combined search/time/camera filters. Shared page introductions and recording-total explanations clarify the surrounding workflow. See [audit-log-ux.md](audit-log-ux.md) for behavior, coverage, and test-environment deployment.

## Shared visual refresh — 4 October 2026

The operator shell, navigation, metrics, forms, dialogs, authentication pages, and playback surfaces now use the refreshed shared visual finish. See [the October interface report](ui-modernization-2026-10.md) for assets, browser coverage, Docker deployment, and verification limits.
