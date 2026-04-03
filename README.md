# BigBrothas

BigBrothas is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, scheduler-driven per-camera recording, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. Operator access is expected to be authenticated through Google OAuth in Laravel, and Live Wall playback now uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Stack

- Laravel 13 on PHP 8.3.
- Blade plus Livewire 4 for the operator UI.
- Laravel Socialite for Google sign-in.
- Standard CSS under `public/css`.
- ffmpeg and ffprobe for RTSP diagnostics and preview generation.
- ffmpeg stream-copy recording with optional motion-triggered capture on a cropped analysis region.
- MediaMTX for shared WebRTC fan-out from RTSP camera sources.
- Scheduled preview refreshes and recording retention cleanup can run with Laravel's scheduler so saved thumbnails and recording segments stay current without operator intervention.

## Important Constraints

- This is a Composer-only project.
- Do not add Node.js tooling, Vite, Tailwind, Bootstrap, Sass, Less, or any build pipeline unless explicitly requested.
- HTTP access restriction is driven by `WEBSITE_ALLOWED_IPS`; an empty value disables the allow list.
- If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers.
- That HTTP restriction does not control ONVIF WS-Discovery multicast behavior.

## Main Routes

- `/login` Google sign-in entry.
- `/` dashboard.
- `/camera-fleet` camera inventory and management.
- `/recordings` recording browser and saved segment search.
- `/recordings/{recording}` recording playback and review screen.
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
8. If the path is idle, MediaMTX starts ffmpeg through `runOnDemand`, ffmpeg pulls the selected camera RTSP URI, transcodes to browser-safe H.264 baseline, and republishes locally to `rtsp://publisher:...@127.0.0.1:8554/$MTX_PATH`.
9. The auth callback accepts that local RTSP publish only when it matches the configured internal publisher credentials and comes from loopback.

## Relay Settings

Production deployments should set these values explicitly:

- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `MEDIAMTX_WEBRTC_PUBLIC_URL`
- `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`
- `MEDIAMTX_AUTH_CALLBACK_URL`
- `MEDIAMTX_AUTH_CALLBACK_SECRET`
- `MEDIAMTX_AUTH_TOKEN_SECRET`
- `MEDIAMTX_PUBLISHER_USER`
- `MEDIAMTX_PUBLISHER_PASS`
- `CAMERA_RECORDING_QUEUE`

After changing relay or auth-related environment values, run:

```bash
php artisan config:clear
php artisan view:clear
php artisan relay:sync
```

In normal operation this is enough. A `php-fpm` restart is only needed if the host is serving stale PHP bytecode through OPcache.

## Production Setup

Use this short checklist during deployment:

1. Install PHP 8.3, Composer, ffmpeg, ffprobe, a database, cron, and a user-level or system-level process manager.
2. Run `composer install --no-dev --optimize-autoloader`.
3. Create `.env`, set database, Google auth, ffmpeg, MediaMTX, and recording worker values, then run `php artisan key:generate` if the app key is still empty.
4. Run `php artisan migrate --force`.
5. Run `composer relay:install` and `php artisan relay:sync`.
6. Run `composer recordings:worker:install` so Laravel writes and enables the recordings worker systemd unit.
7. Add cron for `* * * * * cd /var/www/bigbrothas && php artisan schedule:run >> /dev/null 2>&1`.
8. Run `php artisan optimize:clear`, `php artisan relay:start`, and `php artisan camera-recordings:ensure-worker`.

If the host uses a specific PHP binary such as `/usr/bin/php8.3`, set `CAMERA_RECORDING_WORKER_PHP_BINARY` in `.env` before running `composer recordings:worker:install` so the generated worker unit uses the correct interpreter.

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
php artisan test tests/Feature/CameraRecordingCommandTest.php
php artisan test tests/Feature/RtspStreamDiagnosticsServiceTest.php
php artisan camera-fleet:refresh-previews
php artisan camera-recordings:install-worker-service
php artisan camera-recordings:ensure-worker
php artisan camera-recordings:tick
php artisan camera-recordings:prune
php artisan camera-recordings:build-review-assets --missing
php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256
composer recordings:worker:install
composer relay:install
php artisan config:clear
php artisan view:clear
php artisan relay:sync
php artisan relay:start
php artisan relay:status
```

Run `php artisan schedule:run` from cron every minute so the built-in preview refresh and recording tasks continue automatically. Recording jobs are queued, so production also needs a queue worker process for the `recordings` queue. If you want Laravel's minute scheduler to act as a safety net, enable `CAMERA_RECORDING_ENSURE_WORKER=true` and point `CAMERA_RECORDING_WORKER_SYSTEMD_SERVICE` at the installed unit so `camera-recordings:ensure-worker` can start it when the worker is absent.

For repeatable setup on Linux hosts with user systemd available, run `php artisan camera-recordings:install-worker-service` once during deployment, or use `composer recordings:worker:install`. That removes the hand-edited unit file step, but it still relies on systemd for real background-process persistence.

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
