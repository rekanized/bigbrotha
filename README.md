# BigBrotha

BigBrotha is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, scheduler-driven per-camera recording, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. Operator access is expected to be authenticated through Google OAuth in Laravel, and Live Wall playback now uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Stack

- Laravel 13 on PHP 8.3.
- Blade plus Livewire 4 for the operator UI.
- Laravel Socialite for Google sign-in.
- Standard CSS under `public/css`.
- bundled or image-baked ffmpeg and ffprobe for RTSP diagnostics and preview generation.
- ffmpeg stream-copy recording with persistent continuous segmenting and a rolling short-segment motion buffer that stitches dynamic motion events with pre-roll and resettable post-trigger time.
- MediaMTX for shared WebRTC fan-out from RTSP camera sources, bundled into the Docker app image.
- Admin-managed Google sign-in allowlist with automatic first-user bootstrap.
- Scheduled preview refreshes and recording retention cleanup can run with Laravel's scheduler so saved thumbnails and recording segments stay current without operator intervention.

## Important Constraints

- This is a Composer-only project.
- Do not add Node.js tooling, Vite, Tailwind, Bootstrap, Sass, Less, or any build pipeline unless explicitly requested.
- HTTP access restriction is driven by `WEBSITE_ALLOWED_IPS`; an empty value disables the allow list.
- If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers.
- That HTTP restriction does not replace the need for normal HTTP, ONVIF, and RTSP reachability from this host to the camera network.

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

## Live Wall Flow

1. An operator signs in through Google OAuth and receives a normal Laravel session.
2. `/wall-tiles` is used to create named walls, assign camera tiles, and choose tile orientation or span.
3. `/live-wall` resolves the selected or default active wall and only renders cameras assigned to enabled tiles on that wall.
4. `/live-wall/{camera}/player` still renders a Laravel-owned player shell instead of exposing the stock public MediaMTX iframe page.
5. The browser calls `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token plus the WHEP URL for the selected camera path.
6. `public/js/live-wall-player.js` loads the official per-path MediaMTX `reader.js` and passes the signed token as a bearer token.
7. MediaMTX calls `/relay/auth/mediamtx` before allowing a WebRTC read.
8. If the path is idle, MediaMTX starts ffmpeg through `runOnDemand`, ffmpeg pulls the selected camera RTSP URI, copies H.264 video when the source is already browser-safe or otherwise transcodes to browser-safe H.264, normalizes audio timestamps while transcoding audio to Opus, and republishes locally to `rtsp://publisher:...@app:8554/$MTX_PATH`.
9. The auth callback accepts that internal RTSP publish only when it matches the configured internal publisher credentials and comes from the Docker network.

## Relay Settings

Production deployments should set these values explicitly:

- `APP_URL`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`

MediaMTX derives its public WebRTC URL from `APP_URL` as `APP_URL + /__webrtc`, derives relay secrets from `APP_KEY`, and uses Docker internal service names for app-to-app traffic. Most relay runtime knobs now live in `config/mediamtx.php`, not in `.env`.

After changing relay or auth-related environment values, run:

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan relay:sync
```

## Docker Deployment

BigBrotha now targets a single supported runtime: the Docker Compose stack in `docker-compose.yml`.

Quick start:

```bash
./docker/compose-up.sh
```

Review these values before first startup:

- `.env.docker` copied from `.env.docker.example`
- `./.docker-state/app.key` preserved after the first successful boot
- `APP_URL`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `DB_*` if you are not using the bundled PostgreSQL defaults
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of `worker` replicas

Container notes:

- the app image includes the repository `bin/ffmpeg` and `bin/ffprobe` binaries plus the bundled MediaMTX binary.
- the `app` container waits for PostgreSQL, ensures `APP_KEY`, persists it at `./.docker-state/app.key`, applies pending Laravel migrations, syncs relay config, and then serves `php-fpm`.
- `./docker/compose-up.sh` reads `.env.docker` and scales the `worker` service to match `CAMERA_RECORDING_WORKER_PROCESSES`.
- the `worker` and `scheduler` containers wait for the app bootstrap marker and rely on Docker healthchecks and restart policies instead of cron or systemd.
- internal service traffic uses Docker DNS names: `database`, `app`, and `web`.
- the bundled PostgreSQL service stays internal to the Compose network by default.
- the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- app services can also load `DB_PASSWORD_FILE`, `GOOGLE_CLIENT_ID_FILE`, and `GOOGLE_CLIENT_SECRET_FILE` when you want to move those values out of `.env.docker`.

Verification:

```bash
docker compose ps
docker compose logs --tail=100 app web worker scheduler
docker compose exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `web`, `worker`, and `scheduler` running.
- `app`, `web`, `worker`, and `scheduler` reporting healthy after startup settles.
- `php artisan relay:status` reporting MediaMTX installed, running, and API reachable.

For the full first-time bootstrap flow, see [docs/startup-from-scratch.md](docs/startup-from-scratch.md).

## Project Context

- [AGENTS.md](AGENTS.md)
- [docs/video-platform-architecture.md](docs/video-platform-architecture.md)
- [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md)
- [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md)
- [docs/startup-from-scratch.md](docs/startup-from-scratch.md)

## Useful Commands

```bash
docker compose exec app php artisan test
docker compose exec app php artisan camera-fleet:refresh-previews
docker compose exec app php artisan camera-recordings:tick
docker compose exec app php artisan camera-recordings:prune
docker compose exec app php artisan camera-recordings:prune-audit
docker compose exec app php artisan camera-recordings:orphans
docker compose exec app php artisan camera-recordings:orphans --purge
docker compose exec app php artisan camera-recordings:build-review-assets --missing
docker compose exec app php artisan camera-recordings:build-review-assets --missing --limit=10
docker compose exec app php artisan camera-recordings:queue-review-assets --camera_id=12
docker compose exec app php artisan camera-recordings:queue-review-assets --date_from=2026-04-01 --date_to=2026-04-03
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan relay:sync
docker compose exec app php artisan relay:start
docker compose exec app php artisan relay:status
```

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
