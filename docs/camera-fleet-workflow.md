# Camera Fleet Workflow

## Goal

The Camera Fleet is the main operator workflow for turning known ONVIF or RTSP devices into managed camera records with saved RTSP profiles and preview diagnostics.

## Entry Paths

### Manual ONVIF Probe

Use `/camera-fleet` and choose `Add camera` to start the probe-first create flow with:

- ONVIF URL.
- optional username.
- optional password.

If a camera is RTSP-only and does not expose ONVIF device services, switch the create modal into RTSP-only mode and save the manual RTSP endpoint without probing.

Best when:

- a specific device address is already known.
- authentication is required to validate the device.

Successful probes now hydrate the Camera Fleet draft before the first database insert.

## Provisioning Flow

Verified manual probe results now hydrate the create form with:

- device service URL.
- resolved IP address when ONVIF interface data returns one.
- MAC address when ONVIF interface data returns one.
- credentials.
- manufacturer.
- model.
- serial number.
- verification metadata.
- discovered RTSP media profiles and primary stream defaults when the camera exposes them.

Operators can then review and adjust the draft before the camera record is inserted.

## Camera Fleet Screen

Open `/camera-fleet` to manage saved cameras.

Each row currently shows:

- camera identity.
- enabled or disabled state.
- latest preview thumbnail when available.
- last stream-check status.
- recording mode and retention status.
- latest recorded segment timing when available.
- ONVIF and RTSP readiness.

Use `View / edit` to open the modal editor.

On narrow touch screens, the shared global header switches its desktop links to a compact navigation drawer, fleet cards become single-column, long endpoints wrap inside their cards, and camera actions expand to full-width touch targets. The same header and active-route navigation remain present on Camera Fleet, recordings, Timeline Review, wall setup, admin screens, single-camera playback, and Live Wall. The editor consumes the available dynamic viewport height, respects device safe areas, keeps its section navigator horizontally scrollable, and retains the sticky save/cancel footer without causing page-level horizontal overflow.

## Camera Editor Modal

The modal is the main management surface for a selected camera.

The Live relay transcoding section can force an otherwise H.264 camera through browser-safe H.264 normalization. Use this for camera encoders that advertise H.264 but still produce unstable browser timing or incompatible access units. Compatibility mode reads the canonical source with the camera's native RTP timestamps, leaves RTP fragmentation to MediaMTX, and rebuilds erratic camera audio clocks into continuous 48 kHz Opus timestamps.

It supports:

- probing a new ONVIF endpoint before first save.
- editing network and endpoint fields.
- setting an explicit live-feed RTSP path and an explicit recording RTSP path.
- editing credentials.
- toggling ONVIF and RTSP support.
- selecting a recording mode per camera.
- choosing recording retention in days.
- setting the movement threshold and painting motion-detection zones directly over the live feed.
- saving camera changes.
- refreshing RTSP profiles from ONVIF.
- saving a direct RTSP endpoint for cameras that do not support ONVIF.

Password behavior:

- on create, the supplied password is saved.
- on edit, leaving the password blank keeps the saved password.

## RTSP Retrieval

When RTSP profiles are refreshed:

1. If ONVIF is enabled and usable, `OnvifRtspStreamService` locates the media service with `GetCapabilities`.
2. It loads media profiles with `GetProfiles`.
3. It resolves RTSP URIs with `GetStreamUri`.
4. It saves results under `metadata['rtsp_profiles']`.
5. It uses the first discovered stream to prefill the live-feed path and recording path for new drafts.
6. If ONVIF is unavailable but a direct RTSP path is saved, Camera Fleet stores that saved endpoint as a manual RTSP profile instead of blocking the workflow.
7. When a refreshed ONVIF profile matches an existing saved profile, the workflow now preserves preview and probe metadata instead of discarding the last captured thumbnail.

## RTSP Test And Preview

Use `Test connection and preview` on a saved profile to confirm playback before it is used elsewhere.

The diagnostics flow:

1. injects credentials into the RTSP URI when needed.
2. runs ffprobe for stream validation and metadata.
3. ensures per-camera preview storage exists.
4. runs ffmpeg to capture a frame.
5. saves the updated probe and preview state back into the RTSP profile metadata.

Saved profile metadata may include:

- `probe_status`
- `probe_checked_at`
- `probe_message`
- `video_codec`
- `video_resolution`
- `preview_path`
- `preview_generated_at`
- `preview_message`
- `transport`

## Preview Display

Preview images appear:

- inside RTSP profile cards in the modal.
- in fleet rows through `latestRtspPreview()`.

If a saved preview file is not a valid image, the preview route serves a placeholder instead of a broken icon.

`latestRtspPreview()` now ignores missing or invalid image files so fleet cards do not keep pointing at stale thumbnails after storage drift.

## Recording Policy

Camera Fleet is now the place where operators enable feed recording.

Current behavior:

1. each camera can stay off, record continuously, or record only on movement.
2. movement recording now uses a painted motion mask stored as a low-resolution grid instead of a single rectangle.
3. operators paint or erase the saved mask over a live preview. The browser video is display-only. Opening the editor starts one shared, temporary backend sampler on the canonical recording source. It decodes continuously at 6 fps by default, while alternating frame phases preserve the recorder's 3 fps comparison spacing. Draft masks reuse these frames. The modal requests the latest confirmed movement about every 150 ms, without waiting for recorder segment rollover. Closing or hiding all editors lets the sampler stop after five seconds.
4. the movement threshold is an effective trigger-pixel count over the selected mask; connected clusters receive bonus weight. The modal separately displays actual moving cells, their percentage of the selected area, weighted trigger pixels, a threshold meter, and the saved recording event. Draft settings never change the recorder until saved. These are BigBrotha motion settings, not ONVIF camera alarm settings.
5. full-frame refreshes, decoder-startup churn, and coherent exposure or infrared-mode changes are rejected before the painted mask can start an event. The modal uses the same backend detector and temporal comparison spacing as the recorder. The live preview estimates the current draft threshold; the worker independently decides saved events from closed recording segments.
6. each camera now stores an explicit live-feed RTSP path and an explicit recording RTSP path, and those two values may be identical.
7. retention is currently enforced per camera in whole days, with the default workflow set to one day.

The recorder writes short direct-to-disk segments with ffmpeg. Motion buffers use stream copy. Continuous capture encodes H.264/AAC with a fixed frame rate and forced keyframes at each clip boundary, so long camera GOPs cannot extend a minute clip. Encoding stays in ffmpeg; PHP orchestrates the process and completed files.

## Scheduled Preview Refresh

Preview freshness no longer depends entirely on a human running a stream test.

Current behavior:

1. `camera-fleet:refresh-previews` scans enabled cameras with saved RTSP profiles.
2. Each eligible camera dispatches a preview refresh job that re-tests the preferred profile and captures a fresh thumbnail.
3. The command is scheduled every 30 minutes through Laravel's scheduler.
4. Existing preview metadata is retained when a profile refresh returns the same ONVIF stream definition.

The supported Compose deployment runs `php artisan schedule:run` every minute through the `background` container's scheduler; this drives preview refreshes without host cron.

## Scheduled Recording

Recording maintenance is also scheduler-driven.

Current behavior:

1. `camera-recordings:tick` runs every minute and queues one recording decision per eligible camera.
2. continuous mode uses the scheduler tick as a bootstrap or recovery trigger, keeps one ffmpeg segment-muxer process running per camera, and imports completed direct-to-disk files without waiting for minute boundaries.
3. motion mode keeps a persistent rolling stream-copy buffer. The worker analyzes closed segments, opens an event on qualifying motion, includes pre-roll, and resets the post-trigger quiet deadline on later qualifying segments. Event length is dynamic and follows segment boundaries.
4. an active event extends with later movement instead of opening duplicate overlapping events. Finalization stitches the selected buffer window; continuously active events roll over at the configured maximum duration.
5. motion capture and review-asset work stay queue-backed and guarded by a per-camera lock so duplicate overlapping jobs are avoided, while continuous mode is watchdog-managed by the persistent segmenter service.
6. the minute scheduler sweeps the `failed_jobs` table and requeues eligible failed jobs up to the configured automatic retry limit; exhausted rows remain visible for the configured terminal retention window and are then pruned hourly, preventing indefinite accumulation while preserving a diagnostic window.
7. `camera-recordings:prune` runs hourly and removes files whose row `created_at` time is older than the camera's retention window, and `camera-recordings:prune-audit` can be used to inspect the same candidates without deleting anything.

Continuous timestamp behavior:

1. a continuous segment now stamps `scheduled_for` and `started_at` from the imported segment filename timestamp written by the persistent ffmpeg segmenter.
2. `ended_at` is derived from the imported start plus the measured file duration, capped at the next segment start when necessary to avoid overlap. Interrupted clips remain short and outages remain gaps. If probing fails, the configured segment duration is the fallback.
3. Continuous clips target 60 seconds (`CAMERA_RECORDING_SEGMENT_SECONDS`), including the first full clip after startup. Final clips from shutdown or source loss can be shorter. Audio packet boundaries can add a few milliseconds to container duration. `CAMERA_CONTINUOUS_VIDEO_PRESET`, `CAMERA_CONTINUOUS_VIDEO_CRF`, and `CAMERA_CONTINUOUS_VIDEO_FPS` control capture cost and quality (defaults: `veryfast`, `20`, `20`). Encoding uses `FFMPEG_THREADS` (default two); size the host for continuous encoding per camera. Review generation can copy the resulting H.264/AAC into a seekable fast-start MP4 and generates local scrub sprites. It publishes and verifies the finished recording on the active storage disk before updating its database path.
4. exact timeline-boundary focus points resolve to the following adjacent clip, so operators do not lose the next clip behind an inclusive edge match.

Production deployments should run both the minute scheduler and a queue worker that polls `recordings,default,review-assets` in that order so the continuous watchdog, motion clips, and delayed review assets all keep flowing without preview work blocking capture first.

## Camera Editor Workflow

The Camera Fleet editor keeps the full camera configuration in one modal while making the long form navigable for operators.

Current behavior:

1. the modal title identifies the selected camera and states that edits are not applied until they are saved.
2. a persistent section navigator jumps directly to identity, stream access, live relay, recording, availability, diagnostics, and recent activity.
3. the primary save and cancel actions remain visible at the bottom of the modal while its configuration sections scroll.
4. keyboard focus moves into the dialog when it opens, stays inside the modal while tabbing, returns to the launching control after close, and Escape cancels the editor.
5. narrow layouts retain the same sections and actions without horizontal page overflow; the section navigator itself scrolls horizontally.
6. recording mode, RTSP support, and relay rate control update their dependent controls immediately. Motion-mask strokes and trigger-pixel edits are kept in deferred Livewire state, survive other form updates, and are submitted together by Save changes.
7. the motion preview uses the **saved recording path**. Unsaved connection changes do not interrupt the preview; saving a changed recording path starts a new receiver. Reconnect preview releases the old receiver and requests a fresh session.
8. the painted grid follows the actual video bounds, excluding letterboxing. Fast pointer strokes are interpolated; cancelling a touch/pen gesture ends the stroke. Empty masks remain empty and motion-mode saves validate that at least one pixel is selected.
9. activity colors show the latest confirmed recorder transition, while the recorder still qualifies an entire segment by its peak. An active saved recording event is reported separately and can remain active after the current image becomes quiet. The sample-age label measures time since new decoded buffer frames, not camera-to-screen latency.
10. analysis requests never overlap, have a ten-second client deadline, and pause in hidden tabs. The default target interval is 150 ms, with processing time included and a minimum 20 ms gap. Sampling targets six updates per second; network and source delivery can reduce the observed rate. Errors back off. Samples without confirmed frame progress expire after three seconds. The live sampler removes recurring segment-rollover waits.
11. modal receivers participate in the shared player's connection deadline, frozen-video watchdog, autoplay recovery, and online/visibility recovery. Script loads time out after 15 seconds and can retry; closing or navigating away releases the receiver and analysis resources.

See [camera-editor-audit.md](camera-editor-audit.md) for the audit and validation coverage.

## Live Wall Playback

Use `/wall-tiles` to choose what the wall should show, then open `/live-wall` to monitor the configured result.

## Recordings Browser

Use `/recordings` for the fast recordings browser, then open `/recordings/timeline` when you need the heavier multi-camera review workflow.

Current behavior:

1. `/recordings` stays optimized for search, filtering, and opening a single saved segment quickly.
2. the dedicated timeline review page lives separately in navigation and behaves like a standalone synchronized review module rather than a second recordings browser.
3. the module now opens into a single large preview stage with a right-hand vertical scrub rail and a bottom camera strip, so operators can keep one feed in focus while switching cameras quickly.
4. the review screen auto-loads available recorded cameras into the bottom strip instead of starting with a separate camera-selection step or a saved wall layout.
5. the review timeline defaults to the previous display day plus the current display day. Expand **Date range & cameras** to change dates or camera selection; leaving all camera boxes unchecked shows all available cameras. Reset restores the default range and camera list. Filters open automatically when the selected range has no clips.
6. the active stage loads the saved clip that overlaps the selected timeline focus time for the currently active camera, while cameras without a clip at that time stay visibly empty until the operator switches feeds or moves the focus.
7. the timeline supports dragging the focus line, clicking thumbnail rail events, hour-jump labels, scrub sprite previews, explicit zoom controls, keyboard focus movement, and synchronized autoplay within the active preview stage.
8. timeline scrolling remains native: wheel and touch gestures scroll the rail, Ctrl/Command + wheel zooms around the pointer, and touch scrubbing starts only from the blue focus handle so the mobile rail does not trap page or timeline scrolling.
9. on narrow screens, the camera strip scrolls horizontally and the timeline keeps a bounded viewport. **Timeline** and **Back to video** links move between the player and rail. **Find selected time** centers the rail on its focus handle and gives it keyboard focus.
10. **Previous** / **Next** find saved clips across gaps within the selected camera and date range. The ten-second controls move the shared focus; The on-video playline seeks within the selected clip, shows elapsed/total time, and restores the previous playing or paused state after dragging. **Detail view** magnifies the vertical timeline around the selected moment; **Overview** resets its scale. **Go to time** accepts the displayed application timezone, even when the device uses another timezone. Camera selection, committed focus, and zoom are preserved in the URL for reloads and bookmarks.
11. the player exposes inline loading, buffering, and retry feedback. Timeline-loading errors have their own retry button. Playback stops visibly at gaps or at the range boundary; operators can use Next to continue across a gap.
12. the video retains its source proportions, camera metadata sits outside the footage, and a full-screen control appears when the browser supports it.
13. detailed searching, failure inspection, and one-off playback remain on `/recordings`, so the timeline screen stays focused on synchronized review only.
14. recorded entries still open a dedicated playback screen for focused review, and that screen still provides original-file download.
15. if a saved file is missing or the segment was skipped or failed, the detailed review page still exposes the recorder status and metadata without pretending playback is available.

## Wall Tiles Builder

Use `/wall-tiles` to build named monitoring layouts.

Current behavior:

1. create one or more named walls for different operators, rooms, or viewing objectives.
2. assign specific cameras to wall tiles instead of sending every enabled camera to the wall.
3. use the top grid itself as the editor, with each tile exposing its own camera source, orientation, and span controls directly inside the tile.
4. drag tiles inside that grid to reorder how cameras are packed across the live wall.
5. choose tile orientation and span in place so priority cameras can occupy more grid space immediately.
6. remove a tile entirely when you do not want that feed shown on the live wall.
7. mark one wall as the default wall used by `/live-wall` when no query string override is provided.

Disabled camera records can still remain assigned in the builder, but `/live-wall` only renders enabled camera records attached to the saved wall layout.

## Live Wall Playback

Use `/live-wall` to view the selected or default active wall.

Current behavior:

1. the wall resolves the selected active layout and renders only its enabled camera tiles.
2. each tile picks a lower-cost RTSP profile when the camera exposes one.
3. the selected profile is mapped to a shared MediaMTX path and transcoded on demand into a WebRTC-safe stream.
4. Camera Fleet can override live-relay transcode behavior per camera when a specific HEVC or otherwise browser-unsafe feed needs different quality or constant-bitrate settings than the stack default.
5. the browser-facing tile renders a Laravel-owned player shell instead of the stock public MediaMTX page.
6. the player requests `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token and the proxied WHEP URL for the selected camera path.
7. the browser loads the official per-path MediaMTX `reader.js` and opens the WHEP session with that token.
8. MediaMTX validates both the WebRTC read and the internal ffmpeg RTSP publisher through the Laravel auth callback.
9. operators can promote one live tile at a time to output wall audio, while every other tile stays muted, the selected source is visibly marked, and a shared wall volume slider sits in the bottom dock.
10. the standalone camera player exposes an `Open relay` link that remuxes the selected camera video with ffmpeg stream copy instead of a re-encode.
11. relay configuration can be refreshed with `php artisan relay:sync` when enabled cameras, RTSP selections, relay auth settings, or camera-specific live-transcode overrides change.
12. large walls stagger browser connection startup and retry with jittered backoff so a relay or network recovery does not make every tile reconnect at once.
13. a wall tile that stays outside the viewport for 30 seconds is disconnected until it approaches the viewport again; focused mode also disconnects dimmed tiles, and a background tab releases all receivers after 10 seconds. These states resume automatically and never substitute a saved preview for the live feed.
14. use a tile’s focus button to enlarge it; the same button or Escape returns to the grid and restores the scroll position. Double-click and nearby double-tap remain shortcuts. Other camera receivers pause while focused.
15. Connecting changes to Live only after video frames arrive. Portrait/square tile containers preserve the video’s proportions. A tile with no live path keeps its camera name and links to Camera Fleet; configure its path and reopen the wall.
16. the mobile Controls drawer contains volume and wall configuration, closes on Escape or outside interaction, and stays within the viewport. Navigation arrows appear only when another active wall exists.

This gives operators a shared live view path for multiple simultaneous viewers while keeping a separate no-transcode path available for consumers that do not need WebRTC.

## Reverse Proxy Workflow

When the operator UI is published behind Nginx on a host like `monitor.schollinetz.com`:

1. Laravel serves the main application from the normal web root.
2. Nginx proxies MediaMTX HTTP player and WHEP requests under a public prefix such as `/__webrtc/`.
3. MediaMTX advertises that public prefix from `APP_URL` by default, and `MEDIAMTX_WEBRTC_PUBLIC_URL` plus `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS` remain available when the relay needs explicit overrides.
4. the proxy should preserve the prefixed WHEP session URLs with `X-Forwarded-Prefix` and `proxy_redirect`, otherwise WHEP `PATCH` and `DELETE` calls will fall back to unprefixed session URLs and fail.
5. ICE transport still needs direct host or firewall exposure on the configured WebRTC ports.
6. the MediaMTX auth callback URL and internal publisher credentials must be in sync with Laravel config before secure playback can work.

The same-host deployment model should expose MediaMTX directly on `MEDIAMTX_ICE_PORT` (default 8190) rather than trying to proxy that same port back through a site-level Nginx `stream` block.

## Deletion

Deleting a camera also removes its per-camera preview storage through `CameraStorageService`.

That same per-camera storage root now also contains recording segments, so deleting the camera removes both previews and recordings together.

## Live stream diagnostics and compatibility

ONVIF profile discovery takes the encoding and resolution from the video encoder configuration, even when audio configuration appears first. RTSP diagnostics persist the detected B-frame count; H.264 with detected B-frames is transcoded for WebRTC because those frames are not supported by MediaMTX WebRTC delivery. Unprobed H.264 retains the existing copy behavior; the operator can still force compatibility transcoding. Refresh profiles and run diagnostics to update older saved metadata. Changing compatibility mode can reload the shared source path; allow for a brief interruption to its readers.

When diagnostics fall back to a live relay, they preserve the original source codec and B-frame metadata: a successfully probed H.264 relay does not prove that the hardware camera emits H.264. Probes of the canonical source and original recording buffer remain authoritative for source metadata.

Motion sampling, cache behavior, interpretation, and validation are detailed in [motion-triggering-audit.md](motion-triggering-audit.md).
