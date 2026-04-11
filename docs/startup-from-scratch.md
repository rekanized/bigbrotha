# Startup From Scratch

Use this when you are bringing up a new BigBrotha host from nothing.

## Preferred Docker Path

If the host has Docker with Compose support, the repository now includes a deployment path that avoids host-installed PHP, Composer, cron, systemd, ffmpeg, ffprobe, and MediaMTX binaries.

Container services in [docker-compose.yml](../docker-compose.yml):

- `app` runs Laravel under `php-fpm` and starts MediaMTX locally on demand through the baked image binary.
- `web` runs Nginx and proxies `/__webrtc/` to the `app` container.
- `worker` runs `php artisan queue:work` for `recordings,default,review-assets`.
- `scheduler` runs `php artisan schedule:run` in a loop instead of relying on host cron.
- `database` runs `postgres:18-alpine` for the default compose setup.

### Docker Prerequisites

- Docker with Compose support.
- Linux-compatible statically compiled `ffmpeg` and `ffprobe` binaries committed in `bin/`.
- A `.env` file copied from `.env.example` with `APP_URL`, database values, and Google auth values. The Docker quick-start command below generates `APP_KEY` into that file.

### Docker Startup

```bash
cp .env.example .env
docker compose build
docker compose up -d
```

### Docker Hub Startup

```bash
cp .env.example .env
printf '%s\n' 'BIGBROTHA_APP_IMAGE=yourdockerhubuser/bigbrotha-app:latest' >> .env
printf '%s\n' 'BIGBROTHA_WEB_IMAGE=yourdockerhubuser/bigbrotha-web:latest' >> .env
docker compose pull
docker compose up -d --no-build
```

Recommended Docker env values:

- `APP_URL=http://localhost:8080` for a local compose stack unless you publish a different host or port.
- `DB_CONNECTION=pgsql` when using the bundled compose database.
- `DB_PASSWORD=...` with a real production password instead of the compose fallback.

Docker runtime notes:

- the Docker images use `/app` as the internal application root. That path is inside the image and is not tied to any host checkout path such as `/var/www/...`.
- the compose file can either build local images or pull published images. Local builds are tagged as `bigbrotha-app:local` and `bigbrotha-web:local` unless `BIGBROTHA_APP_IMAGE` and `BIGBROTHA_WEB_IMAGE` are set.
- the compose file injects production-safe defaults for `APP_ENV`, `APP_DEBUG`, `TRUSTED_PROXIES`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE`, and logging to `stderr` so the stack does not inherit local development behavior from `.env.example`.
- the app image bakes in MediaMTX and uses `MEDIAMTX_INSTALL_MODE=bundled` with `MEDIAMTX_BINARY_PATH=/usr/local/bin/mediamtx`.
- the compose stack also sets `MEDIAMTX_LOG_PATH=/dev/stdout`, so relay failures and startup logs appear in normal container logs.
- the `app` container bootstraps itself automatically: after the database is reachable it runs `php artisan migrate --force` when the `users` table is missing or has zero rows, and then starts MediaMTX automatically.
- the app image has a Docker `HEALTHCHECK` that waits for bootstrap completion, verifies `php-fpm` on port `9000`, and checks the local MediaMTX API when relay auto-start is enabled.
- Compose also injects `MEDIAMTX_AUTH_CALLBACK_URL=http://web/relay/auth/mediamtx` because the public `APP_URL` is not reachable as `localhost` from inside the `app` container.
- Compose also injects `MEDIAMTX_RTSP_INTERNAL_BASE_URL=rtsp://app:8554` so any Laravel container that uses the relay source talks to the `app` service on the Docker network instead of to its own loopback device.
- if `APP_KEY` is missing, the entrypoint generates it once, persists it under shared Docker storage, and writes it into the mounted `.env` file so later `docker compose exec app php artisan ...` commands see the same key.
- the app image includes `smbclient`, so SMB-backed camera storage does not require a host binary outside Docker.
- Compose publishes the HTTP UI on `APP_HTTP_PORT` and the WebRTC ICE ports on `MEDIAMTX_WEBRTC_TCP_PORT` and `MEDIAMTX_WEBRTC_UDP_PORT`.
- PostgreSQL remains internal to the Docker network by default and is not published to the host unless you add that override yourself.
- the `web` service also has a lightweight healthcheck through Nginx, and `worker` plus `scheduler` now wait for the `app` service to become healthy before they start.
- `worker` and `scheduler` wait for the app bootstrap marker before they start processing jobs or scheduler ticks, so first-run migrations do not race those services.

Recommended Docker verification:

```bash
docker compose ps
docker compose exec app php artisan relay:status
docker compose logs --tail=100 app web worker scheduler
```

`php artisan relay:status` is optional verification. Normal startup should not require it.

## Bare Metal Or VM Host Path

## Prerequisites

- PHP 8.3 CLI and the PHP extensions required by Laravel.
- Composer.
- Linux-compatible statically compiled `ffmpeg` and `ffprobe` binaries committed in `bin/`, or alternate absolute binary paths ready for `.env`.
- A writable database supported by Laravel.
- Cron running on the host.
- User systemd, system systemd, Supervisor, or another real process manager.
- A web server or reverse proxy in front of Laravel for non-local deployments.

This repository is Composer-only. Do not add Node, Vite, Tailwind, or any frontend build pipeline.

## 1. Fetch The App

```bash
git clone <your-repo-url> /var/www/bigbrotha
cd /var/www/bigbrotha
composer install --no-dev --optimize-autoloader
```

For a local development install, dropping `--no-dev` is fine.

## 2. Create The Environment File

```bash
cp .env.example .env
php artisan key:generate
```

Then update `.env` with the values that match the host.

Minimum values to review:

- `APP_URL`
- `DB_CONNECTION` and matching database credentials
- `QUEUE_CONNECTION=database`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `CAMERA_RECORDING_ENSURE_WORKER=true`

If the correct CLI binary is not just `php`, also set:

- `CAMERA_RECORDING_WORKER_PHP_BINARY=/usr/bin/php8.3`

That ensures the generated recordings worker service uses the right interpreter.

The default FFmpeg runtime values now live in `config/ffmpeg.php`. Without any FFmpeg-related `.env` entries, Laravel resolves the bundled binaries from `base_path('bin')` and the temp workspace from `storage_path(...)`. Only add `FFMPEG_BINARIES`, `FFPROBE_BINARIES`, or `FFMPEG_TEMPORARY_DIRECTORY` when a host needs to override those defaults.

The default MediaMTX runtime values now live in `config/mediamtx.php`. Without any MediaMTX-specific `.env` entries, Laravel derives the public WebRTC base URL from `APP_URL` as `APP_URL + /__webrtc`, derives the auth callback URL from the app origin, and derives relay secrets plus internal publisher credentials from `APP_KEY`. Only add MediaMTX env values when a host needs a different public relay URL, additional ICE hosts, or explicit secret overrides. If a deployment needs different relay ports, paths, install locations, or transcode settings, edit `config/mediamtx.php` directly.

For Docker deployments, set `MEDIAMTX_INSTALL_MODE=bundled` and `MEDIAMTX_BINARY_PATH=/usr/local/bin/mediamtx` so Laravel uses the image-baked binary instead of trying to download one at runtime.

If the host uses SMB or NAS-backed camera storage through the admin settings page, point that setting at the dedicated camera-storage directory itself, not its parent. For example, prefer `//fileserver/share/cameras` or `//fileserver/share/Applications/bigbrotha/cameras` over a parent path like `//fileserver/share/Applications/bigbrotha`.

## 3. Prepare The Database

```bash
php artisan migrate --force
```

If you are using the default sqlite example, make sure the database file exists and is writable before running migrations.

## 4. Install And Sync MediaMTX

```bash
composer relay:install
php artisan relay:sync
```

This project manages MediaMTX from Laravel rather than expecting a separate hand-installed binary.

## 5. Install The Recordings Worker Service

```bash
composer recordings:worker:install
```

That command:

1. writes the user systemd unit for the recordings worker
2. runs `systemctl --user daemon-reload`
3. enables the service
4. starts it immediately

If you are provisioning a host where user systemd may not be available yet and you want a non-failing attempt, use:

```bash
php artisan camera-recordings:install-worker-service --graceful
```

## 6. Add The Scheduler Cron

Add this cron entry for the deploy user:

```cron
* * * * * cd /var/www/bigbrotha && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler drives:

- preview refreshes
- recording tick creation
- stale recording recovery
- recordings worker safety-net checks
- recording retention pruning

## 7. Start The App Services

```bash
php artisan optimize:clear
php artisan relay:start
php artisan camera-recordings:ensure-worker
```

`camera-recordings:ensure-worker` is the safety-net check. The real persistence still comes from the installed process manager.

## 8. Verify The Host

Run these checks after startup:

```bash
php artisan relay:status
php artisan camera-recordings:ensure-worker
systemctl --user --no-pager --full status bigbrotha-recordings-queue.service
php artisan schedule:run
php artisan test tests/Feature/RecordingWorkerCommandTest.php tests/Feature/CameraRecordingCommandTest.php tests/Feature/CameraRecordingMotionCommandTest.php tests/Feature/CameraRecordingMaintenanceCommandTest.php
```

What you want to see:

- MediaMTX installed, running, and API reachable.
- The recordings worker already running.
- The systemd user unit enabled and active.
- The scheduler completing without errors.

## 9. Local Development Shortcut

For a quick non-production startup:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer relay:install
php artisan relay:sync
php artisan serve
```

In another shell, run:

```bash
php artisan queue:work --queue=recordings,default,review-assets --max-jobs=50 --max-time=3600 --memory=256
```

And either run cron externally or trigger the scheduler manually while testing:

```bash
php artisan schedule:run
```

## Notes

- Pure PHP request handling is not a substitute for a real queue worker.
- The scheduler can detect a missing worker and start the configured systemd unit, but it is not itself the worker.
- If you need full reboot and logout persistence, use a root-managed service or enable linger for the deploy user.
- Docker deployments replace the host cron and systemd requirements with the dedicated `scheduler` and `worker` compose services.