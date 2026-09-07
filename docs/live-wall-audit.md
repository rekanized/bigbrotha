# Live-wall visual and functional audit — 7 September 2026

Scope: the configured live wall, standalone camera player, stream lifecycle, camera focus, audio controls, and desktop/mobile layouts. Changes are built into the `bigbrotha` test Docker stack at https://monitor-test.schollinetz.com using its `.env.docker`. Production `actualbigbrotha` is unchanged by this audit.

## Findings and fixes

- **Focus was undiscoverable and inaccessible from the keyboard.** Added a 44×44 focus/return control with accessible names, pressed state, visible keyboard focus, dialog semantics, an internal Tab loop, inert background controls, Escape dismissal, and restoration of the initiating control and scroll position. Focused video occupies the full viewport, including short landscape screens, without the global header obscuring it.
- **Touch scrolling could toggle focus.** Double-tap now requires nearby, short, stationary taps. Pointer cancellation, scrolling, distant taps, and long presses clear the gesture. Double-click remains available.
- **Configured orientation was ignored and video could stretch.** The wall renders saved landscape/portrait/square orientation. Tile containers honor orientation and spans; video retains its native proportions using `object-fit: contain`, including focused and standalone playback.
- **Live status appeared before video arrived.** The badge now distinguishes Connecting, Live, Retrying, and Paused. A negotiated track alone is insufficient: Live requires an actually presented or decoded frame. Failure overlays remain visible instead of substituting an old preview image.
- **The watchdog could confuse browser throttling or deliberate pause with stream failure.** Off-screen viewport players are excluded from presentation-based stall detection and receive a new grace period when visible again. Native standalone pause remains paused. Actual visible video stalls retain the existing bounded reconnect behavior.
- **Native and custom audio controls could disagree.** Native mute and volume changes update the player selection and custom button; muted video retains the selected volume so unmuting is audible. The wall continues to allow only one audible tile at a time.
- **Page lifecycle cleanup did not cover cached back/forward restoration.** `pagehide` releases players; persisted `pageshow` initializes fresh receivers. Hidden-page suspension and staggered recovery remain in place.
- **Unconfigured tiles lost their identity and promised a nonexistent 15-second retry.** These tiles now retain the camera name, explain the required configuration step, and link to Camera Fleet. Configured players still retry with exponential backoff and jitter. Documentation now describes that behavior accurately.
- **The dock wasted mobile space and its drawer could leave the viewport.** A single wall no longer renders self-navigation arrows. Mobile controls fit one row; the panel opens within the viewport, scrolls in short screens, and dismisses on outside click or Escape. Long names wrap inside the expanded panel. Desktop operators also have the wall-configuration link.

Monitor sizing and interaction styles live in `public/css/pages/live-wall.css`, after the shared theme and responsive rules. The application remains Composer-only with standard CSS, Blade, and plain JavaScript.

## Validation and limits

The test environment has one accessible Tapo C200 camera. Multi-receiver checks clone its tile in the browser, exercising simultaneous WebRTC delivery through the existing shared camera source without creating additional hardware camera connections. A temporary non-default wall exercises actual server-rendered portrait layout, long names, and Livewire wall switching; it is removed after validation.

Mobile testing uses Chromium viewport/touch emulation, including 320-pixel width and short landscape screens. Physical iOS/Safari and Android devices were unavailable. This audit cannot establish flawless behavior on every device or independently verify the production-only 1080p IMOU camera. The previously measured upstream camera/network delivery gaps remain documented in [live-streaming-audit.md](live-streaming-audit.md).

### Results

- Full Docker application suite: **309 passed, 1,650 assertions**. The final view/theme regression subset also passed: **25 tests, 161 assertions**.
- Native Chromium JavaScript regression fixture: **20 checks**, covering token refresh, stale asynchronous failures, actual-frame watchdog behavior, off-screen throttling, deliberate pause, focus/keyboard handling, and touch gestures.
- Browser layouts checked at **1440×900, 1024×768, 768×1024, 390×844, 320×568, 844×390, and 390×320**. Final portrait, mixed-span/square, unavailable-tile, and expanded-controls fixtures had no detected horizontal overflow or unlabelled visible inputs. Focus controls passed hit testing, and focused bounds matched the full viewport.
- A temporary long-name portrait wall rendered and played through the real controller. Livewire switching returned to the primary wall with one header and one receiver. Returning from focus restored the measured mobile scroll position exactly. The temporary wall was deleted afterward.
- Native mute/unmute and a 35% volume setting agreed with the custom button. A **26-second native pause** preserved the connection attempt; resuming delivered frames without reconnecting. Exclusive wall audio selected only the second of six receivers at **65% volume**.
- Offline/online recovery, hidden-page suspension after 10 seconds, and visible-page recovery worked. Explicit `pagehide` and persisted `pageshow` events verified cleanup/reinitialization; this is lifecycle-event testing, not proof of every browser's back/forward-cache eligibility. Deliberately closing the receiver recovered through the watchdog during a 32-second observation.
- The final six-receiver soak kept all connections live for **60 seconds**, with no reconnects or reported packet loss. Each receiver presented **712–739 additional frames**. This was **not stall-free playback**: WebRTC reported 22–28 short freezes per receiver (16.1–18.2 seconds total), along with 149–176 dropped frames. These counters are recorded rather than treating a connected socket as proof of smooth video.
- A separate single-receiver sample also reported short freezes: 479 decoded frames, 6 dropped frames, and 11 freezes totaling 3.144 seconds. Its aggregate decode time was 1.263 seconds, so these observations do not support attributing all stalls to six-tile rendering load.
- Simultaneous 50-second packet-timing reads from the **existing canonical source** and **derived live path**, without another hardware camera connection, measured matching delivery gaps of approximately **0.88 seconds** (source 0.881 s; live 0.888 s), followed later by gaps around 0.7 s. Timing variability therefore already exists before browser playback. The measurements do not establish the precise camera/network/source-ingest contribution. A blanket timestamp-policy change was avoided because the earlier IMOU comparison showed regressions from doing that globally.
- No unhandled JavaScript exceptions were observed in the final browser passes. `git diff --check` passed. Deployed hashes match the six changed live-wall assets/views.

The deployed image is `rekanized/bigbrotha-app:20260907-live-wall-audit`, image ID `778837049fb2b78cce30bc1b3d5a4c0173a4ff2ac14b06735f5959a3b9639264`. App, background, relay, and database health checks passed. Production image IDs and start times were verified unchanged.

Run `tests/Browser/live-wall-player.test.js` after the player script in an isolated visible browser document, then await `runLiveWallPlayerTests()`. No Node tooling is required.

Final cleanup removed the temporary wall and both audit sessions and closed the audit browser. A final single-wall mobile smoke check confirmed that redundant arrows were absent, the controls panel stayed in view and dismissed on outside interaction, and 1280×720 video was live. Shared operator menu/dialog regression checks also completed without exceptions.
