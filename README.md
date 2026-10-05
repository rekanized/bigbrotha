# BigBrotha

Docker-first camera management and recording. Docker Compose runs the app, PostgreSQL, background workers, and MediaMTX relay.

## Support

Help support me: [Buy me a coffee](https://buymeacoffee.com/rekanized).

## Features

- ONVIF and RTSP cameras, automatic ONVIF stream-profile retrieval, connection tests, and preview thumbnails.
- Named multi-camera live walls with customizable layouts and WebRTC playback.
- Continuous or motion-triggered recording with painted detection zones and per-camera retention.
- Synchronized multi-camera timeline review, recording playback, and downloads.
- Local or SMB recording storage.
- Local and Google sign-in, administrator controls, and audit logs.

## Setup

Requires Docker with Compose and network access from the host and containers to your cameras. The application and deployment files come from the Docker Hub image `rekanized/bigbrotha-app:latest`.

For an existing deployment, keep its setup with the [existing configuration instructions](docs/startup-from-scratch.md#existing-installations) before switching to plain Compose commands.

For a new installation:

```bash
mkdir bigbrotha
cd bigbrotha
docker pull rekanized/bigbrotha-app:latest
docker run --rm --entrypoint cat rekanized/bigbrotha-app:latest /usr/share/bigbrotha/docker-compose.yml > docker-compose.yml
docker run --rm --entrypoint cat rekanized/bigbrotha-app:latest /usr/share/bigbrotha/.env.example > .env.example
cp .env.example .env
chmod 600 .env
```

Edit `.env`: set `APP_URL` to your public origin without a trailing slash (for example, `http://192.0.2.10:8082` or `https://cameras.example.com`), and set `DB_PASSWORD` to a strong random password. You can generate one with `openssl rand -hex 32` or a password manager.

```bash
docker compose up -d --wait
```

This starts the published images and waits for all four services to be healthy. Open `${APP_URL}/setup` in your browser to configure sign-in and your first administrator, then add cameras.

Before completing setup, retrieve its private authorization token:

```bash
docker compose exec app php artisan setup:token
```

Enter that token in the setup wizard. For a source build, include the same Compose override files in this command. Production startup creates no sample cameras or default administrator.

- Default ports: HTTP **8082**, WebRTC ICE **8190 TCP/UDP**. Keep ICE reachable by browsers; an HTTP reverse proxy alone is insufficient.
- For multiple installations, set a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT` in each `.env`.
- Back up the database/storage volumes and `.docker-state/app.key`; the key is required to decrypt saved credentials.
- Use HTTPS for internet-facing installations, scope `TRUSTED_PROXIES` to your actual reverse proxy, and restrict direct access to the backend HTTP port. Database and relay management ports remain internal.

## Updates and troubleshooting

```bash
docker compose pull                              # Fetch updated published images
docker compose up -d --wait --remove-orphans       # Apply updates
docker compose ps                                # Check service health
docker compose logs --tail=100 app background relay
```

## Build from source

For source builds, install Git and clone the repository into a separate directory:

```bash
git clone https://github.com/rekanized/bigbrotha.git bigbrotha-source
cd bigbrotha-source
cp .env.example .env
chmod 600 .env
```

Set `APP_URL` and `DB_PASSWORD` in `.env`. Choose a unique project name and ports if another installation runs on the same host, then build and start:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build --wait
```

For development with live source mounts, use `docker-compose.dev.yml` instead of `docker-compose.build.yml`. Include the same Compose files for later commands such as `ps`, `logs`, and `exec`.

[Full setup and proxy configuration](docs/startup-from-scratch.md) · [Backup and restore](docs/backup-and-restore.md) · [Camera workflow](docs/camera-fleet-workflow.md) · [Troubleshooting](docs/known-issues-and-constraints.md)

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the Docker-only development and test workflow, and [SECURITY.md](SECURITY.md) for private vulnerability reporting. `./publish.sh` runs the test suite and validates the production image before publishing. CSS and browser JavaScript are served directly; no frontend build is required.

Licensed under [MIT](LICENSE). Bundled third-party components retain their own licenses; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
