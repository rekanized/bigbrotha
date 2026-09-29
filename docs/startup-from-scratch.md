# Startup From Scratch

Use this when you are bringing up a new BigBrotha deployment from nothing.

## Supported Runtime

BigBrotha now targets a single supported runtime: Docker Compose.

The default stack in [docker-compose.yml](../docker-compose.yml) runs from published Docker Hub images. Local production image builds use [docker-compose.build.yml](../docker-compose.build.yml) as an override. Development uses [docker-compose.dev.yml](../docker-compose.dev.yml) to bind mount source code.

Use `./docker/compose.sh` for routine Compose commands so the selected `.env.docker` file and `COMPOSE_PROJECT_NAME` stay aligned across the deployment lifecycle.

Plain `docker compose up -d` does not read `.env.docker` for Compose-level interpolation. If you skip `--env-file .env.docker` or the wrapper, Compose fails because `APP_URL` and `DB_PASSWORD` are intentionally required.

The stack runs these services:

- `app` for Supervisor-managed Nginx and PHP-FPM, including `/__webrtc/` proxying.
- `background` for one scheduler and the configured queue-worker process pool.
- `relay` for MediaMTX and its on-demand stream processes.
- `database` for the bundled PostgreSQL service.

## Prerequisites

- Docker with Compose support.
- Routed reachability from the Docker host to the camera network.

## 1. Review Deployment Values

Before startup, copy `.env.docker.example` to `.env.docker`, restrict it to the owner with `chmod 600 .env.docker`, and set `APP_URL` to the exact public origin. The remaining values are optional deployment overrides:

- `COMPOSE_PROJECT_NAME` set to a unique stack name when this host runs more than one BigBrotha deployment
- `BIGBROTHA_APP_IMAGE` if you need to pin a specific published tag
- `WEB_BIND_IP`
- `WEB_PORT`
- `MEDIAMTX_ICE_BIND_IP`
- `MEDIAMTX_ICE_PORT`
- `DB_*` values if you are not using the bundled PostgreSQL defaults
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of queue-worker processes inside `background`

For this deployment, the bundled Docker defaults publish Nginx on `WEB_PORT=8082`, publish MediaMTX ICE on the configured `MEDIAMTX_ICE_PORT`, and expect `APP_URL` to stay set to the public origin that browsers and the later Google OAuth setup flow use.

For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `APP_URL`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`. MediaMTX signaling and API traffic stay internal to the Compose network, so they do not need separate host ports per stack.

`./docker/compose.sh` generates and saves a strong `DB_PASSWORD` when the value is empty or missing. If you use raw `docker compose` commands, generate and set that password yourself before startup.

Google OAuth configuration is database-only. Remove legacy Google client ID, client secret, or redirect URI entries from custom `.env.docker` files and use the setup or admin authentication flow instead.

## 2. Start The Stack

For a normal deployment that should pull published images:

```bash
./docker/compose.sh pull
./docker/compose.sh up -d --remove-orphans
```

If this host requires Docker commands through sudo, run:

```bash
sudo ./docker/compose.sh up -d --remove-orphans
```

For a local source checkout that should build images from the repository:

```bash
./docker/compose-up.sh
```

For development from a checkout, build the development image and start the same four services:

```bash
./docker/compose-dev.sh up -d --build
```

The development image includes Composer. The `app` and `background` containers mount the checkout at `/app` and share a `dev-vendor` volume for dependencies. The app runs `composer install` from the lock file at startup; after editing application code, reload the page without rebuilding or restarting. Development clears Laravel route, event, and view caches and enables PHP timestamp checks. Its Nginx file cache is disabled. The host `.env` is masked inside those containers; `.env.docker` supplies runtime settings through Compose. Use `./docker/compose-dev.sh up -d` after a normal stop, and `./docker/compose-dev.sh ps` to inspect this stack. Run `./docker/compose.sh up -d` to return the same stack to the published production image.

For a production release, `./publish.sh` builds and tests the image, checks that the production image contains the app and excludes local secrets, pushes a tagged copy to Docker Hub, and verifies its manifest. Run `PUSH_IMAGES=false ./publish.sh` first to exercise the local publish path without pushing. On a second server, set `BIGBROTHA_APP_IMAGE` in `.env.docker` to the published tag, then use `./docker/compose.sh pull` and `./docker/compose.sh up -d`. Transfer the server's application key and persistent volumes only when moving an existing deployment, never as image contents.

The `app` container will:

- wait for PostgreSQL on `database:5432`
- ensure `APP_KEY` and persist it under `./.docker-state/app.key` on the Docker host
- apply pending Laravel migrations automatically and bootstrap an empty database when needed
- supervise Nginx and PHP-FPM after writing the bootstrap marker that unblocks `background` and `relay`

After the containers are healthy on a brand-new deployment, open `/setup` on the published application URL and complete the onboarding wizard:

- choose whether local sign-in, Google OAuth, or both should be enabled
- create the initial local administrator when local sign-in is enabled
- enter the Google client ID, client secret, and redirect URI there and run the built-in Google validation flow before enabling Google OAuth
- for Google-only setup, sign in first with the same Google account used for validation; it becomes the initial administrator

## 3. Confirm Camera Reachability

The `app` container must be able to reach camera HTTP, ONVIF, and RTSP endpoints directly.

The current onboarding flow uses direct ONVIF probes from Camera Fleet, so this is a routed reachability requirement rather than a multicast discovery requirement.

## 4. Verify Startup

```bash
./docker/compose.sh ps
./docker/compose.sh logs --tail=100 app background relay
./docker/compose.sh exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `background`, and `relay` running and healthy once startup settles
- MediaMTX installed, running, and API reachable from the `app` container

## 5. Useful Runtime Commands

```bash
./docker/compose.sh exec app php artisan relay:sync
./docker/compose.sh exec app php artisan relay:start
./docker/compose.sh exec app php artisan relay:status
./docker/compose.sh exec app php artisan camera-recordings:tick
./docker/compose.sh exec app php artisan camera-recordings:prune
./docker/compose.sh exec app php artisan camera-recordings:build-review-assets --missing
```

## Notes

- The scheduler and worker pool are Supervisor-managed inside `background`. Do not install host cron jobs or systemd units for queue work.
- When upgrading from the former six-service layout, pass `--remove-orphans` so the obsolete `web`, `worker`, and `scheduler` containers release their resources and host port.
- The default [docker-compose.yml](../docker-compose.yml) is image-first so a deployment can run from Docker Hub without local Docker builds.
- `./docker/compose.sh` keeps routine Compose commands pinned to the same `.env.docker` file and `COMPOSE_PROJECT_NAME`.
- `./docker/compose-up.sh` explicitly uses the repo-root Compose files and builds the application images from source.
- the application image reads `CAMERA_RECORDING_WORKER_PROCESSES` from `.env.docker` and applies it as the supervised worker-process count.
- the application image owns fixed production defaults plus role-aware health checks, so the Compose file only carries deployment-specific values and service topology.
- Keep `./.docker-state/app.key` with the deployment. If that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The bundled PostgreSQL service stays internal to the Compose network by default.
- The relay, database, background, and Laravel services communicate through Docker DNS names such as `app`, `relay`, and `database`.
- PostgreSQL 18's named volume is mounted at `/var/lib/postgresql`, above its version-specific `PGDATA`. Deployments that previously mounted `/var/lib/postgresql/data` must run `docker/migrate-postgres-18-volume.sh` before the database container is recreated.
