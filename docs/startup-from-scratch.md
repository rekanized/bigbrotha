# Startup From Scratch

Use this when you are bringing up a new BigBrotha host from nothing.

## Preferred Docker Path

If the host has Docker with Compose support, the repository now includes a deployment path that avoids host-installed PHP, Composer, cron, systemd, ffmpeg, ffprobe, and MediaMTX binaries.

Container services in [docker-compose.yml](../docker-compose.yml):

# Startup From Scratch

Use this when you are bringing up a new BigBrotha deployment from nothing.

## Supported Runtime

BigBrotha now targets a single supported runtime: Docker Compose.

The stack in [docker-compose.yml](../docker-compose.yml) runs these services:

- `app` for Laravel under `php-fpm` plus the bundled MediaMTX relay.
- `web` for Nginx and `/__webrtc/` proxying.
- `worker` for `php artisan queue:work` on `recordings,default,review-assets`.
- `scheduler` for the recurring `php artisan schedule:run` loop.
- `database` for the bundled PostgreSQL service.

## Prerequisites

- Docker with Compose support.
- The repository `bin/ffmpeg` and `bin/ffprobe` binaries present in the checkout.
- Routed reachability from the Docker host to the camera network.

## 1. Review Deployment Values

Before startup, review these values in [docker-compose.yml](../docker-compose.yml):

- copy `.env.docker.example` to `.env.docker`
- `APP_URL`
- `WEB_PORT`
- `MEDIAMTX_ICE_PORT`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `DB_*` values if you are not using the bundled PostgreSQL defaults
- `CAMERA_RECORDING_WORKER_PROCESSES` for the desired number of `worker` replicas

For this deployment, the bundled Docker defaults publish Nginx on `WEB_PORT=8082`, publish MediaMTX ICE on the configured `MEDIAMTX_ICE_PORT`, and expect `APP_URL` to stay set to the public origin that browsers and Google OAuth use.

If you want file-backed secrets instead of environment values, the app containers also accept `DB_PASSWORD_FILE`, `GOOGLE_CLIENT_ID_FILE`, and `GOOGLE_CLIENT_SECRET_FILE`.

## 2. Start The Stack

```bash
./docker/compose-up.sh
```

The `app` container will:

- wait for PostgreSQL on `database:5432`
- ensure `APP_KEY` and persist it under `./.docker-state/app.key` on the Docker host
- apply pending Laravel migrations automatically and bootstrap an empty database when needed
- start the bundled MediaMTX relay
- write the bootstrap marker that unblocks `worker` and `scheduler`

## 3. Confirm Camera Reachability

The `app` container must be able to reach camera HTTP, ONVIF, and RTSP endpoints directly.

The current onboarding flow uses direct ONVIF probes from Camera Fleet, so this is a routed reachability requirement rather than a multicast discovery requirement.

## 4. Verify Startup

```bash
docker compose ps
docker compose logs --tail=100 app web worker scheduler
docker compose exec app php artisan relay:status
```

What you should see:

- `database`, `app`, `web`, `worker`, and `scheduler` running
- `app`, `web`, `worker`, and `scheduler` healthy once startup settles
- MediaMTX installed, running, and API reachable from the `app` container

## 5. Useful Runtime Commands

```bash
docker compose exec app php artisan relay:sync
docker compose exec app php artisan relay:start
docker compose exec app php artisan relay:status
docker compose exec app php artisan camera-recordings:tick
docker compose exec app php artisan camera-recordings:prune
docker compose exec app php artisan camera-recordings:build-review-assets --missing
docker compose exec app php artisan test
```

## Notes

- The worker pool is Docker-managed. Do not install host cron jobs or systemd units for queue work.
- `./docker/compose-up.sh` scales the `worker` service to the same value as `CAMERA_RECORDING_WORKER_PROCESSES`.
- Keep `./.docker-state/app.key` with the deployment. If that file is lost while the database still contains encrypted values, Laravel will no longer be able to decrypt them.
- The bundled PostgreSQL service stays internal to the Compose network by default.
- The relay, database, and Laravel services communicate through Docker DNS names such as `app`, `web`, and `database`.