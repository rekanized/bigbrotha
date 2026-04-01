# BigBrothas

BigBrothas is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Stack

- Laravel 13 on PHP 8.3.
- Blade plus Livewire 4 for the operator UI.
- Standard CSS under `public/css`.
- ffmpeg and ffprobe for RTSP diagnostics and preview generation.
- MediaMTX for shared WebRTC fan-out from RTSP camera sources.

## Important Constraints

- This is a Composer-only project.
- Do not add Node.js tooling, Vite, Tailwind, Bootstrap, Sass, Less, or any build pipeline unless explicitly requested.
- HTTP access restriction is driven by `WEBSITE_ALLOWED_IPS`; an empty value disables the allow list.
- If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers.
- That HTTP restriction does not control ONVIF WS-Discovery multicast behavior.

## Main Routes

- `/` dashboard.
- `/camera-fleet` camera inventory and management.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` private preview image endpoint.
- `/live-wall` live wall entry.
- `/live-wall/{camera}/stream` legacy MJPEG live endpoint.
- `/live-wall/{camera}/relay` copied live relay endpoint.
- `/__webrtc/{camera-path}/` proxied MediaMTX WebRTC player path when deployed behind Nginx.
- `/discovery/onvif-sweep` ONVIF discovery and manual endpoint probing.

## Project Context

- [AGENTS.md](AGENTS.md)
- [docs/video-platform-architecture.md](docs/video-platform-architecture.md)
- [docs/camera-fleet-workflow.md](docs/camera-fleet-workflow.md)
- [docs/known-issues-and-constraints.md](docs/known-issues-and-constraints.md)

## Useful Commands

```bash
php artisan test
php artisan test tests/Feature/CameraFleetManagerTest.php
php artisan test tests/Feature/RtspStreamDiagnosticsServiceTest.php
composer relay:install
php artisan relay:sync
php artisan relay:start
php artisan relay:status
```

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
