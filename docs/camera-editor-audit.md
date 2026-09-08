# Camera editor audit — September 2026

The review covers Camera Fleet's camera dialog, recording preview, motion painter, dependent form controls, saving, and receiver cleanup.

## Findings and changes

- Manually created motion-editor receivers were absent from the shared player lifecycle. They now receive connection and frozen-frame health checks, autoplay unlock, and visibility/network recovery.
- Failed or stalled script elements could strand future loads. Both loaders remove failed elements, bound each attempt to 15 seconds, and support subsequent retries. The modal provides Reconnect preview.
- The activity overlay reused the strongest transition anywhere in the growing recorder segment. The detector now also reports the newest confirmed transition, including quiet and artifact-rejected frames. Recording still uses the full-segment decision with the existing artifact gates.
- The Matroska rolling buffer now bounds internal clusters to 250 ms as well as flushing packets. Packet flushing alone still held undecodable frames until the next cluster, creating multi-second gaps. Segment duration, stream copy, and the final recording format are unchanged. Preview decoding has an eight-second backend deadline.
- Polling previously waited a full interval after each completed decode. Processing now counts toward the 650 ms target, with one request at a time, a minimum gap, bounded client timeout, error backoff, and hidden-tab suspension. This improves response cadence without opening another RTSP connection or changing recording behavior.
- Stale results no longer remain visible indefinitely. Sample identity uses the segment and decoded frame count, and the UI reports sample age and analysis reasons in visible text.
- Overlay coordinates now match the contained video bounds at desktop and mobile sizes. Fast strokes fill intermediate cells, pointer cancellation ends painting, and empty masks are preserved.
- Draft mask and threshold changes enter local deferred Livewire state and survive unrelated form updates. Save sends them together, and a regular Livewire save remains available if the painter cannot initialize. The saved recording path determines preview identity, avoiding reconnects on unsaved path edits.
- Recording mode, RTSP support, and relay rate control refresh their dependent controls immediately. Frequent motion text changes no longer cause whole-modal layout measurements or repeated editor discovery.

## Operational limits

The overlay comes from the actual recorder buffer, not browser video differences. It can trail WebRTC video because capture, decoder lookahead, polling, and camera delivery all add delay. Sample age is freshness of the decoded recorder sample, not an end-to-end latency measurement. Motion mode and camera availability must be saved before backend recording analysis can run. Network or camera outages remain visible and trigger recovery; no saved thumbnail substitutes for live video.

## Validation

Native browser regressions cover opening/reopening, receiver cleanup, offline recovery, shared watchdog registration, loader failure recovery, contain geometry, continuous strokes, pointer cancellation, empty masks, deferred drafts, atomic save, stale-response suppression, and sample expiry. PHP regressions retain segment qualification while proving that current activity can return to quiet, and verify separate API activity and decision payloads.

Validation completed:

- Full Docker application suite: **312 passed**, 1,677 assertions. After the final recorder buffering/deadline changes, the expanded relevant suite passed **87 tests**, 519 assertions. Pint passed for all five touched PHP files; `git diff --check` passed.
- Native browser suites: **31 motion-editor assertions**, **26 player assertions**, and **12 operator UI assertions** passed.
- Published test site: five consecutive camera-dialog openings presented video in **1.85–2.52 seconds**. Manual reconnect and offline/online recovery succeeded. Draft mask/threshold survived a rate-control update and an off/motion mode round trip; a failed validation left the draft intact and Save available. No browser exceptions were reported.
- A 12-second live observation collected **18 analysis responses**. Confirmed samples advanced within each active segment (for example, 4, 6, 8, and 10 decoded frames), with short waiting periods at segment rollover. Response times were approximately **171–762 ms** in this observation. The camera was quiet; activity remained zero, while synthetic detector tests cover triggering and return to quiet.
- An isolated real-ffmpeg test at 1.5 seconds of capture exposed **three grayscale analysis frames** with 250 ms Matroska clusters; the previous packet-flush-only settings exposed no decodable frames at that point.
- Desktop and mobile widths 1440, 390, 320, and 844 pixels were checked for overflow and video/mask alignment. Narrow preview layout uses the video's default aspect ratio instead of reserving a tall mostly empty box.

The test stack's `app`, `background`, and `relay` were rebuilt and recreated from `rekanized/bigbrotha-app:20260908-camera-editor`; `.env.docker` selects that image. Its final image ID is `sha256:7e2d2406b21e9234373d5b46ef65c1cf07ff7ee6d96e4ed60f5526b07b11abb8`. Production `actualbigbrotha` container identities remained unchanged.
