---
name: docker-runtime
description: Work on BigBrotha Docker Compose, image building, local development, startup, deployment, persistence, and health checks.
---

# Docker runtime

Read [startup](../../../docs/startup-from-scratch.md) for deployment steps. Verify current Compose state with `APP_URL=https://example.invalid DB_PASSWORD=validation-only docker compose --env-file .env.docker.example -f docker-compose.yml config --services`; add `-f docker-compose.dev.yml` or `-f docker-compose.build.yml` for the respective overlay.

## Topology

- `docker-compose.yml` defines `database`, `app`, `background`, `relay`. `docker/compose.sh` consistently passes the selected `.env.docker` and project directory, creates/generates the local DB credential file when needed, and forwards Compose arguments. `docker/compose-dev.sh` adds the development overlay; `docker/compose-up.sh` adds the build overlay.
- `Dockerfile` has `mediamtx`, `runtime`, `vendor-production`, `application`, `test`, `development`, and `final` stages. The published app image contains Nginx, PHP-FPM, ffmpeg/ffprobe, MediaMTX, and production Composer packages. The test/dev stages include dev packages and SQLite support. Source changes are baked into production but bind mounted in development.
- `docker/entrypoint.sh` waits for PostgreSQL, persists `APP_KEY`, applies migrations only after checking the initialization marker, clears config cache, warms safe production caches, and syncs relay config. `run-app` supervises Nginx/PHP-FPM. `run-background` supervises a scheduler and `CAMERA_RECORDING_WORKER_PROCESSES` workers. `run-relay` starts MediaMTX with generated YAML. `docker/healthcheck*.sh` checks each role.
- Compose named `db-data` mounts at PostgreSQL 18's `/var/lib/postgresql`; `app-storage` is shared at `/app/storage`. Ignored host `.docker-state` mounts at `/app/bootstrap-persist` and holds the encryption key. Dev adds source bind mounts and `dev-vendor` / `dev-cache` volumes. Protect all persistent volumes and the key across upgrades.

## Operations

`APP_URL`, `DB_PASSWORD`, `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT` are key deployment values. Use `.env.docker.example` for names and `config/*.php` for runtime defaults; never print or commit values from `.env.docker` or `.docker-state`. The app/relay use Docker DNS: `database`, `app`, `relay`. App HTTP publishes via `WEB_PORT`; ICE TCP/UDP via `MEDIAMTX_ICE_PORT`. Nginx proxies `/__webrtc/` signaling.

`./docker/compose-dev.sh up -d --build` is the source-editing path; later `up -d` reuses the image. `./docker/compose.sh pull` and `up -d --remove-orphans` are image deployment commands. `publish.sh` performs a test image run and remote Docker Hub publication; do not invoke for routine validation. Avoid old PostgreSQL volume mount layouts; `docker/migrate-postgres-18-volume.sh` and `docker/rotate-db-password.sh` are guarded operational scripts, not general test commands.

For failure diagnosis: `./docker/compose.sh ps`, `logs --tail=100 app background relay`, `exec app php artisan relay:status`, and targeted health checks. The app can be healthy while relay playback fails; inspect both roles.
