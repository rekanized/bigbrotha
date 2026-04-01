# BigBrothas

BigBrothas is a Laravel 13 operator-facing web application for ONVIF and RTSP camera operations. The current platform supports camera discovery, direct ONVIF verification, camera fleet management, RTSP profile retrieval, backend stream diagnostics, preview capture, and shared WebRTC wall playback through MediaMTX.

The Live Wall now uses a shared MediaMTX relay for WebRTC playback, while still exposing a no-transcode copy relay path that remuxes camera video with ffmpeg stream copy instead of re-encoding it. Operator access is expected to be authenticated through Google OAuth in Laravel, and Live Wall playback now uses Laravel-issued short-lived MediaMTX read tokens instead of the stock public iframe player.

When deploying behind Nginx, Laravel must trust the proxy headers and MediaMTX must be given a public WebRTC URL plus reachable ICE addresses. See the proxy notes in the docs before publishing the wall through a reverse proxy.

## Stack

- Laravel 13 on PHP 8.3.
- Blade plus Livewire 4 for the operator UI.
- Laravel Socialite for Google sign-in.
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

- `/login` Google sign-in entry.
- `/` dashboard.
- `/camera-fleet` camera inventory and management.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` private preview image endpoint.
- `/live-wall` live wall entry.
- `/live-wall/{camera}/player` Laravel-served secure player page.
- `/live-wall/{camera}/session` Laravel-authenticated WebRTC bootstrap endpoint.
- `/live-wall/{camera}/stream` legacy MJPEG live endpoint.
- `/live-wall/{camera}/relay` copied live relay endpoint.
- `/relay/auth/mediamtx` MediaMTX HTTP auth callback.
- `/__webrtc/{camera-path}/` proxied MediaMTX WebRTC player path when deployed behind Nginx.
- `/discovery/onvif-sweep` ONVIF discovery and manual endpoint probing.

## Live Wall Flow

1. An operator signs in through Google OAuth and receives a normal Laravel session.
2. `/live-wall` and `/live-wall/{camera}/player` render Laravel-owned player shells instead of exposing the stock public MediaMTX iframe page.
3. The browser calls `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token plus the WHEP URL for the selected camera path.
4. `public/js/live-wall-player.js` loads the official per-path MediaMTX `reader.js` and passes the signed token as a bearer token.
5. MediaMTX calls `/relay/auth/mediamtx` before allowing a WebRTC read.
6. If the path is idle, MediaMTX starts ffmpeg through `runOnDemand`, ffmpeg pulls the selected camera RTSP URI, transcodes to browser-safe H.264 baseline, and republishes locally to `rtsp://publisher:...@127.0.0.1:8554/$MTX_PATH`.
7. The auth callback accepts that local RTSP publish only when it matches the configured internal publisher credentials and comes from loopback.

## Relay Settings

Production deployments should set these values explicitly:

- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `MEDIAMTX_WEBRTC_PUBLIC_URL`
- `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`
- `MEDIAMTX_AUTH_CALLBACK_URL`
- `MEDIAMTX_AUTH_CALLBACK_SECRET`
- `MEDIAMTX_AUTH_TOKEN_SECRET`
- `MEDIAMTX_PUBLISHER_USER`
- `MEDIAMTX_PUBLISHER_PASS`

After changing relay or auth-related environment values, run:

```bash
php artisan config:clear
php artisan view:clear
php artisan relay:sync
```

In normal operation this is enough. A `php-fpm` restart is only needed if the host is serving stale PHP bytecode through OPcache.

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
php artisan config:clear
php artisan view:clear
php artisan relay:sync
php artisan relay:start
php artisan relay:status
```

## Resume Guidance

When resuming work, start with `AGENTS.md` and the docs listed above. They capture the camera platform architecture, discovery behavior, fleet workflow, preview storage layout, and current operational constraints.
