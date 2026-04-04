# Camera Fleet Workflow

## Goal

The Camera Fleet is the main operator workflow for turning discovered or known devices into managed camera records with saved RTSP profiles and preview diagnostics.

## Entry Paths

### ONVIF Sweep

Use `/discovery/onvif-sweep` to run WS-Discovery across the network.

Best when:

- cameras respond to multicast.
- the network allows WS-Discovery traffic.

### Manual ONVIF Probe

The same screen supports direct ONVIF endpoint verification with:

- ONVIF URL.
- optional username.
- optional password.

Best when:

- multicast discovery returns nothing.
- a specific device address is already known.
- authentication is required to validate the device.

Successful manual probes can be saved directly into the fleet.

## Provisioning Flow

Verified manual probe results can create or update a camera using:

- device service URL.
- credentials.
- manufacturer.
- model.
- serial number.
- verification metadata.

The provisioning flow tries to match existing cameras before creating duplicates.

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

## Camera Editor Modal

The modal is the main management surface for a selected camera.

It supports:

- editing network and endpoint fields.
- editing credentials.
- toggling ONVIF and RTSP support.
- selecting a recording mode per camera.
- choosing recording retention in days.
- setting motion sensitivity and the motion-analysis region as percentages of the feed.
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
5. It updates the primary RTSP path and port on the camera when possible.
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
2. movement recording uses a basic ffmpeg scene-change check over a cropped feed region defined by left, top, width, and height percentages.
3. higher motion sensitivity values react to smaller pixel changes.
4. the selected recording source can stay on automatic primary-profile selection or target a saved RTSP profile explicitly.
5. retention is currently enforced per camera in whole days, with the default workflow set to one day.

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
2. continuous mode uses the scheduler tick as a bootstrap or recovery trigger, then immediately chains the next direct-to-disk segment from the end of the current one instead of waiting for the next minute boundary.
3. motion mode first samples a short cropped analysis window and only writes a segment when the selected region crosses the configured threshold.
4. recording work is queue-backed and guarded by a per-camera lock so duplicate overlapping segment jobs are avoided.
5. `camera-recordings:prune` runs hourly and removes files whose row `created_at` time is older than the camera's retention window, and `camera-recordings:prune-audit` can be used to inspect the same candidates without deleting anything.

Continuous timestamp behavior:

1. a continuous segment now stamps `scheduled_for` and `started_at` at the actual capture start once ffmpeg begins the segment job.
2. `ended_at` is derived from that capture start plus the configured segment duration instead of from PHP process cleanup time.
3. exact timeline-boundary focus points resolve to the following adjacent clip, so operators do not lose the next clip behind an inclusive edge match.

Production deployments should run a queue worker for the `recordings` queue in addition to the normal scheduler.

## Live Wall Playback

Use `/wall-tiles` to choose what the wall should show, then open `/live-wall` to monitor the configured result.

## Recordings Browser

Use `/recordings` for the fast recordings browser, then open `/recordings/timeline` when you need the heavier multi-camera review workflow.

Current behavior:

1. `/recordings` stays optimized for search, filtering, and opening a single saved segment quickly.
2. the dedicated timeline review page lives separately in navigation and behaves like a standalone synchronized review module rather than a second recordings browser.
3. the module now opens into a single large preview stage with a right-hand vertical scrub rail and a bottom camera strip, so operators can keep one feed in focus while switching cameras quickly.
4. the review screen auto-loads available recorded cameras into the bottom strip instead of starting with a separate camera-selection step or a saved wall layout.
5. the review timeline loads a padded multi-day span for the loaded cameras so zooming out still exposes meaningful date range context even when clips only exist on one day.
6. the active stage loads the saved clip that overlaps the selected timeline focus time for the currently active camera, while cameras without a clip at that time stay visibly empty until the operator switches feeds or moves the focus.
7. the timeline supports dragging the focus line, clicking thumbnail rail events, hour-jump labels, scrub sprite hover previews in the stage, and synchronized autoplay within the active preview stage.
8. detailed searching, failure inspection, and one-off playback remain on `/recordings`, so the timeline screen stays focused on synchronized review only.
9. recorded entries still open a dedicated playback screen for focused review, and that screen still provides original-file download.
10. if a saved file is missing or the segment was skipped or failed, the detailed review page still exposes the recorder status and metadata without pretending playback is available.

## Wall Tiles Builder

Use `/wall-tiles` to build named monitoring layouts.

Current behavior:

1. create one or more named walls for different operators, rooms, or viewing objectives.
2. assign specific cameras to wall tiles instead of sending every enabled camera to the wall.
3. drag tile rows to reorder how cameras are packed across the live wall.
4. choose tile orientation per assignment with landscape, portrait, or square framing.
5. choose tile span so priority cameras can occupy more grid space.
6. mark one wall as the default wall used by `/live-wall` when no query string override is provided.

Disabled cameras can still remain assigned in the builder, but `/live-wall` only renders enabled camera records attached to enabled wall tiles.

## Live Wall Playback

Use `/live-wall` to view the selected or default active wall.

Current behavior:

1. the wall resolves the selected active layout and renders only its enabled camera tiles.
2. each tile picks a lower-cost RTSP profile when the camera exposes one.
3. the selected profile is mapped to a shared MediaMTX path and transcoded on demand into a WebRTC-safe stream.
4. the browser-facing tile renders a Laravel-owned player shell instead of the stock public MediaMTX page.
5. the player requests `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token and the proxied WHEP URL for the selected camera path.
6. the browser loads the official per-path MediaMTX `reader.js` and opens the WHEP session with that token.
7. MediaMTX validates both the WebRTC read and the internal ffmpeg RTSP publisher through the Laravel auth callback.
8. operators can promote one live tile at a time to output wall audio, while every other tile stays muted, the selected source is visibly marked, and a shared wall volume slider sits in the bottom dock.
9. each tile still exposes an `Open relay` link that remuxes the selected camera video with ffmpeg stream copy instead of a re-encode.
10. relay configuration can be refreshed with `php artisan relay:sync` when enabled cameras, RTSP selections, or relay auth settings change.

This gives operators a shared live view path for multiple simultaneous viewers while keeping a separate no-transcode path available for consumers that do not need WebRTC.

## Reverse Proxy Workflow

When the operator UI is published behind Nginx on a host like `monitor.schollinetz.com`:

1. Laravel serves the main application from the normal web root.
2. Nginx proxies MediaMTX HTTP player and WHEP requests under a public prefix such as `/__webrtc/`.
3. MediaMTX advertises that public prefix through `MEDIAMTX_WEBRTC_PUBLIC_URL` and `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`.
4. the proxy should preserve the prefixed WHEP session URLs with `X-Forwarded-Prefix` and `proxy_redirect`, otherwise WHEP `PATCH` and `DELETE` calls will fall back to unprefixed session URLs and fail.
5. ICE transport still needs direct host or firewall exposure on the configured WebRTC ports.
6. the MediaMTX auth callback URL and internal publisher credentials must be in sync with Laravel config before secure playback can work.

The same-host deployment model should expose MediaMTX directly on `8189` rather than trying to proxy that same port back through a site-level Nginx `stream` block.

## Deletion

Deleting a camera also removes its per-camera preview storage through `CameraStorageService`.

That same per-camera storage root now also contains recording segments, so deleting the camera removes both previews and recordings together.