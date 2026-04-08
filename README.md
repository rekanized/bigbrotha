# BigBrothas

BigBrothas is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, scheduler-driven per-camera recording, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. Operator access is expected to be authenticated through Google OAuth in Laravel, and Live Wall playback now uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Stack

- Laravel 13 on PHP 8.3.
- Blade plus Livewire 4 for the operator UI.
- Laravel Socialite for Google sign-in.
- Standard CSS under `public/css`.
- bundled or system ffmpeg and ffprobe for RTSP diagnostics and preview generation.
- ffmpeg stream-copy recording with persistent continuous segmenting and a rolling short-segment motion buffer that stitches dynamic motion events with pre-roll and resettable post-trigger time.
- MediaMTX for shared WebRTC fan-out from RTSP camera sources.
- Admin-managed Google sign-in allowlist with automatic first-user bootstrap.
- Scheduled preview refreshes and recording retention cleanup can run with Laravel's scheduler so saved thumbnails and recording segments stay current without operator intervention.

## Important Constraints

- This is a Composer-only project.
- Do not add Node.js tooling, Vite, Tailwind, Bootstrap, Sass, Less, or any build pipeline unless explicitly requested.
- HTTP access restriction is driven by `WEBSITE_ALLOWED_IPS`; an empty value disables the allow list.
- If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers.
- That HTTP restriction does not control ONVIF WS-Discovery multicast behavior.

## Main Routes

- `/login` Google sign-in entry.
- `/` authenticated redirect to `/camera-fleet`.
- `/camera-fleet` camera inventory and management.
- `/recordings` recording browser and saved segment search.
- `/recordings/{recording}` recording playback and review screen.
- `/admin/users` admin-only operator access page for approved sign-in emails, stored users, and admin roles.
- `/admin/settings` admin-only operator settings such as the display timezone and recorder runtime status.
- `/wall-tiles` named wall and tile layout builder.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` private preview image endpoint.
- `/recordings/{recording}/stream` Laravel-served MP4 playback stream for private recordings.
- `/recordings/{recording}/download` original private recording download.
- `/live-wall` live wall entry.
- `/live-wall/{camera}/player` Laravel-served secure player page.
- `/live-wall/{camera}/session` Laravel-authenticated WebRTC bootstrap endpoint.
- `/live-wall/{camera}/stream` legacy MJPEG live endpoint.
- `/live-wall/{camera}/relay` copied live relay endpoint.
- `/relay/auth/mediamtx` MediaMTX HTTP auth callback.
- `/__webrtc/{camera-path}/` proxied MediaMTX WebRTC player path when deployed behind Nginx.
- `/discovery/onvif-sweep` ONVIF discovery and manual endpoint probing.

## Live Wall Flow

1. An operator signs in through Google OAuth and receives a normal Laravel session.
2. `/wall-tiles` is used to create named walls, assign camera tiles, and choose tile orientation or span.
3. `/live-wall` resolves the selected or default active wall and only renders cameras assigned to enabled tiles on that wall.
4. `/live-wall/{camera}/player` still renders a Laravel-owned player shell instead of exposing the stock public MediaMTX iframe page.
5. The browser calls `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token plus the WHEP URL for the selected camera path.
6. `public/js/live-wall-player.js` loads the official per-path MediaMTX `reader.js` and passes the signed token as a bearer token.
7. MediaMTX calls `/relay/auth/mediamtx` before allowing a WebRTC read.
8. If the path is idle, MediaMTX starts ffmpeg through `runOnDemand`, ffmpeg pulls the selected camera RTSP URI, copies H.264 video when the source is already browser-safe or otherwise transcodes to browser-safe H.264, normalizes audio timestamps while transcoding audio to Opus, and republishes locally to `rtsp://publisher:...@127.0.0.1:8554/$MTX_PATH`.
9. The auth callback accepts that local RTSP publish only when it matches the configured internal publisher credentials and comes from loopback.

## Relay Settings

Production deployments should set these values explicitly:

- `APP_URL`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`

MediaMTX now derives its default public WebRTC URL from `APP_URL` as `APP_URL + /__webrtc`, derives the auth callback URL from the same app origin, and derives relay secrets plus internal publisher credentials from `APP_KEY`. Only set `MEDIAMTX_WEBRTC_PUBLIC_URL`, `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`, `MEDIAMTX_WEBRTC_ALLOW_ORIGINS`, `MEDIAMTX_AUTH_CALLBACK_URL`, `MEDIAMTX_AUTH_CALLBACK_SECRET`, or `MEDIAMTX_AUTH_TOKEN_SECRET` when a deployment needs a non-default relay topology or explicit secret override.
Most MediaMTX runtime knobs now live directly in `config/mediamtx.php`, not in `.env`.

After changing relay or auth-related environment values, run:

```bash
php artisan config:clear
php artisan view:clear
php artisan relay:sync
```

In normal operation this is enough. A `php-fpm` restart is only needed if the host is serving stale PHP bytecode through OPcache.

## Production Setup

Use this short checklist during deployment:

1. Install PHP 8.3, Composer, a database, cron, and a user-level or system-level process manager.
2. Run `composer install --no-dev --optimize-autoloader`.
3. Commit the Linux-compatible statically compiled `ffmpeg` and `ffprobe` binaries in `bin/`. By default Laravel resolves those binaries and the FFmpeg temp directory from the project root dynamically through `config/ffmpeg.php`, so `.env` only needs `FFMPEG_BINARIES`, `FFPROBE_BINARIES`, or `FFMPEG_TEMPORARY_DIRECTORY` if a deployment wants an explicit override. Then create `.env`, set database, Google auth, `APP_URL`, and recording worker values, and run `php artisan key:generate` if the app key is still empty. MediaMTX will derive its same-host relay defaults from `APP_URL` and `APP_KEY`; only add MediaMTX env overrides if the relay is published on a different origin, prefix, or credential set.
4. Run `php artisan migrate --force`.
5. Run `composer relay:install` and `php artisan relay:sync`.
6. Run `composer recordings:worker:install` so Laravel writes and enables the recordings worker systemd unit.
7. Add cron for `* * * * * cd /var/www/bigbrothas && php artisan schedule:run >> /dev/null 2>&1`.
8. Run `php artisan optimize:clear`, `php artisan relay:start`, and `php artisan camera-recordings:ensure-worker`.

If the host uses a specific PHP binary such as `/usr/bin/php8.3`, set `CAMERA_RECORDING_WORKER_PHP_BINARY` in `.env` before running `composer recordings:worker:install` so the generated worker unit uses the correct interpreter.

Composer now reapplies execute permissions to `bin/ffmpeg` and `bin/ffprobe` on `install` and `update`. If you replace those files manually, rerun `composer ffmpeg:binaries:chmod`.

If you build this app into Docker, copy the repository `bin/` directory into the image so the container uses the same pinned binaries Laravel is configured to resolve.

For the full first-time bootstrap flow, see [docs/startup-from-scratch.md](docs/startup-from-scratch.md).

## Project Context

- [AGENTS.md](AGENTS.md)
- [docs/video-platform-architecture.md](docs/video-platform-architecture.md)
- [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md)
- [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md)
- [docs/startup-from-scratch.md](docs/startup-from-scratch.md)

## Useful Commands

```bash
php artisan test
php artisan test tests/Feature/CameraFleetManagerTest.php
php artisan test tests/Feature/CameraRecordingCommandTest.php tests/Feature/CameraRecordingMotionCommandTest.php tests/Feature/CameraRecordingMaintenanceCommandTest.php
php artisan test tests/Feature/RtspStreamDiagnosticsServiceTest.php
php artisan camera-fleet:refresh-previews
php artisan camera-recordings:install-worker-service
php artisan camera-recordings:ensure-worker
php artisan camera-recordings:tick
php artisan camera-recordings:prune
php artisan camera-recordings:prune-audit
php artisan camera-recordings:orphans
php artisan camera-recordings:orphans --purge
php artisan migrate
php artisan camera-recordings:build-review-assets --missing
php artisan camera-recordings:build-review-assets --missing --limit=10
php artisan camera-recordings:queue-review-assets --camera_id=12
php artisan camera-recordings:queue-review-assets --date_from=2026-04-01 --date_to=2026-04-03
php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256
composer recordings:worker:install
composer relay:install
php artisan config:clear
php artisan view:clear
php artisan relay:sync
php artisan relay:start
php artisan relay:status
```

Run `php artisan schedule:run` from cron every minute so the built-in preview refresh, recording tasks, and bounded missing review-asset backfill continue automatically. Recording jobs are still queued, so production also needs a queue worker process for the `recordings` queue. The scheduler now also runs `camera-recordings:build-review-assets --missing --limit=...` as a safety net for preview MP4 and scrub-sprite generation; tune it with `CAMERA_REVIEW_ASSET_SCHEDULER_ENABLED` and `CAMERA_REVIEW_ASSET_SCHEDULER_LIMIT`. If you want Laravel's minute scheduler to act as a worker safety net too, enable `CAMERA_RECORDING_ENSURE_WORKER=true` and point `CAMERA_RECORDING_WORKER_SYSTEMD_SERVICE` at the installed unit so `camera-recordings:ensure-worker` can start it when the worker is absent.

Recordings worker sizing is now dynamic by default. `CAMERA_RECORDING_WORKER_PROCESSES` remains the minimum worker count, and the worker supervisor can scale above that floor up to `CAMERA_RECORDING_WORKER_MAX_PROCESSES` using both enabled recording-camera count and queued `recordings,default` job backlog. Tune the ramp with `CAMERA_RECORDING_WORKER_CAMERAS_PER_PROCESS` and `CAMERA_RECORDING_WORKER_JOBS_PER_PROCESS`.

For repeatable setup on Linux hosts with user systemd available, run `php artisan camera-recordings:install-worker-service` once during deployment, or use `composer recordings:worker:install`. That removes the hand-edited unit file step, but it still relies on systemd for real background-process persistence.

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
