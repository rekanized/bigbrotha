# Timeline Review audit — 8 September 2026

The changes target the `bigbrotha` test deployment at https://monitor-test.schollinetz.com. The `actualbigbrotha` production containers are excluded from deployment commands and verified separately by container ID, image ID, and start time.

## Operator experience

- Replaced the large introductory metrics and always-open date panel with a compact heading and expandable date/camera filters. Filters open automatically for an empty range. Camera counts remain on the camera cards; recording failures remain available in the Recordings browser.
- Moved camera identity and clip metadata outside the video. Desktop player height leaves room for controls; mobile video preserves its proportions. Playback, audio, transport, zoom, and focus controls have visible keyboard focus and touch-sized targets.
- Added Previous/Next clip navigation across gaps, ten-second focus steps, a display-timezone time-jump form, a control to center and focus the timeline handle, full-screen playback when supported, and mobile links between video and timeline.
- Camera switching preserves the shared time. URLs retain the chosen camera, committed time, date range, and zoom. Native forms retain camera selection, and selecting no camera checkboxes restores all available cameras.
- Removed the camera-card intrinsic height placeholder that shifted the page after mobile anchor navigation; both jump links keep their destination below the global header.
- Kept ordinary wheel/touch scrolling native. The explicit blue focus handle supports dragging and keyboard movement; timeline instructions are expandable.

## Reliability

- Stage requests distinguish failed requests from genuinely empty times and expose retry. Media loading, buffering, failure, and retry states remain visible beside the player.
- Late stage or rail responses cannot replace a newer camera/selection. Loading an unrelated rail window does not clear active playback.
- Repeated initialization of the same page no longer stops the player or resets volume. Navigation releases old video and audio resources.
- Pause and seek actions during source loading take precedence when the source becomes ready. Clearing an empty clip does not generate a false media error. Playback stops at the review-range boundary instead of reselecting the final clip.
- Previous/Next uses the existing authenticated stage endpoint with a validated direction. It selects one recorded, file-backed database row within the camera/date range, including clips crossing midnight, without probing recording files. No schema or storage changes are required.

## Validation

- Final Docker PHP suite: **311 tests passed, 1,669 assertions**. Earlier full runs intermittently failed motion-recorder process tests; the maintenance class passed separately and the subsequent full run passed. Those recorder implementations were not changed for this UI audit.
- Native browser fixture: 29 checks covering transport, URL state, gaps, zoom/reset, focus, media and request retry, pause/seek while loading, stale responses, camera labels, touch scrolling, and detached-media cleanup. Run `tests/Browser/timeline-review.test.js` on a populated timeline page and await `runTimelineReviewTests()`.
- Published application: populated and empty ranges at 1440×900, 1024×768, 768×1024, 390×844, 320×568, and 844×390. Checks include page overflow, actual video frames, volume, empty filters, and screenshots of player and timeline.
- Six consecutive Livewire navigation transitions retained one timeline instance, released detached playback, and preserved initial volume. The native time-jump form respected the application's Europe/Stockholm timezone while the browser used another timezone. Doubled text size did not cause horizontal page overflow.
- Browser testing uses Chromium with mobile viewport/touch emulation. Physical iOS/Safari and Android device testing was not available.

The initial audit release was `rekanized/bigbrotha-app:20260908-timeline-review`. All four test services were healthy, and the MediaMTX API was reachable. Final browser checks also passed full-screen entry/exit, bookmark reload with the saved date range and clip, subsequent timeline-window loading, and both mobile jump links at four touch viewport sizes. The eight changed runtime files matched the deployed image byte for byte. Temporary browser authentication sessions were revoked after validation.

The referenced project `UI-rules.md` was absent from this checkout. The implementation follows the existing light theme, shared navigation, Blade/plain JavaScript conventions, and Composer-only Docker workflow.

## Follow-up: video playline and timeline scrubber

The selected video now has a persistent native range control over its lower edge, with elapsed and total clip time. Mouse, touch, and keyboard seeking stay within that clip and the selected review range. Dragging temporarily pauses playback and preserves the prior playing/paused state on release or cancellation. The vertical focus cursor and bookmark time stay synchronized. The playline is unavailable for empty times and remains accessible in container fullscreen.

The vertical scrubber has a readable time chip and directional grip on the focus handle, a visible drag hint, a one-click Detail view, an explicit Overview reset, and shorter same-day range labels.

Follow-up validation: **60 focused PHP tests passed (377 assertions)** and **38 browser regression checks passed**. Native mouse/touch dragging, keyboard seeking, synchronized video/timeline position, fullscreen controls, and page overflow were checked against the published application at 1440×900, 390×844, 320×568, and 844×390. These are Chromium desktop and emulated mobile checks; physical mobile devices remain untested.

The follow-up is deployed to the test stack as `rekanized/bigbrotha-app:20260908-playline`. Production container identities, image IDs, and start times remain unchanged.

## Follow-up: scroll stability

The virtualized rail now reconciles ticks, recording bars, and previews by camera/recording identity. Retained elements stay attached, preserving keyboard focus and decoded images while only entries at the buffered window edges are added or removed. Preview selection is cached across the loaded range, and arriving windows preserve existing non-overlapping choices. Scrolling no longer repeatedly clears or rehydrates ready thumbnails. Sprite replacements decode before becoming visible, with stale completions ignored.

Scroll work runs once per animation frame. The rail no longer combines JavaScript virtualization with browser content-visibility placeholders or scroll anchoring. Loading/retry feedback overlays the rail instead of shifting the header; short requests do not flash a loading message. The native browser regression in `tests/Browser/timeline-scroll.test.js` checks retained DOM/media identity, thumbnail readiness, scroll geometry, delayed window loading, and selected-video stability.

Crossing midnight also exposed a header-height change when the visible-range label wrapped. Dates and times now occupy two consistent lines for both same-day and cross-day ranges.

Zoom applies the new scale and scroll anchor before reconciling entries, preventing an intermediate window from removing the selected preview. Track measurements use the declared layout height rather than stale child overflow while zooming out.

Final scroll-fix validation: **60 focused PHP tests passed (377 assertions)**, **38 existing browser regression checks passed**, and **48 scroll/zoom assertions passed** across 1440×900, 390×844, 320×568, and 844×390. The scroll checks compared 21,072 retained elements and 885 ready sprites, with **zero replacements, readiness resets, or viewport position shifts**. Each viewport exercised three delayed window requests. Native wheel/touch gestures preserved selection; Detail/Overview retained the selected preview; video playline, keyboard seeking, fullscreen, and both mobile jump links passed again. Chromium desktop and touch emulation were used; physical mobile devices remain untested.

Current test release: `rekanized/bigbrotha-app:20260908-smooth-timeline`. All four test services are healthy. The three scroll-fix runtime files match the deployed container, and production container IDs, image IDs, and start times remain unchanged.
