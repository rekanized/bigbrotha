# BigBrotha

BigBrotha is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports first-launch onboarding, concurrent manual local accounts and Google OAuth, camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, scheduler-driven per-camera recording, synchronized timeline review, admin audit logging, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. Operator access is authenticated through the application's enabled sign-in methods, and Live Wall playback uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Quick Start

BigBrotha's fastest supported startup path is Docker Compose with the published Docker Hub images referenced by [docker-compose.yml](/home/administrator/dockers/bigbrotha/docker-compose.yml). You do not need to build images locally for a normal deployment.

1. Copy the Docker environment template and set the public URL, published ports, and worker count.

```bash
cp .env.docker.example .env.docker
chmod 600 .env.docker
openssl rand -base64 32
```

Put the generated random value in `DB_PASSWORD`; startup intentionally fails when `APP_URL` or `DB_PASSWORD` is empty.

Minimum values to review in `.env.docker` before first startup:

- `COMPOSE_PROJECT_NAME`
- `APP_URL`
- `WEB_BIND_IP`
- `WEB_PORT`
- `MEDIAMTX_ICE_BIND_IP`
- `MEDIAMTX_ICE_PORT`
- `MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS`, typically `:${MEDIAMTX_ICE_PORT}`
- `MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS`, typically `:${MEDIAMTX_ICE_PORT}`
- `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
- `CAMERA_RECORDING_WORKER_PROCESSES`

2. Start from the published Docker Hub images.

Preferred wrapper:

```bash
./docker/compose.sh pull
./docker/compose.sh up -d
```

Raw Docker Compose equivalent:

```bash
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d
```

3. Verify the stack and relay health.

```bash
./docker/compose.sh ps
./docker/compose.sh logs --tail=100 app relay web worker scheduler
./docker/compose.sh exec app php artisan relay:status
```

Expected result:

- `database`, `app`, `relay`, `web`, `worker`, and `scheduler` are running.
- `app`, `relay`, `web`, `worker`, and `scheduler` become healthy after startup settles.
- `php artisan relay:status` reports MediaMTX installed, running, and API reachable.

4. Open `/setup` on the published application URL if this is a brand-new deployment.

- Choose whether local sign-in, Google OAuth, or both should be active.
- Create the initial local administrator if local sign-in is enabled.
- Enter the Google client ID, client secret, and redirect URI in the setup wizard and run the built-in validation flow before enabling Google sign-in.

If this host requires Docker through `sudo`, run `sudo ./docker/compose.sh up -d`.

## Docker Configuration

- Always use `./docker/compose.sh` or `docker compose --env-file .env.docker ...`.
- Plain `docker compose up -d` without `--env-file .env.docker` is unsupported and fails closed because production `APP_URL` and `DB_PASSWORD` are required.
- `APP_URL` must match the public origin exactly.
- If Google sign-in is enabled later in `/setup` or the admin settings page, use `${APP_URL}/auth/google/callback` as the normal callback target unless you intentionally publish a different callback URL.
- `WEB_PORT` and `MEDIAMTX_ICE_PORT` must be free on the host.
- For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`.
- The Docker host and the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Keep `./.docker-state/app.key` with the deployment; if that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The default published images are `rekanized/bigbrotha-app:latest` and `rekanized/bigbrotha-web:latest` unless overridden in `.env.docker`.
- `CAMERA_RECORDING_WORKER_PROCESSES` is applied as the Compose service scale, so image-only and source-build deployments start the same requested worker count.
- PostgreSQL 18 data is mounted at `/var/lib/postgresql`, the parent of its version-specific `PGDATA`; do not change this back to the pre-18 `/var/lib/postgresql/data` target.

## Stack

- Laravel 13 on PHP 8.3.
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
- `/recordings/{recording}/preview-stream`, `/preview-thumbnail`, `/preview-sprite`, `/review-stream`, `/stream`, and `/download` provide private review and playback endpoints.
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
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan relay:sync
```

## Docker Deployment Details

BigBrotha now targets a single supported runtime: the Docker Compose stack in `docker-compose.yml`.

Use `./docker/compose.sh` for routine Compose commands so the selected `.env.docker` file and `COMPOSE_PROJECT_NAME` stay aligned across `pull`, `up`, `ps`, `logs`, and `exec`.

If you want to start the application with raw Docker Compose instead of the wrapper, pass `--env-file .env.docker` on every command:

```bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker pull
docker compose --env-file .env.docker up -d
```

Why `--env-file .env.docker` matters:

- `docker-compose.yml` injects `.env.docker` into the containers with `env_file`, but Compose does not use that file for top-level interpolation unless you pass `--env-file` or rename it to `.env`.
- If you run plain `docker compose up -d` without `--env-file .env.docker`, Compose does not receive the required production URL and database credential and exits instead of silently starting with insecure defaults.
- The `relay` service publishes the host ICE port using `MEDIAMTX_ICE_PORT`, so that value must stay aligned with `MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS` and `MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS` in `.env.docker`.

Equivalent wrapper commands:

```bash
./docker/compose.sh pull
./docker/compose.sh up -d
```

The default [docker-compose.yml](/home/administrator/dockers/bigbrotha/docker-compose.yml) consumes published Docker Hub images:

- `rekanized/bigbrotha-app:latest`
- `rekanized/bigbrotha-web:latest`

Quick start from a source checkout that should build images locally:

```bash
./docker/compose-up.sh
```

Copy `.env.docker.example` to `.env.docker` and review these values before first startup:

- `COMPOSE_PROJECT_NAME` set to a unique stack name when this host runs more than one BigBrotha deployment
- `APP_URL`
- `WEB_BIND_IP` if the web listener should not bind all host interfaces
- `WEB_PORT`
- `MEDIAMTX_ICE_BIND_IP` if the WebRTC ICE listener should not bind all host interfaces
- `MEDIAMTX_ICE_PORT`
- `MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS`, typically `:${MEDIAMTX_ICE_PORT}`
- `MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS`, typically `:${MEDIAMTX_ICE_PORT}`
- `DB_*` if you are not using the bundled PostgreSQL defaults
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of `worker` replicas
- `BIGBROTHA_APP_IMAGE` and `BIGBROTHA_WEB_IMAGE` if you want to pin a non-default image tag
- `./.docker-state/app.key` preserved after the first successful boot

Google OAuth no longer needs to be defined in `.env.docker`. Configure it from `/setup` on the first launch or later from the admin authentication settings panel, and the client ID, client secret, and redirect URI will be stored in the database.

The Docker Compose stack now forces the legacy `GOOGLE_*` container variables to empty strings so old `.env.docker` entries do not override the database-backed authentication settings.

Minimum usable deployment rules:

- `APP_URL` must match the public origin exactly.
- `WEB_PORT` and `MEDIAMTX_ICE_PORT` must be free on the host.
- For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`.
- The Docker host and the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Keep `./.docker-state/app.key` with the deployment; if that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.

Container notes:

- the published app image includes ffmpeg, ffprobe, and MediaMTX.
- the `app` container waits for PostgreSQL, ensures `APP_KEY`, persists it at `./.docker-state/app.key`, applies pending Laravel migrations, syncs relay config, and then serves `php-fpm`.
- the `relay` container runs MediaMTX from the same app image and reads the generated config from `storage/app/private/mediamtx/mediamtx.yml`.
- the `web` container serves Nginx for the operator UI and proxies `/__webrtc/` traffic to `relay`.
- `./docker/compose.sh` wraps the default [docker-compose.yml](/home/administrator/dockers/bigbrotha/docker-compose.yml), the selected `.env.docker`, and the configured `COMPOSE_PROJECT_NAME` so multiple stacks can coexist on one host.
- `./docker/compose-up.sh` uses [docker-compose.build.yml](/home/administrator/dockers/bigbrotha/docker-compose.build.yml); the base Compose file applies `CAMERA_RECORDING_WORKER_PROCESSES` as the worker-service scale for both build and image deployments.
- the `worker` and `scheduler` containers wait for the app bootstrap marker and rely on Docker healthchecks and restart policies instead of cron or systemd.
- internal service traffic uses Docker DNS names: `database`, `app`, `relay`, and `web`.
- the bundled PostgreSQL service stays internal to the Compose network by default.
- MediaMTX signaling and API traffic stay internal to the Compose network; only the web port and WebRTC ICE port need to be unique on the host.
- the `app` container must have routed reachability to camera HTTP, ONVIF, and RTSP endpoints.
- Compose log rotation is bounded, containers use Docker's init process where appropriate, and the relay runs as `www-data` with all Linux capabilities dropped.
- PostgreSQL 18 uses the named `db-data` volume at `/var/lib/postgresql`, which preserves its version-specific data directory.

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
./docker/compose.sh logs --tail=100 app relay web worker scheduler
./docker/compose.sh exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `relay`, `web`, `worker`, and `scheduler` running.
- `app`, `relay`, `web`, `worker`, and `scheduler` reporting healthy after startup settles.
- `php artisan relay:status` reporting MediaMTX installed, running, and API reachable.

If you started the stack with raw Compose, keep using the same form for follow-up commands:

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=100 app relay web worker scheduler
docker compose --env-file .env.docker exec app php artisan relay:status
```

For the full first-time bootstrap flow, see [docs/startup-from-scratch.md](docs/startup-from-scratch.md).

## Project Context

- [AGENTS.md](AGENTS.md)
- [docs/video-platform-architecture.md](docs/video-platform-architecture.md)
- [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md)
- [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md)
- [docs/startup-from-scratch.md](docs/startup-from-scratch.md)

## Publishing Images

`publish.sh` builds a dedicated test image, runs the full application suite, validates the production app and Nginx images, publishes an immutable timestamp-and-commit tag, updates `latest`, and verifies each remote manifest.

```bash
./publish.sh
```

Docker daemon access and an existing Docker Hub login are required. Set `IMAGE_TAG` to choose an explicit immutable tag or `PUBLISH_LATEST=false` when `latest` must not move.

## Useful Commands

```bash
./docker/compose.sh exec app php artisan test
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
