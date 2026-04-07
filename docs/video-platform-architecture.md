# Video Platform Architecture

## Purpose

This application is an operator-facing camera platform for ONVIF and RTSP devices. The implemented foundation covers discovery, provisioning, camera management, RTSP URL retrieval, backend stream checks, preview capture, scheduler-driven recording, and shared WebRTC live-wall delivery.

## Runtime Stack

- Laravel 13.
- PHP 8.3.
- Livewire 4 for server-driven UI behavior.
- Blade templates and standard CSS.
- Google OAuth via Laravel Socialite for operator sign-in.
- ffmpeg and ffprobe configured through `config/ffmpeg.php` and service bindings in `app/Providers/AppServiceProvider.php`.
- Laravel scheduler plus queue workers for preview maintenance and per-camera recording jobs.
- MediaMTX as the shared WebRTC relay managed from Laravel and installed through Composer-driven commands.

## Route Map

- `/login` for Google sign-in.
- `/` as an authenticated redirect to `/camera-fleet`.
- `/camera-fleet` via `App\Http\Controllers\CameraFleetController` and `App\Livewire\CameraFleet\Manager`.
- `/recordings` plus `/recordings/{recording}` via `App\Http\Controllers\RecordingController`.
- `/wall-tiles` via `App\Http\Controllers\WallTilesController` and `App\Livewire\LiveWall\TilesManager`.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` via `App\Http\Controllers\CameraFleetStreamPreviewController`.
- `/recordings/{recording}/stream` via `App\Http\Controllers\RecordingController@stream`.
- `/recordings/{recording}/download` via `App\Http\Controllers\RecordingController@download`.
- `/live-wall` via `App\Http\Controllers\LiveWallController`.
- `/live-wall/{camera}/player` via `App\Http\Controllers\LiveWallPlayerController`.
- `/live-wall/{camera}/session` via `App\Http\Controllers\LiveWallSessionController`.
- `/live-wall/{camera}/stream` via `App\Http\Controllers\LiveWallStreamController@mjpeg`.
- `/live-wall/{camera}/relay` via `App\Http\Controllers\LiveWallStreamController@relay`.
- `/relay/auth/mediamtx` via `App\Http\Controllers\Relay\MediaMtxAuthController`.
- `/discovery/onvif-sweep` via `App\Http\Controllers\Discovery\OnvifSweepController` and `App\Livewire\Discovery\OnvifSweep`.

## Core Domain Object

`App\Models\Camera` is the central persistence model for camera identity, credentials, and saved RTSP metadata.

`App\Models\LiveWall` and `App\Models\LiveWallTile` now persist named monitoring layouts and the explicit camera-to-tile assignments that control what operators actually see on `/live-wall`.

`App\Models\CameraRecording` persists scheduled recording segments, capture outcome, file metadata, and retention targets for each camera feed.

It stores:

- identity and network fields.
- ONVIF and RTSP endpoint parts.
- credentials.
- ONVIF and RTSP capability flags.
- recording mode, recording retention, and motion-analysis area settings.
- metadata for ONVIF verification and RTSP profiles.
- enable state and last-seen timestamps.

Important model helpers:

- `onvifEndpoint()`.
- `rtspEndpoint()`.
- `rtspProfiles()`.
- `latestRtspPreview()`.

## Discovery And Provisioning

- `App\Services\Discovery\OnvifWsDiscoveryService` handles WS-Discovery multicast probing.
- `App\Services\Onvif\OnvifDeviceProbeService` performs manual authenticated SOAP `GetDeviceInformation` requests.
- `App\Services\Onvif\OnvifCameraProvisioningService` maps verified probe results into `Camera` records.

## Stream Services

- `App\Services\Onvif\OnvifRtspStreamService` retrieves ONVIF media capabilities, profiles, and RTSP stream URIs.
- `App\Services\Onvif\RtspStreamDiagnosticsService` validates RTSP connectivity and captures preview frames.
- `App\Services\CameraStorageService` manages per-camera storage folders.
- `App\Services\CameraLiveStreamService` selects efficient wall profiles, proxies a browser-safe MJPEG live feed, and exposes a copied relay stream without re-encoding the camera video.
- `App\Services\CameraRecordingService` orchestrates recording policies, delegates continuous-mode process lifecycle to `App\Services\ContinuousRecordingSegmenterService`, uses a recording-specific ffmpeg RTSP input profile with larger buffers and timestamp recovery instead of sharing the live wall's low-latency probe settings, runs masked low-fps grayscale frame differencing on a normalized motion grid, records motion clips through a single buffered motion-only capture window whose pre-roll and post-trigger timing can be configured per camera, retains the pre-roll portion as context only, saves the full clip when motion crosses the threshold during the monitored span after pre-roll, prefers the local MediaMTX recording relay when available so motion jobs do not reopen the camera directly, and prunes expired footage.
- `App\Services\Relay\MediaMtxConfigService` generates MediaMTX paths from enabled cameras.
- `App\Services\Relay\MediaMtxAccessTokenService` issues and validates short-lived signed MediaMTX read tokens.
- `App\Services\Relay\MediaMtxInstaller` downloads the pinned MediaMTX release into private storage.
- `App\Services\Relay\MediaMtxProcessService` syncs config, starts the relay process, and checks relay health.

## Operator Authentication

The operator UI now assumes Laravel session authentication.

Current behavior:

- guest users are redirected to `/login`.
- `/auth/google/redirect` and `/auth/google/callback` complete Google OAuth through Laravel Socialite.
- the first successful Google sign-in is allowed to bootstrap the system, is promoted to admin automatically, and is inserted into the operator allowlist.
- once bootstrap is complete, Google callback only admits email addresses stored in the admin-managed operator allowlist.
- `App\Http\Controllers\Auth\LogoutController` destroys the Laravel session.
- the top bar renders the authenticated operator name and sign-out action.
- MediaMTX reads are not treated as public access; the web session is used only to bootstrap short-lived relay tokens.

## Camera Fleet UI

The main operator management surface is `App\Livewire\CameraFleet\Manager` with the Blade view at `resources/views/livewire/camera-fleet/manager.blade.php`.

Current behavior includes:

- create, edit, enable, disable, and delete cameras.
- modal-based editing.
- RTSP profile refresh from ONVIF.
- per-profile RTSP connection testing.
- preview capture and preview display.
- recording mode, retention, movement threshold, and painted motion-mask editing over the live feed.
- recent recording queue and retention status directly in the editor.
- latest preview thumbnail directly in each fleet row.

## Recording Browser And Playback

The operator review surface for saved footage is `App\Http\Controllers\RecordingController` with Blade views under `resources/views/recordings`.

Current behavior includes:

- browser-side filtering by camera, recorder status, capture mode, date range, and free-text search.
- a dedicated playback page per saved segment.
- private playback remuxing through Laravel using ffmpeg stream copy from the saved container into fragmented MP4, with the review-stream route now streamed directly instead of buffering the full remuxed clip in PHP memory.
- direct download of the original private segment file for archival or external review.
- review of failed and skipped motion decisions alongside successful recordings so operators can troubleshoot policy behavior.

## Live Wall Delivery

`App\Http\Controllers\LiveWallController` now resolves the selected active wall, loads its saved tile assignments, and prepares each assigned camera with a preferred wall profile before rendering the Blade view at `resources/views/live-wall/index.blade.php`.

`App\Livewire\LiveWall\TilesManager` is the operator-facing builder for named walls and camera tiles, with a single WYSIWYG grid surface that embeds tile configuration controls directly inside each tile.

Current live viewing behavior:

- only displays cameras assigned to the selected active wall.
- allows multiple named walls with separate grid column counts and tile orientation or span settings.
- prefers a lower-cost RTSP profile such as a minor or sub stream when available.
- uses a shared MediaMTX WebRTC relay for operator wall playback.
- preserves optional camera audio in the shared relay by publishing an Opus audio track alongside the browser-safe H.264 wall video.
- lets operators select exactly one wall tile for live audio output at a time, with a shared wall volume control in the bottom dock and the active audio source marked directly on the wall.
- copies source H.264 video into the shared relay when the selected profile is already browser-safe, otherwise transcodes once per active camera into WebRTC-safe H.264 output through ffmpeg `runOnDemand` publishing, instead of one ffmpeg job per viewer.
- normalizes live audio timestamps and transcodes audio to Opus in the shared relay path so cameras with unstable AAC timing do not corrupt live playback.
- serves a Laravel-rendered player shell and an authenticated session bootstrap endpoint instead of embedding the stock public MediaMTX iframe page.
- uses `public/js/live-wall-player.js` to fetch session bootstrap data and then load the official per-path MediaMTX `reader.js` implementation.
- issues short-lived Laravel-signed MediaMTX read tokens per authenticated operator and per camera path.
- uses MediaMTX `authMethod: http` so the relay calls back into Laravel before accepting a WebRTC read.
- uses dedicated internal RTSP publisher credentials for the ffmpeg `runOnDemand` republish leg, so relay auth can stay enabled without blocking the local publisher.
- exposes `App\Http\Controllers\LiveWallStreamController@relay` for a no-transcode path that remuxes the selected video stream with `-c:v copy` into fragmented MP4.

## Live Wall Request Sequence

The current secure playback sequence is:

1. `App\Livewire\LiveWall\TilesManager` saves named walls and explicit camera tile assignments.
2. `App\Http\Controllers\LiveWallController` renders the selected wall's saved tile assignments with Laravel session bootstrap URLs.
3. `App\Http\Controllers\LiveWallPlayerController` renders the single-camera secure player page.
4. The browser calls `App\Http\Controllers\LiveWallSessionController` for `{ whep_url, reader_url, access_token }`.
5. `public/js/live-wall-player.js` loads the official MediaMTX `reader.js` script from the proxied path and opens the WHEP session with the bearer token.
6. MediaMTX calls `App\Http\Controllers\Relay\MediaMtxAuthController` with `action=read` and `protocol=webrtc`.
7. If the path has no active publisher, MediaMTX executes the configured ffmpeg `runOnDemand` command.
8. ffmpeg pulls the selected camera RTSP URI, copies H.264 video when the source is already browser-safe or otherwise transcodes to browser-safe H.264, normalizes audio timestamps while transcoding audio to Opus, and republishes locally to the same MediaMTX path over RTSP.
9. MediaMTX calls the same auth controller with `action=publish` and `protocol=rtsp` for that internal republish.
10. The auth controller accepts that local publish only when the configured publisher credentials match and the request IP is loopback.
11. Once the path is ready, WebRTC tracks are delivered to the browser and shared across additional viewers.

## MediaMTX Authentication Model

MediaMTX currently uses one HTTP auth callback for two different trust models:

- browser reads:
	- authenticated by Laravel-issued short-lived signed tokens.
	- expected action/protocol pair is `read` and `webrtc`.
- internal ffmpeg publisher:
	- authenticated by dedicated internal credentials configured through `MEDIAMTX_PUBLISHER_USER` and `MEDIAMTX_PUBLISHER_PASS`.
	- expected action/protocol pair is `publish` and `rtsp`.
	- expected source IP is loopback only.

This split keeps relay auth enabled for public-facing WebRTC while still allowing the local on-demand publisher to function.

Current relay management behavior:

- `config/mediamtx.php` pins the MediaMTX version and relay ports.
- `App\Services\Relay\MediaMtxInstaller` downloads the relay into `storage/app/private/mediamtx/releases/{version}`.
- `App\Services\Relay\MediaMtxConfigService` renders `storage/app/private/mediamtx/mediamtx.yml` from enabled cameras.
- `App\Services\Relay\MediaMtxProcessService` syncs config, starts the relay, reconciles stale pid files, and checks the Control API.
- relay liveness checks match the expected MediaMTX binary and config path from process arguments instead of relying only on `kill -0`, since the web worker may run as `www-data` while the relay process is owned by another user.
- `routes/console.php` exposes `relay:install`, `relay:sync`, `relay:start`, `relay:stop`, and `relay:status`.

Current recording management behavior:

- `routes/console.php` exposes `camera-recordings:tick`, `camera-recordings:prune`, and `camera-recordings:prune-audit`.
- `routes/console.php` also exposes `camera-recordings:install-worker-service` for provisioning the user systemd unit and `camera-recordings:ensure-worker`, which can be scheduled every minute to verify the bounded recording worker is present and to start the configured systemd unit when the worker is absent.
- the scheduler still evaluates recording work every minute, but continuous mode now uses that tick as a bootstrap, recovery, and segment-import safety net instead of the primary clip boundary.
- recording rows now move through explicit `queued`, `processing`, `recorded`, `skipped`, and `failed` states so the operator-facing browser can distinguish waiting work from active capture.
- motion recording jobs run through the Laravel queue, acquire a per-camera lock, and call ffmpeg directly so PHP never buffers camera payloads in memory.
- continuous mode now keeps one per-camera ffmpeg process alive with the segment muxer, writes timestamped direct-to-disk files under the normal camera recordings directory, and imports completed files back into `camera_recordings` rows so playback, review assets, and pruning keep the same downstream model.
- imported continuous segments derive `scheduled_for`, `started_at`, and `ended_at` from the segment filename timestamp plus the configured segment duration instead of from minute scheduler timing or per-segment PHP cleanup time.
- motion mode now captures a single motion-only buffered clip, evaluates the saved detection window inside that buffered clip against the painted mask, and only keeps the buffered clip when the threshold is crossed.
- motion events set a short active-event guard so later scheduler ticks skip duplicate motion triggers while the current motion clip is still being recorded and compiled.
- successful recordings are stored as per-camera segment files under private storage and expired files are removed by the hourly prune task.
- recording capture and motion analysis now use workload-specific ffmpeg RTSP input profiles, while the live wall and relay paths keep the more aggressive low-latency probe settings.
- prune eligibility now uses `camera_recordings.created_at < now()->subDays(recording_retention_days)` per camera, while `camera-recordings:prune-audit` reports the same candidates without deleting files or rows.
- stale `queued` or `processing` rows are re-dispatched on later scheduler ticks after the configured timeout window instead of remaining silently pending forever.
- terminal queue failures now write an explicit `failed` state back onto the recording row, and review-asset queue failures write a failed manifest instead of disappearing into worker logs alone.
- `/recordings/timeline` now lets operators choose the cameras they want to review directly instead of resolving them from a saved wall.
- the recordings timeline now mounts a Livewire review shell that keeps the selected camera and focus time in parent-owned state while rendering separate stage and rail child components.
- the timeline rail now hydrates only summary camera-strip data on first load, server-renders a small focus-centered segment window, and asks the parent Livewire review shell for additional segment windows as the operator scrolls.
- the review screen still loads a padded multi-day span for the selected cameras, but the JavaScript layer is now limited to transient rail dragging, scrub-preview overlays, and stage seek synchronization across Livewire rerenders.
- the vertical rail DOM is virtualized in plain JavaScript so only the visible tick, segment, and thumbnail nodes remain mounted, while thumbnail images hydrate through an `IntersectionObserver` rooted to the rail viewport.
- timeline segment selection now treats clip bounds as half-open ranges, so a focus time exactly on a shared clip edge resolves to the following adjacent segment instead of double-matching the earlier one.
- saved segments can generate private review assets under their `_review` directory, including a preview MP4, poster thumbnail, and scrub sprite sheet used for in-rail hover previews.

The WebRTC wall is intended for operator viewing with shared fan-out. The copy relay remains available for downstream consumers that want copied camera video without a re-encode step.

## Access Model

- Operator pages are intended to be protected by Laravel session authentication.
- Google OAuth is the primary sign-in path for the web application.
- MediaMTX WebRTC access is not treated as public; Laravel authorizes each read through a short-lived token.
- Direct ICE transport still requires network reachability on the configured WebRTC ports.

## Reverse Proxy Deployment

The application is now proxy-aware for same-host Nginx deployments.

Relevant behavior:

- `App\Http\Middleware\TrustReverseProxyHeaders` trusts configured `X-Forwarded-*` headers through `TRUSTED_PROXIES`.
- `App\Http\Middleware\RestrictWebsiteIp` evaluates the client IP after proxy normalization and is driven by `WEBSITE_ALLOWED_IPS`.
- MediaMTX player traffic is expected to be published behind a proxied path such as `/__webrtc/` through `MEDIAMTX_WEBRTC_PUBLIC_URL`.
- the reverse proxy must preserve the `/__webrtc` prefix on WHEP session URLs, typically with `X-Forwarded-Prefix` and `proxy_redirect` rules that rewrite upstream `Location` headers back under `/__webrtc/`.
- Media traffic still requires direct ICE reachability on the configured WebRTC transport ports, typically `8189/udp` and optionally `8189/tcp`.

## Storage Layout

Current preview storage layout:

`storage/app/private/cameras/{id}/previews`

Current recording storage layout:

`storage/app/private/cameras/{id}/recordings/{YYYY}/{MM}/{DD}`

Preview metadata stores relative paths like:

`cameras/{id}/previews/{slug}.jpg`

Recording metadata stores relative paths like:

`cameras/{id}/recordings/{YYYY}/{MM}/{DD}/{timestamp}-{mode}.mkv`

Older `storage/app/private/stream-previews` folders may still exist from previous iterations, but new preview writes should use the per-camera layout above.

## Preview Serving

`App\Http\Controllers\CameraFleetStreamPreviewController` serves saved previews.

Current behavior:

- resolves the saved preview path from the RTSP profile metadata.
- validates that the file is a real image.
- serves the image when valid.
- falls back to an inline SVG placeholder when the file is missing, corrupt, or incompatible.

This avoids broken image icons in the Camera Fleet UI.

## Access Restriction

`App\Http\Middleware\RestrictWebsiteIp` now reads the allow list from `WEBSITE_ALLOWED_IPS`.

This restriction applies to the web application, but not to UDP multicast discovery traffic.

If `WEBSITE_ALLOWED_IPS` is blank, the allow list is effectively disabled.