# Startup From Scratch

Use this when you are bringing up a new BigBrothas host from nothing.

## Prerequisites

- PHP 8.3 CLI and the PHP extensions required by Laravel.
- Composer.
- ffmpeg and ffprobe installed on the host.
- A writable database supported by Laravel.
- Cron running on the host.
- User systemd, system systemd, Supervisor, or another real process manager.
- A web server or reverse proxy in front of Laravel for non-local deployments.

This repository is Composer-only. Do not add Node, Vite, Tailwind, or any frontend build pipeline.

## 1. Fetch The App

```bash
git clone <your-repo-url> /var/www/bigbrothas
cd /var/www/bigbrothas
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
- `FFMPEG_BINARY`
- `FFPROBE_BINARY`
- `MEDIAMTX_WEBRTC_PUBLIC_URL`
- `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`
- `MEDIAMTX_AUTH_CALLBACK_URL`
- `MEDIAMTX_AUTH_CALLBACK_SECRET`
- `MEDIAMTX_AUTH_TOKEN_SECRET`
- `MEDIAMTX_PUBLISHER_USER`
- `MEDIAMTX_PUBLISHER_PASS`
- `CAMERA_RECORDING_ENSURE_WORKER=true`

If the correct CLI binary is not just `php`, also set:

- `CAMERA_RECORDING_WORKER_PHP_BINARY=/usr/bin/php8.3`

That ensures the generated recordings worker service uses the right interpreter.

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
* * * * * cd /var/www/bigbrothas && php artisan schedule:run >> /dev/null 2>&1
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
systemctl --user --no-pager --full status bigbrothas-recordings-queue.service
php artisan schedule:run
php artisan test tests/Feature/RecordingWorkerCommandTest.php tests/Feature/CameraRecordingCommandTest.php
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
php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256
```

And either run cron externally or trigger the scheduler manually while testing:

```bash
php artisan schedule:run
```

## Notes

- Pure PHP request handling is not a substitute for a real queue worker.
- The scheduler can detect a missing worker and start the configured systemd unit, but it is not itself the worker.
- If you need full reboot and logout persistence, use a root-managed service or enable linger for the deploy user.