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
3. operators paint or erase that mask over a live stream preview, and the initial state starts with the full viewport selected. The preview video is display-only: the activity overlay and recorder state come from the backend recorder detector evaluating stable snapshots of the currently-written rolling-buffer segment with the current draft mask and threshold. The recorder flushes inner Matroska packets as they arrive so those snapshots advance during the segment instead of only when it closes. A snapshot caught between packets is treated as a transient waiting sample while the last confirmed overlay remains visible. The editor polls this near-live path after each completed analysis and immediately after draft edits.
4. the movement threshold is an effective trigger-pixel count over the selected mask; connected clusters receive bonus weight.
5. full-frame refreshes, decoder-startup churn, and coherent exposure or infrared-mode changes are rejected before the painted mask can start an event. Because the modal now calls the same detector on the same recorder buffer, those rejected artifacts no longer flash a false browser-side `Recording` state.
6. each camera now stores an explicit live-feed RTSP path and an explicit recording RTSP path, and those two values may be identical.
7. retention is currently enforced per camera in whole days, with the default workflow set to one day.

The first implementation prioritizes reliability and resource control: the recorder writes short direct-to-disk segments with ffmpeg stream copy instead of buffering or re-encoding in PHP.

## Scheduled Preview Refresh

Preview freshness no longer depends entirely on a human running a stream test.

Current behavior:

1. `camera-fleet:refresh-previews` scans enabled cameras with saved RTSP profiles.
2. Each eligible camera dispatches a preview refresh job that re-tests the preferred profile and captures a fresh thumbnail.
3. The command is scheduled every 30 minutes through Laravel's scheduler.
4. Existing preview metadata is retained when a profile refresh returns the same ONVIF stream definition.

Deployments should run `php artisan schedule:run` every minute from cron or an equivalent scheduler so these preview refreshes continue automatically.

## Scheduled Recording

Recording maintenance is also scheduler-driven.

Current behavior:

1. `camera-recordings:tick` runs every minute and queues one recording decision per eligible camera.
2. continuous mode uses the scheduler tick as a bootstrap or recovery trigger, keeps one ffmpeg segment-muxer process running per camera, and imports completed direct-to-disk files without waiting for minute boundaries.
3. motion mode now captures one buffered motion-only clip that includes the per-camera pre-roll context and the monitored span that follows it, then saves the whole clip when motion crosses the threshold during that monitored span.
4. motion events ignore new motion triggers while an existing motion clip is still being compiled so overlapping motion files are not generated for the same camera.
5. motion capture and review-asset work stay queue-backed and guarded by a per-camera lock so duplicate overlapping jobs are avoided, while continuous mode is watchdog-managed by the persistent segmenter service.
6. the minute scheduler sweeps the `failed_jobs` table and requeues eligible failed jobs up to the configured automatic retry limit; exhausted rows remain visible for the configured terminal retention window and are then pruned hourly, preventing indefinite accumulation while preserving a diagnostic window.
7. `camera-recordings:prune` runs hourly and removes files whose row `created_at` time is older than the camera's retention window, and `camera-recordings:prune-audit` can be used to inspect the same candidates without deleting anything.

Continuous timestamp behavior:

1. a continuous segment now stamps `scheduled_for` and `started_at` from the imported segment filename timestamp written by the persistent ffmpeg segmenter.
2. `ended_at` is derived from that imported segment start plus the configured segment duration instead of from PHP process cleanup time.
3. exact timeline-boundary focus points resolve to the following adjacent clip, so operators do not lose the next clip behind an inclusive edge match.

Production deployments should run both the minute scheduler and a queue worker that polls `recordings,default,review-assets` in that order so the continuous watchdog, motion clips, and delayed review assets all keep flowing without preview work blocking capture first.

## Camera Editor Workflow

The Camera Fleet editor keeps the full camera configuration in one modal while making the long form navigable for operators.

Current behavior:

1. the modal title identifies the selected camera and states that edits are not applied until they are saved.
2. a persistent section navigator jumps directly to identity, stream access, live relay, recording, availability, diagnostics, and recent activity.
3. the primary save and cancel actions remain visible at the bottom of the modal while its configuration sections scroll.
4. keyboard focus moves into the dialog when it opens, stays inside the modal while tabbing, returns to the launching control after close, and Escape cancels the editor.
5. narrow layouts retain the same sections and actions without horizontal page overflow; the section navigator itself scrolls horizontally.

## Live Wall Playback

Use `/wall-tiles` to choose what the wall should show, then open `/live-wall` to monitor the configured result.

## Recordings Browser

Use `/recordings` for the fast recordings browser, then open `/recordings/timeline` when you need the heavier multi-camera review workflow.

Current behavior:

1. `/recordings` stays optimized for search, filtering, and opening a single saved segment quickly.
2. the dedicated timeline review page lives separately in navigation and behaves like a standalone synchronized review module rather than a second recordings browser.
3. the module now opens into a single large preview stage with a right-hand vertical scrub rail and a bottom camera strip, so operators can keep one feed in focus while switching cameras quickly.
4. the review screen auto-loads available recorded cameras into the bottom strip instead of starting with a separate camera-selection step or a saved wall layout.
5. the review timeline now defaults to the previous display day plus the current display day, and operators can submit a custom `From` / `To` date span when they need a narrower or older range.
6. the active stage loads the saved clip that overlaps the selected timeline focus time for the currently active camera, while cameras without a clip at that time stay visibly empty until the operator switches feeds or moves the focus.
7. the timeline supports dragging the focus line, clicking thumbnail rail events, hour-jump labels, scrub sprite previews, explicit zoom controls, keyboard focus movement, and synchronized autoplay within the active preview stage.
8. timeline scrolling remains native: wheel and touch gestures scroll the rail, Ctrl/Command + wheel zooms around the pointer, and touch scrubbing starts only from the blue focus handle so the mobile rail does not trap page or timeline scrolling.
9. on narrow screens, the camera strip becomes a horizontal touch scroller, the timeline keeps a bounded viewport, and the rail exposes loading or retry feedback instead of silently failing while more segment windows are requested.
10. detailed searching, failure inspection, and one-off playback remain on `/recordings`, so the timeline screen stays focused on synchronized review only.
11. recorded entries still open a dedicated playback screen for focused review, and that screen still provides original-file download.
12. if a saved file is missing or the segment was skipped or failed, the detailed review page still exposes the recorder status and metadata without pretending playback is available.

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
10. each tile still exposes an `Open relay` link that remuxes the selected camera video with ffmpeg stream copy instead of a re-encode.
11. relay configuration can be refreshed with `php artisan relay:sync` when enabled cameras, RTSP selections, relay auth settings, or camera-specific live-transcode overrides change.
12. large walls stagger browser connection startup and retry with jittered backoff so a relay or network recovery does not make every tile reconnect at once.
13. a wall tile that stays outside the viewport for 30 seconds is disconnected until it approaches the viewport again; focused mode also disconnects dimmed tiles, and a background tab releases all receivers after 10 seconds. These states resume automatically and never substitute a saved preview for the live feed.

This gives operators a shared live view path for multiple simultaneous viewers while keeping a separate no-transcode path available for consumers that do not need WebRTC.

## Reverse Proxy Workflow

When the operator UI is published behind Nginx on a host like `monitor.schollinetz.com`:

1. Laravel serves the main application from the normal web root.
2. Nginx proxies MediaMTX HTTP player and WHEP requests under a public prefix such as `/__webrtc/`.
3. MediaMTX advertises that public prefix from `APP_URL` by default, and `MEDIAMTX_WEBRTC_PUBLIC_URL` plus `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS` remain available when the relay needs explicit overrides.
4. the proxy should preserve the prefixed WHEP session URLs with `X-Forwarded-Prefix` and `proxy_redirect`, otherwise WHEP `PATCH` and `DELETE` calls will fall back to unprefixed session URLs and fail.
5. ICE transport still needs direct host or firewall exposure on the configured WebRTC ports.
6. the MediaMTX auth callback URL and internal publisher credentials must be in sync with Laravel config before secure playback can work.

The same-host deployment model should expose MediaMTX directly on `8189` rather than trying to proxy that same port back through a site-level Nginx `stream` block.

## Deletion

Deleting a camera also removes its per-camera preview storage through `CameraStorageService`.

That same per-camera storage root now also contains recording segments, so deleting the camera removes both previews and recordings together.
