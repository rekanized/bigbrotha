# BigBrotha

BigBrotha is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports first-launch onboarding, concurrent manual local accounts and Google OAuth, camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, scheduler-driven per-camera recording, synchronized timeline review, admin audit logging, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. The legacy MJPEG and copy endpoints reuse an active canonical MediaMTX source when available and fall back to a direct camera connection when it is not. Operator access is authenticated through the application's enabled sign-in methods, and Live Wall playback uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Quick Start

BigBrotha's fastest supported startup path is Docker Compose with the published Docker Hub images referenced by [docker-compose.yml](docker-compose.yml). You do not need to build images locally for a normal deployment.

1. Copy the Docker environment template and set the public URL.

```bash
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

Set `APP_URL` to the exact public origin. Leave `DB_PASSWORD` empty if you use `./docker/compose.sh`; the wrapper generates and persists a strong password on its first invocation. Raw Compose users must set `DB_PASSWORD` themselves.

Minimum values to review in `.env.docker` before first startup:

- `COMPOSE_PROJECT_NAME`
- `APP_URL`
- `WEB_PORT`
- `MEDIAMTX_ICE_PORT`
- `CAMERA_RECORDING_WORKER_PROCESSES`

2. Start from the published Docker Hub images.

Preferred wrapper:

```bash
./docker/compose.sh pull
./docker/compose.sh up -d --remove-orphans
```

Raw Docker Compose equivalent:

```bash
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d --remove-orphans
```

3. Verify the stack and relay health.

```bash
./docker/compose.sh ps
./docker/compose.sh logs --tail=100 app background relay
./docker/compose.sh exec app php artisan relay:status
```

Expected result:

- `database`, `app`, `background`, and `relay` are running.
- all four services become healthy after startup settles.
- `php artisan relay:status` reports MediaMTX installed, running, and API reachable.

4. Open `/setup` on the published application URL if this is a brand-new deployment.

- Choose whether local sign-in, Google OAuth, or both should be active.
- Create the initial local administrator if local sign-in is enabled.
- Enter the Google client ID, client secret, and redirect URI in the setup wizard and run the built-in validation flow before enabling Google sign-in.

If this host requires Docker through `sudo`, run `sudo ./docker/compose.sh up -d --remove-orphans`.

## Docker Configuration

- For local development, run `./docker/compose-dev.sh up -d --build` once. The `app` and `background` services bind mount this checkout at `/app`, so PHP, Blade, CSS, and JavaScript edits appear without rebuilding. Later starts use `./docker/compose-dev.sh up -d`; use `./docker/compose-dev.sh ps` and `./docker/compose-dev.sh logs` for this stack.
- Development keeps Composer packages in the `dev-vendor` Docker volume and installs from `composer.lock` when the app starts. The `dev-cache` volume keeps Laravel cache files off the host checkout. Development clears route, view, and event caches, makes PHP OPcache revalidate changed files, and disables Nginx file caching.
- The default `./docker/compose.sh` remains the production image deployment. `./docker/compose-up.sh` builds that immutable production image from the checkout. The production image contains the Laravel source and production Composer packages; it does not mount source code or contain `.env` or `.docker-state`.
- Always use `./docker/compose.sh` or `docker compose --env-file .env.docker ...`.
- Plain `docker compose up -d` without `--env-file .env.docker` is unsupported and fails closed because production `APP_URL` and `DB_PASSWORD` are required.
- `APP_URL` must match the public origin exactly.
- If Google sign-in is enabled later in `/setup` or the admin settings page, use `${APP_URL}/auth/google/callback` as the normal callback target unless you intentionally publish a different callback URL.
- `WEB_PORT` and `MEDIAMTX_ICE_PORT` must be free on the host.
- For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`.
- The Docker host and the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Keep `./.docker-state/app.key` with the deployment; if that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The default published image is `rekanized/bigbrotha-app:latest` unless overridden in `.env.docker`.
- `CAMERA_RECORDING_WORKER_PROCESSES` controls the queue-worker process count supervised inside the `background` container.
- PostgreSQL 18 data is mounted at `/var/lib/postgresql`, the parent of its version-specific `PGDATA`; do not change this back to the pre-18 `/var/lib/postgresql/data` target.

## Stack

- Laravel 13 on PHP 8.5.
- Blade plus Livewire 4 for the operator UI.
- Laravel Socialite for Google sign-in.
- Standard CSS under `public/css`.
- image-baked ffmpeg and ffprobe for RTSP diagnostics and preview generation.
- ffmpeg stream-copy recording with persistent continuous segmenting and a rolling short-segment motion buffer that stitches dynamic motion events with pre-roll and resettable post-trigger time.
- MediaMTX for shared WebRTC fan-out from RTSP camera sources, bundled into the Docker app image and run as the dedicated `relay` service in Docker Compose.
- Admin-managed Google sign-in allowlist with automatic first-user bootstrap.
- Scheduled preview refreshes and recording retention cleanup can run with Laravel's scheduler so saved thumbnails and recording segments stay current without operator intervention.

## Important Constraints

- This is a Composer-only project.
- Do not add Node.js tooling, Vite, Tailwind, Bootstrap, Sass, Less, or any build pipeline unless explicitly requested.
- HTTP access restriction is driven by `WEBSITE_ALLOWED_IPS`; an empty value disables the allow list.
- If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers.
- That HTTP restriction does not replace the need for normal HTTP, ONVIF, and RTSP reachability from this host to the camera network.

## Route Highlights

- `/setup` runs the first-launch onboarding wizard.
- `/login`, `/auth/google/redirect`, `/auth/google/callback`, and `/logout` handle operator sign-in and logout.
- `/` is an authenticated redirect to `/camera-fleet`.
- `/camera-fleet` is the main camera inventory, provisioning, diagnostics, preview, and recording-policy surface.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` serves private preview images.
- `/camera-fleet/{camera}/motion-editor-session` bootstraps the authenticated motion-mask editor session.
- `/recordings` is the saved-recording browser.
- `/recordings/timeline` is the synchronized multi-camera review workflow.
- `/recordings/timeline/cameras/{camera}/segments` and `/recordings/timeline/cameras/{camera}/stage` provide authenticated timeline rail and stage data.
- `/recordings/{recording}` is the dedicated review screen.
- `/recordings/{recording}/preview-thumbnail`, `/preview-sprite`, `/review-stream`, `/stream`, and `/download` provide private review and playback endpoints.
- `/admin/users`, `/admin/settings`, and `/admin/audit-log` are the admin-only operator management surfaces.
- `/wall-tiles` is the named wall and tile layout builder.
- `/live-wall` is the live wall entry.
- `/live-wall/{camera}/player`, `/session`, `/stream`, and `/relay` provide secure live playback endpoints.
- `/relay/auth/mediamtx` is the MediaMTX HTTP auth callback.
- `/__webrtc/{camera-path}/...` is the proxied MediaMTX WHEP and player path when deployed behind Nginx.

## Live Wall Flow

1. An operator signs in through one of the enabled authentication methods and receives a normal Laravel session.
2. `/wall-tiles` is used to create named walls, assign camera tiles, and choose tile orientation or span.
3. `/live-wall` resolves the selected or default active wall and only renders cameras assigned to enabled tiles on that wall.
4. `/live-wall/{camera}/player` still renders a Laravel-owned player shell instead of exposing the stock public MediaMTX iframe page.
5. The browser calls `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token plus the WHEP URL for the selected camera path.
6. `public/js/live-wall-player.js` loads the official per-path MediaMTX `reader.js` and passes the signed token as a bearer token.
7. MediaMTX calls `/relay/auth/mediamtx` before allowing a WebRTC read.
8. If the path is idle, MediaMTX starts ffmpeg through `runOnDemand`, ffmpeg pulls the selected camera RTSP URI, copies H.264 video when the source is already browser-safe or otherwise transcodes to browser-safe H.264, normalizes audio timestamps while transcoding audio to Opus, and republishes locally to `rtsp://publisher:...@relay:8554/$MTX_PATH`.
9. The auth callback accepts that internal RTSP publish only when it matches the configured internal publisher credentials and comes from the Docker network.

## Relay Settings

Production deployments should set `APP_URL` explicitly before running setup.

Google OAuth credentials are now entered through `/setup` or the admin authentication settings panel and saved in the database. MediaMTX derives its public WebRTC URL from `APP_URL` as `APP_URL + /__webrtc`, derives relay secrets from `APP_KEY`, and uses Docker internal service names for app-to-app traffic. Most relay runtime knobs now live in `config/mediamtx.php`, not in `.env`.

After changing relay or auth-related environment values, run:

```bash
./docker/compose.sh exec app php artisan config:clear
./docker/compose.sh exec app php artisan view:clear
./docker/compose.sh exec app php artisan relay:sync
```

## Docker Deployment Details

BigBrotha now targets a single supported runtime: the Docker Compose stack in `docker-compose.yml`.

Use `./docker/compose.sh` for routine Compose commands so the selected `.env.docker` file and `COMPOSE_PROJECT_NAME` stay aligned across `pull`, `up`, `ps`, `logs`, and `exec`.

If you want to start the application with raw Docker Compose instead of the wrapper, pass `--env-file .env.docker` on every command:

Copy the template, set `APP_URL`, and put the output of `openssl rand -base64 32` into `DB_PASSWORD` before running these raw commands.

```bash
cp .env.docker.example .env.docker
openssl rand -base64 32
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d --remove-orphans
```

Why `--env-file .env.docker` matters:

- `docker-compose.yml` injects `.env.docker` into the containers with `env_file`, but Compose does not use that file for top-level interpolation unless you pass `--env-file` or rename it to `.env`.
- If you run plain `docker compose up -d` without `--env-file .env.docker`, Compose does not receive the required production URL and database credential and exits instead of silently starting with insecure defaults.
- `MEDIAMTX_ICE_PORT` controls the published TCP/UDP port and both MediaMTX ICE listeners; separate listener variables are no longer required.

Equivalent wrapper commands:

```bash
./docker/compose.sh pull
./docker/compose.sh up -d --remove-orphans
```

The default [docker-compose.yml](docker-compose.yml) consumes the published Docker Hub image:

- `rekanized/bigbrotha-app:latest`

Quick start from a source checkout that should build images locally:

```bash
./docker/compose-up.sh
```

The simplified Compose file relies on the matching application image for role launchers, health checks, and fixed production defaults. Build from the checkout with `compose-up.sh` or publish and pin the matching image tag; do not pair this Compose revision with an older app image.

Copy `.env.docker.example` to `.env.docker` and review these values before first startup:

- `COMPOSE_PROJECT_NAME` set to a unique stack name when this host runs more than one BigBrotha deployment
- `APP_URL`
- `WEB_BIND_IP` if the web listener should not bind all host interfaces
- `WEB_PORT`
- `MEDIAMTX_ICE_BIND_IP` if the WebRTC ICE listener should not bind all host interfaces
- `MEDIAMTX_ICE_PORT`
- `DB_*` if you are not using the bundled PostgreSQL defaults
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of queue-worker processes
- `BIGBROTHA_APP_IMAGE` if you want to pin a non-default image tag
- `QUEUE_FAILED_TERMINAL_RETENTION_HOURS` for how long exhausted queue failures remain available for diagnostics before automatic pruning (24 hours by default)
- `./.docker-state/app.key` preserved after the first successful boot

Google OAuth is database-only. Configure it from `/setup` on the first launch or later from the admin authentication settings panel; legacy `GOOGLE_*` environment entries are ignored and can be removed.

Minimum usable deployment rules:

- `APP_URL` must match the public origin exactly.
- `WEB_PORT` and `MEDIAMTX_ICE_PORT` must be free on the host.
- For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`.
- The Docker host and the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Keep `./.docker-state/app.key` with the deployment; if that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.

Container notes:

- the published app image includes Nginx, PHP-FPM, Supervisor, ffmpeg, ffprobe, and MediaMTX.
- the image owns fixed production defaults and dispatches one built-in health check to the app, background, or relay probe based on the running role, keeping Compose focused on topology and deployment-specific values.
- the `app` container waits for PostgreSQL, ensures `APP_KEY`, persists it at `./.docker-state/app.key`, applies pending Laravel migrations, warms safe Laravel runtime caches, syncs relay config, and then supervises unprivileged Nginx and PHP-FPM processes. Configuration is intentionally not cached because database-backed authentication and storage secrets remain dynamic.
- the `background` container supervises one Laravel scheduler plus exactly `CAMERA_RECORDING_WORKER_PROCESSES` queue workers; its health check validates both roles, the configured worker count, writable nested recorder runtime directories, and every enabled persistent camera recorder process.
- app and background startup repair ownership and directory modes throughout the persistent continuous and motion recorder runtime trees before unprivileged recording processes start, including runtime paths left behind by older root-run maintenance commands.
- the `relay` container runs MediaMTX from the same app image and reads the generated config from `storage/app/private/mediamtx/mediamtx.yml`.
- Nginx in the `app` container serves the operator UI and proxies `/__webrtc/` traffic to `relay`.
- `./docker/compose.sh` wraps the default [docker-compose.yml](docker-compose.yml), the selected `.env.docker`, and the configured `COMPOSE_PROJECT_NAME` so multiple stacks can coexist on one host.
- `./docker/compose-up.sh` uses [docker-compose.build.yml](docker-compose.build.yml); image and source deployments use the same four-service shape.
- the `background` container waits for the app bootstrap marker and uses Supervisor plus Docker health checks instead of host cron or systemd.
- internal service traffic uses Docker DNS names: `database`, `app`, `background`, and `relay`.
- the bundled PostgreSQL service stays internal to the Compose network by default.
- MediaMTX signaling and API traffic stay internal to the Compose network; only the web port and WebRTC ICE port need to be unique on the host.
- the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Compose log rotation is bounded, containers use Docker's init process where appropriate, and the relay runs as `www-data` with all Linux capabilities dropped.
- PostgreSQL 18 uses the named `db-data` volume at `/var/lib/postgresql`, which preserves its version-specific data directory.

When upgrading a six-service deployment, use `up -d --remove-orphans`. This removes the former `web`, `worker`, and `scheduler` containers so the old web container cannot retain `WEB_PORT`; named database and application-storage volumes are not removed.

Deployments created with the older `/var/lib/postgresql/data` mount must migrate before recreating the database container. The guarded migration creates a logical backup, copies the stopped PostgreSQL 18 cluster into the named volume, retains the old anonymous volume, and restarts the stack:

```bash
sudo BIGBROTHA_DEPLOY_DIR=/path/to/deployment ./docker/migrate-postgres-18-volume.sh
```

Rotate an existing deployment database password with:

```bash
sudo BIGBROTHA_DEPLOY_DIR=/path/to/deployment ./docker/rotate-db-password.sh
```

Verification:

```bash
./docker/compose.sh ps
./docker/compose.sh logs --tail=100 app background relay
./docker/compose.sh exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `background`, and `relay` running and healthy after startup settles.
- `php artisan relay:status` reporting MediaMTX installed, running, and API reachable.

If you started the stack with raw Compose, keep using the same form for follow-up commands:

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 app background relay
docker compose --env-file .env.docker exec app php artisan relay:status
```

For the full first-time bootstrap flow, see [docs/startup-from-scratch.md](docs/startup-from-scratch.md).

## Project Context

- [AGENTS.md](AGENTS.md)
- [docs/video-platform-architecture.md](docs/video-platform-architecture.md)
- [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md)
- [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md)
- [docs/mediamtx-review-2026-09-29.md](docs/mediamtx-review-2026-09-29.md)
- [docs/startup-from-scratch.md](docs/startup-from-scratch.md)

## Publishing Images

`publish.sh` builds a dedicated test image, runs the full application suite, validates the unified production image, publishes an immutable timestamp-and-commit tag, updates `latest`, and verifies the remote manifest.

```bash
./publish.sh
```

Docker daemon access and an existing Docker Hub login are required. Set `IMAGE_TAG` to choose an explicit immutable tag or `PUBLISH_LATEST=false` when `latest` must not move.

To run the same build, test, and production-image checks locally without pushing, use `PUSH_IMAGES=false ./publish.sh`. This mode does not require Docker Hub login.

On another server, copy `docker-compose.yml`, `.env.docker.example`, and `docker/compose.sh`; create `.env.docker` with that server's URL, ports, and `BIGBROTHA_APP_IMAGE=<pushed tag>`, then run `./docker/compose.sh pull` and `./docker/compose.sh up -d`. Keep that server's `.docker-state/app.key` and named database and storage volumes when upgrading.

## Useful Commands

```bash
./docker/compose-dev.sh exec app php artisan test
./docker/compose.sh exec app php artisan camera-fleet:refresh-previews
./docker/compose.sh exec app php artisan camera-recordings:tick
./docker/compose.sh exec app php artisan camera-recordings:prune
./docker/compose.sh exec app php artisan camera-recordings:prune-audit
./docker/compose.sh exec app php artisan camera-recordings:orphans
./docker/compose.sh exec app php artisan camera-recordings:orphans --purge
./docker/compose.sh exec app php artisan camera-recordings:build-review-assets --missing
./docker/compose.sh exec app php artisan camera-recordings:build-review-assets --missing --limit=10
./docker/compose.sh exec app php artisan camera-recordings:queue-review-assets --camera_id=12
./docker/compose.sh exec app php artisan camera-recordings:queue-review-assets --date_from=2026-04-01 --date_to=2026-04-03
./docker/compose.sh exec app php artisan camera-recordings:reconcile-review-asset-queue --dry-run
./docker/compose.sh exec app php artisan config:clear
./docker/compose.sh exec app php artisan view:clear
./docker/compose.sh exec app php artisan relay:sync
./docker/compose.sh exec app php artisan relay:start
./docker/compose.sh exec app php artisan relay:stop
./docker/compose.sh exec app php artisan relay:status
```

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
