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

Requires Git, Docker with Compose, and network access from the host and containers to your cameras.

```bash
git clone https://github.com/rekanized/bigbrotha.git
cd bigbrotha
./docker/compose.sh init
```

Edit the generated `.env.docker`: set `APP_URL` to your public origin without a trailing slash (for example, `http://192.168.1.10:8082` or `https://cameras.example.com`). The database password is generated automatically.

```bash
./docker/compose.sh start
```

This pulls published images and waits for all four services to be healthy. Open `${APP_URL}/setup` in your browser to configure sign-in and your first administrator, then add cameras.

Before completing setup, retrieve its private authorization token:

```bash
./docker/compose.sh exec app php artisan setup:token
```

Enter that token in the setup wizard. For a source build, use the same `--local` mode for this command. Production startup creates no sample cameras or default administrator.

- Default ports: HTTP **8082**, WebRTC ICE **8190 TCP/UDP**. Keep ICE reachable by browsers; an HTTP reverse proxy alone is insufficient.
- For multiple installations, set a unique `COMPOSE_PROJECT_NAME`, `WEB_PORT`, and `MEDIAMTX_ICE_PORT` in each `.env.docker`.
- Back up the database/storage volumes and `.docker-state/app.key`; the key is required to decrypt saved credentials.
- Use HTTPS for internet-facing installations, scope `TRUSTED_PROXIES` to your actual reverse proxy, and restrict direct access to the backend HTTP port. Database and relay management ports remain internal.

## Updates and troubleshooting

```bash
./docker/compose.sh start                         # Update published images
./docker/compose.sh ps                            # Check service health
./docker/compose.sh logs --tail=100 app background relay
```

## Build from source

Use `./docker/compose.sh --local start` to build and run this checkout. Re-run it after changes. Use `--dev start` instead for development with live source mounts; retain the chosen mode for later commands (`--local ps`, `--dev logs`, etc.).

[Full setup and proxy configuration](docs/startup-from-scratch.md) · [Backup and restore](docs/backup-and-restore.md) · [Camera workflow](docs/camera-fleet-workflow.md) · [Troubleshooting](docs/known-issues-and-constraints.md)

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the Docker-only development and test workflow, and [SECURITY.md](SECURITY.md) for private vulnerability reporting. GitHub checks run isolated tests, dependency and credential scans, formatting, and production-image validation. CSS and browser JavaScript are served directly; no frontend build is required.

Licensed under [MIT](LICENSE). Bundled third-party components retain their own licenses; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
