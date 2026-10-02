# BigBrotha agent guide

BigBrotha is a Laravel 13 / PHP 8.5 camera operations app. Operators onboard ONVIF or RTSP cameras, test streams, configure recording and motion rules, review footage, and watch named multi-camera walls. The supported deployment is a four-service Docker Compose stack: `database` (PostgreSQL 18), `app` (Nginx, PHP-FPM, Laravel), `background` (scheduler and queue workers), and `relay` (MediaMTX). The app image also contains ffmpeg and ffprobe.

Read this map first, then load only the relevant project skill in `.agents/skills/`. For fuller detail, use [README.md](README.md), [docs/video-platform-architecture.md](docs/video-platform-architecture.md), [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md), and [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md). The older `UI-rules.md` reference was stale; that file is absent. Current UI rules are in the operator UI skill, existing styles, and `.github/copilot-instructions.md`.

## Find the change site

| Concern | Entry point and related code |
| --- | --- |
| HTTP and access control | `routes/web.php`; middleware aliases, setup gate, IP gate, proxy trust, and `/up` in `bootstrap/app.php`; controllers in `app/Http/Controllers/` |
| Scheduled commands | `routes/console.php`; standalone `app/Console/Commands/SampleCameraMotion.php` |
| Camera intake | `app/Livewire/CameraFleet/Manager.php` and paired view; `app/Services/CameraFleet/` and `app/Services/Onvif/` |
| Live wall | `app/Livewire/LiveWall/TilesManager.php`, `app/Http/Controllers/LiveWall*`, `app/Services/Relay/`, `app/Services/CameraLiveStreamService.php`, `public/js/live-wall-player.js` |
| Recordings and timeline | `app/Services/CameraRecordingService.php`, segmenter/motion/review/storage services, `app/Jobs/`, `app/Http/Controllers/RecordingController.php`, `app/Livewire/Recordings/` |
| Authentication and admin | `app/Services/AuthenticationSettingsService.php`, `app/Services/LocalAuthenticationService.php`, `app/Http/Controllers/Auth/`, `app/Livewire/Setup/`, `app/Livewire/Admin/` |
| Data | `app/Models/`, `database/migrations/`, `database/seeders/`, `config/`; `app/Providers/` applies database-backed settings and configures camera storage |
| UI | `resources/views/layouts/app.blade.php`, page and Livewire views, direct assets in `public/css/` and `public/js/` |
| Containers and release | `docker-compose.yml`, development/build overlays, `Dockerfile`, `docker/` role scripts, `publish.sh` |
| Verification | `tests/Feature/`, `tests/Unit/`, `tests/Browser/`, `phpunit.xml` |

There is no separate API route file or JSON API subsystem. A few authenticated web routes return JSON for live sessions, motion analysis, and timeline data. There is no CI workflow in `.github/workflows/`; release testing is in `publish.sh` and the Dockerfile `test` target. Historical investigations under `docs/` are evidence, while the linked docs above describe the current workflow.

## Load a focused project skill

Skills are repository files for future agents; read the `SKILL.md` for the area you are changing.

| Skill | Load when |
| --- | --- |
| [camera-fleet](.agents/skills/camera-fleet/SKILL.md) | Changing camera drafts, ONVIF/RTSP discovery, diagnostics, profiles, or previews |
| [live-relay](.agents/skills/live-relay/SKILL.md) | Changing wall selection, MediaMTX paths/auth, WebRTC, ffmpeg live output, or proxy behavior |
| [recording-storage](.agents/skills/recording-storage/SKILL.md) | Changing recording schedules, jobs, motion, review, retention, files, or SMB |
| [data-model](.agents/skills/data-model/SKILL.md) | Changing migrations, model relationships, seeders, or stored timestamps |
| [auth-admin](.agents/skills/auth-admin/SKILL.md) | Changing setup, local/Google sign-in, roles, allowlist, settings, audit, or web restrictions |
| [operator-ui](.agents/skills/operator-ui/SKILL.md) | Changing Blade, Livewire views, CSS, JavaScript, or page layout |
| [docker-runtime](.agents/skills/docker-runtime/SKILL.md) | Changing Compose, Dockerfile, startup, deployment, secrets, or health checks |
| [verification](.agents/skills/verification/SKILL.md) | Selecting tests or validating a change in this no-build repository |

## Working rules

- Composer is the only dependency manager. Do not add npm, Node tooling, Vite, Webpack, Tailwind, Bootstrap, Sass, Less, or a frontend build step. Render with Blade and Livewire 4; use plain CSS and browser JavaScript in `public/`.
- Make behavior changes in the owning service, controller, component, config, or route. Keep ffmpeg/MediaMTX rules out of views. Add a migration for persisted schema changes; do not edit an already-applied migration to evolve a deployment.
- `Camera` is the network/profile/policy aggregate. `CameraRecording` stores durable segment state. `CameraMotionState` tracks active motion events. `LiveWall` and `LiveWallTile` store layouts. `AppSetting`, `User`, `AllowedLoginEmail`, and `AuditLog` cover configuration and operator access. See migrations for fields and model casts/helpers for behavior.
- Source credentials and private media must stay out of public responses, logs, and committed docs. `Camera.password` is encrypted; Google and SMB secrets are database-backed. Audit snapshots deliberately exclude sensitive fields. Relay tokens are short lived and scoped to one path.
- Use `config/*.php` as the source of environment defaults. The image supplies production defaults for PostgreSQL, database queue/cache/session, logging, and relay callback URL; `.env.docker.example` contains deployment values. `APP_URL` must be the public origin. Do not cache merged Laravel configuration: providers apply database-backed authentication and SMB settings at runtime.
- Do not edit `vendor/`, `bootstrap/cache/*.php`, `storage/`, `.env`, `.env.docker`, `.docker-state/`, generated relay YAML, or built image contents as source. They contain generated state, media, or secrets. `composer.lock` changes with Composer dependency updates only. `public/js/vendor/sortable.min.js` is a vendored browser asset.
- Update relevant `docs/` files when architecture, storage, routes, workflows, or deployment behavior change. Keep `AGENTS.md` a map; put domain detail in the skill and existing focused docs.

## Development and deployment

The normal production path uses published images and `./docker/compose.sh`. Run `./docker/compose.sh init` to create private `.env.docker` configuration and generate `DB_PASSWORD` without starting containers. Set `APP_URL`, then `./docker/compose.sh start` pulls images and waits for four healthy services. Use `--local start` to build from source and `--dev start` for development; add the same mode to later Compose commands. The older shortcuts still work. Raw Compose requires `--env-file .env.docker` on every call. See [docs/startup-from-scratch.md](docs/startup-from-scratch.md).

For source edits, `./docker/compose-dev.sh up -d --build` uses `docker-compose.dev.yml` over the base file. It bind mounts this checkout into `app` and `background`, with separate `dev-vendor` and `dev-cache` volumes. Subsequent edits to PHP, Blade, CSS, and JS do not require an image rebuild; image dependency/runtime changes do. The dev image includes Composer and SQLite support. The host PHP on this checkout may not match required PHP 8.5, so use the container for Artisan and tests. `./docker/compose-up.sh` builds the production image locally using `docker-compose.build.yml`. `publish.sh` builds/runs the `test` target, validates the final image, then publishes immutable and optionally `latest` Docker Hub tags; publication changes a remote registry and requires explicit task authorization.

Useful read-only checks:

```bash
./docker/compose-dev.sh ps
./docker/compose-dev.sh logs --tail=100 app background relay
./docker/compose-dev.sh exec app php artisan route:list
./docker/compose-dev.sh exec app php artisan schedule:list
./docker/compose-dev.sh exec app php artisan relay:status
./docker/compose-dev.sh exec app php artisan migrate:status
```

The `app` startup applies pending migrations, syncs relay config, and warms route/view/event caches in production. It protects an initialized database with a marker if the schema suddenly looks empty. `background` waits for app bootstrap, then Supervisor runs one scheduler and `CAMERA_RECORDING_WORKER_PROCESSES` workers. Worker queues are `recordings,default,review-assets`; the default queue/cache/session drivers in the image use PostgreSQL. Scheduled tasks in `routes/console.php` refresh previews, tick/prune recordings, build review assets, retry/prune failed jobs, and prune audit records. Do not run `migrate:fresh`, seeding, pruning, queue retry, or purge commands against real data just to verify a documentation change.

Persistent state: Compose `db-data` mounts at `/var/lib/postgresql` (PostgreSQL 18 layout); `app-storage` mounts at `/app/storage` across app/background/relay; ignored `./.docker-state` mounts at `/app/bootstrap-persist` and holds the durable encryption key. Losing that key makes encrypted database settings and camera passwords unreadable. Only the web and WebRTC ICE ports publish to the host. The relay API and RTSP listener use internal Docker DNS. The host and `app` container must reach camera networks; HTTP IP allowlisting does not provide that reachability. Nginx proxies `/__webrtc/` signaling, while ICE TCP/UDP uses `MEDIAMTX_ICE_PORT` directly.

## Data and checks

Schema lives in `database/migrations/`; `database/seeders/DatabaseSeeder.php` calls the auth, network storage, and sample fleet seeders. Those seeders respond to `SEED_*` inputs and local mode, so inspect them before running `db:seed`. Production migrations run at app startup; use `./docker/compose-dev.sh exec app php artisan migrate:status` for inspection and `php artisan migrate` only on an intended database. `db:import-sqlite` in `routes/console.php` is a destructive legacy import into the active PostgreSQL connection.

Run focused PHPUnit tests in the development image, then broaden if the change crosses boundaries:

```bash
./docker/compose-dev.sh exec app php artisan test --filter=CameraFleetManagerTest
./docker/compose-dev.sh exec app php artisan test
./docker/compose-dev.sh exec app vendor/bin/pint --test
```

`phpunit.xml` uses in-memory SQLite, sync queues, and array cache/session. The production image installs without dev packages, so it is not the test runner. `tests/Browser/` contains browser-invoked JavaScript checks and is not part of PHPUnit or a configured Node runner. Pint is the configured PHP formatter; no PHP static-analysis configuration or CI pipeline is present. Use `vendor/bin/pint --test` to inspect formatting before applying `vendor/bin/pint` to touched PHP files. Check `git diff --check`, targeted tests, and any relevant Compose config/health command before considering a change complete. Document test limits when a live camera, SMB share, Google callback, or public ICE path was not available.

For troubleshooting, start with `./docker/compose-dev.sh logs --tail=100 app background relay` or the production wrapper equivalent, then Laravel logs under `storage/logs/` if the configured channel writes there. Use `relay:status`, `camera-recordings:healthcheck {role}`, and the admin job queue or audit log to narrow faults. Consult [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md) for camera reachability, SMB requirements, codec limits, proxy headers, and WebRTC ICE behavior.
