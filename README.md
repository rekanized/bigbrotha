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
- MediaMTX for shared WebRTC fan-out from RTSP camera sources, either downloaded on a host install or baked into the Docker image.
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
Docker Compose configuration:
```

x-laravel-environment: &laravel-environment
	APP_DEBUG: "false"
	APP_ENV: production
	APP_URL: ${APP_URL:-http://localhost:8080}
	CAMERA_RECORDING_ENSURE_WORKER: "false"
	CACHE_STORE: database
	DB_CONNECTION: pgsql
	DB_DATABASE: ${DB_DATABASE:-bigbrotha}
	DB_HOST: database
	DB_PASSWORD: ${DB_PASSWORD:-bigbrotha}
	DB_PORT: 5432
	DB_USERNAME: ${DB_USERNAME:-bigbrotha}
	GOOGLE_CLIENT_ID: ${GOOGLE_CLIENT_ID:-}
	GOOGLE_CLIENT_SECRET: ${GOOGLE_CLIENT_SECRET:-}
	GOOGLE_REDIRECT_URI: ${GOOGLE_REDIRECT_URI:-http://localhost:8080/auth/google/callback}
	LOG_CHANNEL: stderr
	MEDIAMTX_AUTH_CALLBACK_URL: http://web/relay/auth/mediamtx
	MEDIAMTX_BINARY_PATH: /usr/local/bin/mediamtx
	MEDIAMTX_INSTALL_MODE: bundled
	MEDIAMTX_LOG_PATH: /app/storage/logs/mediamtx.log
	MEDIAMTX_RTSP_INTERNAL_BASE_URL: rtsp://app:8554
	MEDIAMTX_RTSP_PUBLISH_BASE_URL: rtsp://127.0.0.1:8554
	QUEUE_CONNECTION: database
	SESSION_DRIVER: database
	TRUSTED_PROXIES: "*"

x-laravel-service: &laravel-service
	depends_on:
		database:
			condition: service_healthy
	env_file:
		- .env
	environment: *laravel-environment
	volumes:
		- ./.env:/app/.env
		- app-storage:/app/storage

In normal operation this is enough. A `php-fpm` restart is only needed if the host is serving stale PHP bytecode through OPcache.

		<<: *laravel-service
## Production Setup

Use this short checklist during deployment:

		environment:
			<<: *laravel-environment
			APP_AUTO_MIGRATE_IF_NO_USERS: "true"
			APP_AUTO_START_RELAY: "true"
			APP_CONTAINER_ROLE: app
1. Install PHP 8.3, Composer, a database, cron, and a user-level or system-level process manager.
2. Run `composer install --no-dev --optimize-autoloader`.
3. Commit the Linux-compatible statically compiled `ffmpeg` and `ffprobe` binaries in `bin/`. By default Laravel resolves those binaries and the FFmpeg temp directory from the project root dynamically through `config/ffmpeg.php`, so `.env` only needs `FFMPEG_BINARIES`, `FFPROBE_BINARIES`, or `FFMPEG_TEMPORARY_DIRECTORY` if a deployment wants an explicit override. Then create `.env`, set database, Google auth, `APP_URL`, and recording worker values, and run `php artisan key:generate` if the app key is still empty. MediaMTX will derive its same-host relay defaults from `APP_URL` and `APP_KEY`; only add MediaMTX env overrides if the relay is published on a different origin, prefix, or credential set.
4. Run `php artisan migrate --force`.
5. Run `composer relay:install` and `php artisan relay:sync`.
6. Run `composer recordings:worker:install` so Laravel writes and enables the recordings worker systemd unit.
7. Add cron for `* * * * * cd /var/www/bigbrotha && php artisan schedule:run >> /dev/null 2>&1`.
8. Run `php artisan optimize:clear`, `php artisan relay:start`, and `php artisan camera-recordings:ensure-worker`.

If the host uses a specific PHP binary such as `/usr/bin/php8.3`, set `CAMERA_RECORDING_WORKER_PHP_BINARY` in `.env` before running `composer recordings:worker:install` so the generated worker unit uses the correct interpreter.
		depends_on:
			app:
				condition: service_healthy
		healthcheck:
			test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1/robots.txt || exit 1"]
			interval: 30s
			timeout: 5s
			retries: 3
			start_period: 10s

Composer now reapplies execute permissions to `bin/ffmpeg` and `bin/ffprobe` on `install` and `update`. If you replace those files manually, rerun `composer ffmpeg:binaries:chmod`.

If you build this app into Docker, copy the repository `bin/` directory into the image so the container uses the same pinned binaries Laravel is configured to resolve.

## Docker Deployment
The repository now includes a first-party Docker stack in [docker-compose.yml](docker-compose.yml), [Dockerfile](Dockerfile), and [docker/nginx/default.conf](docker/nginx/default.conf).

What the containerized stack changes:

- the app image bakes in the repository `bin/ffmpeg` and `bin/ffprobe` binaries.
- the app image also bakes in a pinned MediaMTX binary and points Laravel at it with `MEDIAMTX_INSTALL_MODE=bundled`.
- the app image also includes `smbclient`, `tar`, and the PHP PostgreSQL extension, so the container does not rely on host-installed media or storage helpers.
- the `worker` service replaces the host systemd recordings worker.
- the `scheduler` service replaces the host cron entry for `php artisan schedule:run`.
- the `scheduler` container also runs one immediate `camera-recordings:tick` during startup so motion and continuous recorder processes are restored before the next minute boundary.
- the `web` service proxies `/__webrtc/` to MediaMTX running inside the `app` container and proxies PHP requests to `php-fpm`.
- the only required host dependency for this deployment path is Docker with Compose support.

Docker Compose configuration:

```yaml
x-laravel-environment: &laravel-environment
	APP_DEBUG: "false"
	APP_ENV: production
	APP_URL: ${APP_URL:-http://localhost:8080}
	CAMERA_RECORDING_ENSURE_WORKER: "false"
	CACHE_STORE: database
	DB_CONNECTION: pgsql
	DB_DATABASE: ${DB_DATABASE:-bigbrotha}
	DB_HOST: database
	DB_PASSWORD: ${DB_PASSWORD:-bigbrotha}
	DB_PORT: 5432
	DB_USERNAME: ${DB_USERNAME:-bigbrotha}
	GOOGLE_CLIENT_ID: ${GOOGLE_CLIENT_ID:-}
	GOOGLE_CLIENT_SECRET: ${GOOGLE_CLIENT_SECRET:-}
	GOOGLE_REDIRECT_URI: ${GOOGLE_REDIRECT_URI:-http://localhost:8080/auth/google/callback}
	LOG_CHANNEL: stderr
	MEDIAMTX_AUTH_CALLBACK_URL: http://web/relay/auth/mediamtx
	MEDIAMTX_BINARY_PATH: /usr/local/bin/mediamtx
	MEDIAMTX_INSTALL_MODE: bundled
	MEDIAMTX_LOG_PATH: /app/storage/logs/mediamtx.log
	MEDIAMTX_RTSP_INTERNAL_BASE_URL: rtsp://app:8554
	MEDIAMTX_RTSP_PUBLISH_BASE_URL: rtsp://127.0.0.1:8554
	QUEUE_CONNECTION: database
	SESSION_DRIVER: database
	TRUSTED_PROXIES: "*"

x-laravel-service: &laravel-service
	depends_on:
		database:
			condition: service_healthy
	env_file:
		- .env
	environment: *laravel-environment
	volumes:
		- ./.env:/app/.env
		- app-storage:/app/storage

services:
	app:
		<<: *laravel-service
		build:
			context: .
			dockerfile: Dockerfile
		environment:
			<<: *laravel-environment
			APP_AUTO_MIGRATE_IF_NO_USERS: "true"
			APP_AUTO_START_RELAY: "true"
			APP_CONTAINER_ROLE: app
		image: ${BIGBROTHA_APP_IMAGE:-rekanized/bigbrotha-app:latest}
		restart: unless-stopped
		ports:
			- "${MEDIAMTX_WEBRTC_TCP_PORT:-8189}:8189/tcp"
			- "${MEDIAMTX_WEBRTC_UDP_PORT:-8189}:8189/udp"

	web:
		build:
			context: .
			dockerfile: docker/nginx/Dockerfile
		image: ${BIGBROTHA_WEB_IMAGE:-rekanized/bigbrotha-web:latest}
		depends_on:
			app:
				condition: service_healthy
		healthcheck:
			test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1/robots.txt || exit 1"]
			interval: 30s
			timeout: 5s
			retries: 3
			start_period: 10s
		restart: unless-stopped
		ports:
			- "${APP_HTTP_PORT:-8080}:80"

	worker:
		<<: *laravel-service
		depends_on:
			app:
				condition: service_healthy
			database:
				condition: service_healthy
		environment:
			<<: *laravel-environment
			APP_CONTAINER_ROLE: worker
			APP_WAIT_FOR_APP_BOOTSTRAP: "true"
		image: ${BIGBROTHA_APP_IMAGE:-rekanized/bigbrotha-app:latest}
		command: ["run-worker"]
		restart: unless-stopped

	scheduler:
		<<: *laravel-service
		depends_on:
			app:
				condition: service_healthy
			database:
				condition: service_healthy
		environment:
			<<: *laravel-environment
			APP_CONTAINER_ROLE: scheduler
			APP_WAIT_FOR_APP_BOOTSTRAP: "true"
		image: ${BIGBROTHA_APP_IMAGE:-rekanized/bigbrotha-app:latest}
		command: ["run-scheduler"]
		restart: unless-stopped

	database:
		image: postgres:18-alpine
		restart: unless-stopped
		environment:
			POSTGRES_DB: ${DB_DATABASE:-bigbrotha}
			POSTGRES_PASSWORD: ${DB_PASSWORD:-bigbrotha}
			POSTGRES_USER: ${DB_USERNAME:-bigbrotha}
		ports:
			- "${DB_HOST_BIND:-127.0.0.1}:${DB_HOST_PORT:-55432}:5432"
		healthcheck:
			test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-bigbrotha} -d ${DB_DATABASE:-bigbrotha}"]
			interval: 10s
			timeout: 5s
			retries: 12
		volumes:
			- db-data:/var/lib/postgresql/data

volumes:
	app-storage:
	db-data:
```

Quick start:

```bash
docker compose pull
docker compose up -d
```

Docker Hub workflow:

```bash
docker compose pull
docker compose up -d --no-build
```

Camera network reachability for Docker:

```bash
docker compose up -d
```

Before starting the stack, confirm the `app` container can reach the camera LAN or routed camera subnet for direct ONVIF and RTSP traffic. The current compose file assumes normal routed reachability from the host instead of a dedicated WS-Discovery network attachment.

Review these values in `docker-compose.yml` before first startup:

- `APP_URL` with the public host or local published port, for example `http://localhost:8080`
- `DB_PASSWORD` and any other `DB_*` values you want to override from the compose defaults
- `DB_HOST_PORT` if you want the bundled PostgreSQL service reachable from the Docker host on a custom port
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`

Container notes:

- The Docker images use `/app` as the internal application root. That path exists inside the container image and does not depend on where the host stores the compose file or image.
- The compose file now ships with the published installation images as the defaults: `rekanized/bigbrotha-app:latest` and `rekanized/bigbrotha-web:latest`.
- `app`, `worker`, and `scheduler` all resolve from `BIGBROTHA_APP_IMAGE`, while `web` resolves from `BIGBROTHA_WEB_IMAGE`, so Docker rollouts keep the queue worker and scheduler on the same release as the app.
- If you want a different registry or tag, override `BIGBROTHA_APP_IMAGE` and `BIGBROTHA_WEB_IMAGE` in a shell export or in an optional local `.env` file before running Compose.
- Compose forces production-safe container defaults for `APP_ENV`, `APP_DEBUG`, `TRUSTED_PROXIES`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE`, and logging to `stderr`, so the stack does not depend on local development values left in `.env`.
- Compose publishes the web UI on `APP_HTTP_PORT` and MediaMTX ICE on `MEDIAMTX_WEBRTC_TCP_PORT` and `MEDIAMTX_WEBRTC_UDP_PORT`.
- App containers always talk to the bundled PostgreSQL service on `database:5432`. Leave `DB_PORT` at `5432` for the bundled service.
- The bundled PostgreSQL service is also published to the Docker host on `${DB_HOST_BIND:-127.0.0.1}:${DB_HOST_PORT:-55432}` by default. Change `DB_HOST_PORT` if you need a different host-side port.
- Compose sets `CAMERA_RECORDING_ENSURE_WORKER=false` because the worker runs as its own container instead of being started through systemd.
- Compose includes the core installation settings directly in `docker-compose.yml`, and the container generates the Laravel `APP_KEY` automatically on first boot into shared Docker storage.
- Laravel now reads the generated key from that shared storage file, so Docker startup no longer requires a pre-created host `.env` file.
- Compose defaults the bundled database service to `postgres:18-alpine` and uses `DB_CONNECTION=pgsql` unless you override it.
- The `app` container bootstraps itself automatically: when the `users` table is missing or contains zero rows it runs `php artisan migrate --force` before serving `php-fpm`.
- After the first successful bootstrap, the app writes a database initialization marker into shared app storage. If a later startup sees an empty database while that marker already exists, startup now refuses to auto-migrate and exits loudly instead of silently reinitializing a fresh schema over what is likely a lost or swapped database volume.
- Compose forces `MEDIAMTX_AUTH_CALLBACK_URL=http://web/relay/auth/mediamtx` so MediaMTX running in the `app` container can reach Laravel through the internal Nginx service instead of trying to call the public host from inside the container network.
- Compose also allows internal RTSP relay readers from Docker bridge CIDRs through `MEDIAMTX_AUTH_READER_ALLOWED_IPS`, so the `scheduler` and `worker` containers can read `rtsp://app:8554/...` without tripping the loopback-only MediaMTX auth check.
- Compose forces `MEDIAMTX_RTSP_INTERNAL_BASE_URL=rtsp://app:8554` so relay-backed recording workflows can reach the relay from any Laravel container on the compose network.
- The MediaMTX config now separately prefers `MEDIAMTX_RTSP_LOCAL_INTERNAL_BASE_URL`, then `MEDIAMTX_RTSP_PUBLISH_BASE_URL`, for the relay's own nested source-path reads. In Docker that keeps `runOnDemand` loopback reads on `127.0.0.1:8554` instead of hairpinning back through the `app` service hostname.
- Compose sets `MEDIAMTX_WEBRTC_IPS_FROM_INTERFACES=false` so MediaMTX does not advertise Docker-only interface IPs as browser ICE candidates.
- The Laravel MediaMTX config now defaults `MEDIAMTX_WEBRTC_IPS_FROM_INTERFACES` to `false` on all installs. On hosts with Docker bridges, VPNs, or Tailscale-style interfaces, advertising every local interface tends to produce black screens and repeated WHEP reconnects even when the camera feed itself is healthy. Use `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS` to declare the browser-reachable LAN/public hostnames or IPs instead.
- Compose also sets `MEDIAMTX_RTSP_PUBLISH_BASE_URL=rtsp://127.0.0.1:8554` so the in-container MediaMTX `runOnDemand` publisher connects over loopback and satisfies the internal publisher auth checks.
- If UDP remains unreliable on your network, you can force WebRTC over TCP by setting `MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS=` and leaving `MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS=:8189`. Empty listener values are now preserved instead of being replaced with defaults.
- If `APP_KEY` is not provided, the container entrypoint generates one once, stores a shared copy under Docker storage, and writes it into the mounted project `.env` so `app`, `worker`, `scheduler`, and later `docker compose exec` commands all resolve the same key.
- If SMB-backed storage is enabled, the app image already includes `smbclient` so the container does not need that binary from the host.
- MediaMTX is bundled into the app image at `/usr/local/bin/mediamtx` and is started automatically during app bootstrap, while Laravel can still self-heal it later through `ensureRunning()` if needed. You do not need to run `php artisan relay:status` as part of normal startup.
- Compose sets `MEDIAMTX_LOG_PATH=/app/storage/logs/mediamtx.log`, so the detached MediaMTX process writes to a real file inside shared storage instead of inheriting a short-lived bootstrap shell stdout pipe.
- The app image now has a Docker `HEALTHCHECK` that waits for bootstrap completion, verifies `php-fpm` on port `9000`, and checks the local MediaMTX API when relay auto-start is enabled.
- The `worker` and `scheduler` services now also have dedicated Docker healthchecks. The worker healthcheck fails on a missing queue process, a stale worker heartbeat, or an aging recordings queue backlog, while the scheduler healthcheck fails on a stale scheduler heartbeat.
- The `web` service also has a lightweight healthcheck through Nginx, and Compose waits for the `app` service to become healthy before starting `web`, `worker`, and `scheduler`.
- Motion capture through the relay path continues to work in Docker because Compose injects `MEDIAMTX_RTSP_INTERNAL_BASE_URL=rtsp://app:8554`, but the default recording path still reads directly from cameras unless `CAMERA_MOTION_USE_RELAY_SOURCE=true` is explicitly enabled.
- Direct camera onboarding now uses a unicast ONVIF probe from Camera Fleet, so the Docker requirement is straightforward reachability from the `app` container to camera HTTP, ONVIF, and RTSP endpoints.

Production verification after startup:

```bash
docker compose ps
docker compose exec app php artisan relay:status
docker compose logs --tail=100 app web worker scheduler
```

`php artisan relay:status` is optional verification only. It is useful when you are diagnosing a relay problem or validating a fresh production deploy, but it is not required for normal startup.

What you should see:

- `database`, `app`, `web`, `worker`, and `scheduler` running.
- `app`, `web`, `worker`, and `scheduler` showing `healthy` in `docker compose ps` once startup settles.
- `php artisan relay:status` reporting MediaMTX installed, running, and API reachable.
- relay output visible in `docker compose logs app` because MediaMTX writes to stdout in the container.
- no repeated crash-loop output from `worker` or `scheduler`.

Automatic bootstrap behavior:

- `app` waits for PostgreSQL, ensures `APP_KEY`, runs `php artisan migrate --force` when the `users` table is missing or empty, starts MediaMTX automatically, then serves `php-fpm`.
- Once that first successful database bootstrap completes, later startups refuse to auto-initialize a now-empty database if the shared initialization marker already exists. That protects against silently bootstrapping a new database when the PostgreSQL volume was lost, changed, or mounted under a different Compose project name.
- `worker` and `scheduler` wait for the app bootstrap marker before they start queue or scheduler work, so they do not race the first-time migration step.

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

Run `php artisan schedule:run` from cron every minute on host installs so the built-in preview refresh, recording tasks, and bounded missing review-asset backfill continue automatically. Recording jobs are still queued, so production also needs a queue worker process for the `recordings` queue. The scheduler now also runs `camera-recordings:build-review-assets --missing --limit=...` as a safety net for preview MP4 and scrub-sprite generation; tune it with `CAMERA_REVIEW_ASSET_SCHEDULER_ENABLED` and `CAMERA_REVIEW_ASSET_SCHEDULER_LIMIT`. If you want Laravel's minute scheduler to act as a worker safety net too, enable `CAMERA_RECORDING_ENSURE_WORKER=true` and point `CAMERA_RECORDING_WORKER_SYSTEMD_SERVICE` at the installed unit so `camera-recordings:ensure-worker` can start it when the worker is absent.

Recordings worker sizing is now dynamic by default. `CAMERA_RECORDING_WORKER_PROCESSES` remains the minimum worker count, and the worker supervisor can scale above that floor up to `CAMERA_RECORDING_WORKER_MAX_PROCESSES` using both enabled recording-camera count and queued `recordings,default` job backlog. Tune the ramp with `CAMERA_RECORDING_WORKER_CAMERAS_PER_PROCESS` and `CAMERA_RECORDING_WORKER_JOBS_PER_PROCESS`.

For repeatable setup on Linux hosts with user systemd available, run `php artisan camera-recordings:install-worker-service` once during deployment, or use `composer recordings:worker:install`. That removes the hand-edited unit file step, but it still relies on systemd for real background-process persistence. Docker deployments should use the dedicated `worker` and `scheduler` services instead.

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
