# Startup From Scratch

Use this when you are bringing up a new BigBrotha deployment from nothing.

## Supported Runtime

The supported runtime is Docker Compose.

The default stack in [docker-compose.yml](../docker-compose.yml) runs from published Docker Hub images. Local production image builds use [docker-compose.build.yml](../docker-compose.build.yml) as an override. Development uses [docker-compose.dev.yml](../docker-compose.dev.yml) to bind mount source code.

Run standard `docker compose` commands from the deployment directory. Compose automatically loads `.env` for interpolation, and the application services load the same file for runtime settings. No setup or startup wrapper is required. `APP_URL` and `DB_PASSWORD` must be set before startup.

The stack runs these services:

- `app` for Supervisor-managed Nginx and PHP-FPM, including `/__webrtc/` proxying.
- `background` for one scheduler and the configured queue-worker process pool.
- `relay` for MediaMTX and its on-demand stream processes.
- `database` for the bundled PostgreSQL service.

## Prerequisites

- Docker with Compose support.
- Routed reachability from the Docker host to the camera network.

## 1. Review Deployment Values

For a new deployment, pull the Docker Hub image and extract its Compose file and configuration template into an empty deployment directory. The host does not need the repository or PHP:

```bash
mkdir bigbrotha
cd bigbrotha
docker pull rekanized/bigbrotha-app:latest
docker run --rm --entrypoint cat rekanized/bigbrotha-app:latest /usr/share/bigbrotha/docker-compose.yml > docker-compose.yml
docker run --rm --entrypoint cat rekanized/bigbrotha-app:latest /usr/share/bigbrotha/.env.example > .env.example
cp .env.example .env
chmod 600 .env
```

For a new installation, this creates a private `.env`. Set `APP_URL` to the exact public origin without a trailing slash and `DB_PASSWORD` to a strong random password. Generate a password with `openssl rand -hex 32` or a password manager, then paste it into `DB_PASSWORD`. Do not overwrite an existing deployment configuration or change its database password as part of an upgrade. Defaults are HTTP port `8082`, ICE TCP/UDP port `8190`, two queue workers, and the published `rekanized/bigbrotha-app:latest` image. The stack name defaults to the deployment directory's name.

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

Application settings such as authentication and SMB storage belong in the setup/admin screens. Advanced runtime overrides can still be added to `.env`; their defaults are defined in `config/*.php` and the application image. Database and relay API/RTSP ports stay private, so they need no host configuration.

The bundled Docker defaults publish Nginx on `WEB_PORT=8082`, publish MediaMTX ICE on the configured `MEDIAMTX_ICE_PORT`, and expect `APP_URL` to stay set to the public origin that browsers and the later Google OAuth setup flow use.

For multiple deployments on one host, give each stack a unique `COMPOSE_PROJECT_NAME`, `APP_URL`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT`. MediaMTX signaling and API traffic stay internal to the Compose network, so they do not need separate host ports per stack.

Google OAuth configuration is database-only. Remove legacy Google client ID, client secret, or redirect URI entries from custom `.env` files and use the setup or admin authentication flow instead.

## Existing installations

If your deployment already uses `.env.docker`, keep its existing values and let Compose load that same file through a `.env` link. If a host `.env` already exists, back it up privately and move it aside first. From the same deployment directory:

```bash
ln -s .env.docker .env
chmod 600 .env.docker
```

Keep the existing project name, deployment directory, Compose files, volumes, and `.docker-state/app.key`. The link preserves the existing configuration for the next container update; it does not reset setup, generate credentials, or change data. Do not run `down -v` during an update.

The Compose helper scripts have been removed. Use standard Compose commands once `.env` is in place. The database maintenance scripts use the same `.env`, following its link when present. They also accept `BIGBROTHA_DOCKER_ENV_FILE` for a custom file and fall back to `.env.docker` when `.env` is absent.

Database maintenance scripts must also be able to write private backups in `.docker-state`, which may be owned by root after Docker creates it. If needed, run those maintenance scripts with `sudo`; keep the key directory private.

For a custom configuration file without a `.env` link, use both the runtime-file selector and Compose's interpolation-file option for every command:

```bash
BIGBROTHA_DOCKER_ENV_FILE=.env.docker docker compose --env-file .env.docker up -d --wait
```

The same pattern supports another configuration path. `--env-file` alone changes Compose interpolation; `BIGBROTHA_DOCKER_ENV_FILE` selects the file passed into the application services.

## 2. Start The Stack

For a normal deployment that should pull published images:

```bash
docker compose up -d --wait
```

`up -d --wait` pulls missing images, starts the four services, and waits for healthy services. Plain `docker compose up -d` also works, but returns without waiting for health checks. To update published images and remove obsolete containers:

```bash
docker compose pull
docker compose up -d --wait --remove-orphans
```

If this host requires Docker commands through sudo, run:

```bash
sudo docker compose up -d --wait
```

To build from source instead, install Git, clone the repository into a separate directory, and prepare `.env` there with its own project name and ports:

```bash
git clone https://github.com/rekanized/bigbrotha.git bigbrotha-source
cd bigbrotha-source
cp .env.example .env
chmod 600 .env
```

Set `APP_URL` and `DB_PASSWORD`, then build and start:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build --wait
```

This builds the final production image as `bigbrotha:local`, shared by `app`, `background`, and `relay`, and waits for healthy services. Set `BIGBROTHA_BUILD_IMAGE` to choose a different local tag. `BIGBROTHA_APP_IMAGE` remains the published-image selector and is never used as the local build tag. Include both Compose files for subsequent operations on this stack:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml ps
docker compose -f docker-compose.yml -f docker-compose.build.yml logs --tail=100 app background relay
```

The base Compose file selects the published image. Explicitly include the build or development override whenever operating on a stack that uses one, so later commands resolve the same images and volumes.

For development from a checkout, build the development image and start the same four services:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build --wait
```

The development image includes Composer. The `app` and `background` containers mount the checkout at `/app` and share a `dev-vendor` volume for dependencies. The app runs `composer install` from the lock file at startup; after editing application code, reload the page without rebuilding or restarting. Development clears Laravel route, event, and view caches and enables PHP timestamp checks. Its Nginx file cache is disabled. The host `.env` is masked inside those containers; `.env` supplies runtime settings through Compose. Use the same two Compose files for subsequent commands, including `up -d`, `ps`, `logs`, and `exec`.

For a production release, `./publish.sh` refreshes base images and package layers by default (`--pull --no-cache`), builds and tests the image, checks that the production image contains the app and excludes local secrets, pushes a tagged copy to Docker Hub, and verifies its manifest. Run `PUSH_IMAGES=false ./publish.sh` first to exercise the local publish path without pushing. On a second server, set `BIGBROTHA_APP_IMAGE` in `.env` to the published tag, then use `docker compose pull` and `docker compose up -d`. Transfer the server's application key and persistent volumes only when moving an existing deployment, never as image contents.

The `app` container will:

- wait for PostgreSQL on `database:5432`
- ensure `APP_KEY` and persist it under `./.docker-state/app.key` on the Docker host
- apply pending Laravel migrations automatically and bootstrap an empty database when needed
- supervise Nginx and PHP-FPM after writing the bootstrap marker that unblocks `background` and `relay`

After the containers are healthy on a brand-new deployment, retrieve the private setup token with:

```bash
docker compose exec app php artisan setup:token
```

Include the same Compose override files as the running stack. The command is available only before setup completes. Keep the token private; it grants permission to configure the first administrator. Then open `/setup` on the published application URL, enter the token, and complete the onboarding wizard:

- choose whether local sign-in, Google OAuth, or both should be enabled
- create the initial local administrator when local sign-in is enabled
- enter the Google client ID, client secret, and redirect URI there and run the built-in Google validation flow before enabling Google OAuth
- for Google-only setup, sign in first with the same verified Google identity used for validation; both email and Google subject must match, and it becomes the initial administrator

## 3. Confirm Camera Reachability

The `app` container must be able to reach camera HTTP, ONVIF, and RTSP endpoints directly.

The current onboarding flow uses direct ONVIF probes from Camera Fleet, so this is a routed reachability requirement rather than a multicast discovery requirement.

## 4. Verify Startup

```bash
docker compose ps
docker compose logs --tail=100 app background relay
docker compose exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `background`, and `relay` running and healthy once startup settles
- MediaMTX installed, running, and API reachable from the `app` container

## 5. Useful Runtime Commands

```bash
docker compose exec app php artisan relay:sync
docker compose exec app php artisan relay:start
docker compose exec app php artisan relay:status
docker compose exec app php artisan camera-recordings:tick
docker compose exec app php artisan camera-recordings:prune
docker compose exec app php artisan camera-recordings:build-review-assets --missing
```

## Notes

- The scheduler and worker pool are Supervisor-managed inside `background`. Do not install host cron jobs or systemd units for queue work.
- When upgrading from the former six-service layout, pass `--remove-orphans` so the obsolete `web`, `worker`, and `scheduler` containers release their resources and host port.
- The default [docker-compose.yml](../docker-compose.yml) is image-first so a deployment can run from Docker Hub without local Docker builds.
- Run Compose from the deployment directory so it loads the same `.env`, project name, and Compose files.
- the application image defaults to two queue workers; `CAMERA_RECORDING_WORKER_PROCESSES` in `.env` overrides that count.
- The image uses `SIGTERM` for Supervisor and MediaMTX. Supervisor gives workers 300 seconds to finish active jobs, followed by up to 55 seconds for the scheduler. Compose gives `background` a six-minute shutdown grace period. If custom job timeouts exceed five minutes, increase the Supervisor worker wait and Compose grace together.
- the application image owns fixed production defaults plus role-aware health checks with shared timing, so the Compose file only carries deployment-specific values and service topology.
- `sh tests/Docker/compose.test.sh` validates deployment, build, and development Compose merges using placeholder credentials without starting containers. The supported runtime uses PostgreSQL; SQLite support is included only in test/development images, and the unused MySQL PHP driver is omitted.
- Keep `./.docker-state/app.key` with the deployment. If that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The bundled PostgreSQL service stays internal to the Compose network by default.
- The relay, database, background, and Laravel services communicate through Docker DNS names such as `app`, `relay`, and `database`.
- PostgreSQL 18's named volume is mounted at `/var/lib/postgresql`, above its version-specific `PGDATA`. Deployments that previously mounted `/var/lib/postgresql/data` must run `docker/migrate-postgres-18-volume.sh` before the database container is recreated.

## Backup and recovery

Follow [backup and restore](backup-and-restore.md) to capture a matching database, private storage, and encryption key, and to verify recovery in an isolated stack. Keep backups and `.env` outside the public repository.
