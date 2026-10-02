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

Prepare the configuration:

```bash
./docker/compose.sh init
```

This creates `.env.docker` with private permissions and generates a strong database password. It does not require Docker access or start containers, and repeated runs preserve existing values. Set `APP_URL` in that file to the exact public origin. This is the only value a standard installation needs you to enter. Defaults are HTTP port `8082`, ICE TCP/UDP port `8190`, two queue workers, and the published `rekanized/bigbrotha-app:latest` image. The stack name defaults to the checkout directory's name.

The small template shows optional stack, port, and image overrides. Advanced values can be added when needed:

- `COMPOSE_PROJECT_NAME` set to a unique stack name when this host runs more than one BigBrotha deployment
- `BIGBROTHA_APP_IMAGE` if you need to pin a specific published tag
- `WEB_BIND_IP`
- `WEB_PORT`
- `MEDIAMTX_ICE_BIND_IP`
- `MEDIAMTX_ICE_PORT`
- `DB_DATABASE` and `DB_USERNAME` to change the bundled PostgreSQL database/user names on a new installation; changing these values does not rename existing database objects
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of queue-worker processes inside `background`
- `TRUSTED_PROXIES` to change the Docker image's default trusted proxy range of `172.16.0.0/12`; use the addresses of the reverse proxies that serve your installation

Application settings such as authentication and SMB storage belong in the setup/admin screens. Advanced runtime overrides can still be added to `.env.docker`; their defaults are defined in `config/*.php` and the application image. Database and relay API/RTSP ports stay private, so they need no host configuration.

For this deployment, the bundled Docker defaults publish Nginx on `WEB_PORT=8082`, publish MediaMTX ICE on the configured `MEDIAMTX_ICE_PORT`, and expect `APP_URL` to stay set to the public origin that browsers and the later Google OAuth setup flow use.

For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `APP_URL`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`. MediaMTX signaling and API traffic stay internal to the Compose network, so they do not need separate host ports per stack.

`./docker/compose.sh` generates and saves a strong `DB_PASSWORD` when the value is empty or missing. If you use raw `docker compose` commands, generate and set that password yourself before startup.

Google OAuth configuration is database-only. Remove legacy Google client ID, client secret, or redirect URI entries from custom `.env.docker` files and use the setup or admin authentication flow instead.

## 2. Start The Stack

For a normal deployment that should pull published images:

```bash
./docker/compose.sh start
```

`start` pulls the selected images, starts the four services, removes obsolete containers, and waits for healthy services. Reuse it for published-image updates. Standard Compose commands such as `ps`, `logs`, and `exec` still pass through unchanged.

If this host requires Docker commands through sudo, run:

```bash
sudo ./docker/compose.sh start
```

For a local source checkout that should build images from the repository:

```bash
./docker/compose.sh --local start
```

This builds the final production image as `bigbrotha:local`, shared by `app`, `background`, and `relay`, and waits for healthy services. Set `BIGBROTHA_BUILD_IMAGE` to choose a different local tag. `BIGBROTHA_APP_IMAGE` remains the published-image selector and is never used as the local build tag. Keep `--local` for subsequent operations on this stack:

```bash
./docker/compose.sh --local ps
./docker/compose.sh --local logs --tail=100 app background relay
```

Using `./docker/compose.sh start` without a mode selects the published image. The wrapper selects the appropriate overlay for `--local` and `--dev`; you do not need to supply Compose filenames. The old `./docker/compose-up.sh` shortcut remains equivalent to `--local start`.

For development from a checkout, build the development image and start the same four services:

```bash
./docker/compose.sh --dev start
```

The development image includes Composer. The `app` and `background` containers mount the checkout at `/app` and share a `dev-vendor` volume for dependencies. The app runs `composer install` from the lock file at startup; after editing application code, reload the page without rebuilding or restarting. Development clears Laravel route, event, and view caches and enables PHP timestamp checks. Its Nginx file cache is disabled. The host `.env` is masked inside those containers; `.env.docker` supplies runtime settings through Compose. Use `./docker/compose.sh --dev up -d` after a normal stop, and `./docker/compose.sh --dev ps` to inspect this stack. The older `compose-dev.sh` shortcut still works.

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
- the application image defaults to two queue workers; `CAMERA_RECORDING_WORKER_PROCESSES` in `.env.docker` overrides that count.
- The image uses `SIGTERM` for Supervisor and MediaMTX. Supervisor gives workers 300 seconds to finish active jobs, followed by up to 55 seconds for the scheduler. Compose gives `background` a six-minute shutdown grace period. If custom job timeouts exceed five minutes, increase the Supervisor worker wait and Compose grace together.
- the application image owns fixed production defaults plus role-aware health checks with shared timing, so the Compose file only carries deployment-specific values and service topology.
- `sh tests/Docker/compose.test.sh` validates deployment, build, and development Compose merges using placeholder credentials without starting containers. The supported runtime uses PostgreSQL; SQLite support is included only in test/development images, and the unused MySQL PHP driver is omitted.
- Keep `./.docker-state/app.key` with the deployment. If that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The bundled PostgreSQL service stays internal to the Compose network by default.
- The relay, database, background, and Laravel services communicate through Docker DNS names such as `app`, `relay`, and `database`.
- PostgreSQL 18's named volume is mounted at `/var/lib/postgresql`, above its version-specific `PGDATA`. Deployments that previously mounted `/var/lib/postgresql/data` must run `docker/migrate-postgres-18-volume.sh` before the database container is recreated.
