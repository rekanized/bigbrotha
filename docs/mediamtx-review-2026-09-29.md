# MediaMTX and streaming review — 29 September 2026

## Scope

Reviewed Docker packaging, generated MediaMTX configuration, camera source ingestion, FFmpeg copy/transcode paths, relay authentication, WebRTC/WHEP delivery, browser playback, recording interactions, and the legacy MJPEG and fragmented MP4 endpoints. Changes were deployed only to the `bigbrotha` test stack at `https://monitor-test.schollinetz.com`; `actualbigbrotha` production containers were not recreated.

## Changes

| Area | Change | Reason |
| --- | --- | --- |
| PHP and Composer | PHP 8.5 image, PHP 8.5 Composer platform, current compatible locked dependencies, PHPUnit 13 | Keep the runtime and test tooling supported and current. |
| MediaMTX | Pin 1.21.1 with verified amd64 and arm64 release checksums | Pick up current relay fixes, including the upstream WHEP/WebRTC and Origin handling fixes. |
| Legacy HTTP streams | MJPEG and fragmented MP4 read an already active canonical MediaMTX source path using the internal RTSP reader credentials; use the camera URI when that source is inactive | Avoid opening another hardware RTSP session for a feed that the relay is already ingesting. |
| Docker development | Add a bind-mounted development override with its own image target and writable dependency/cache volumes; keep the default deployment image based | Let source edits be tested without changing the immutable deployment behavior. |

The existing architecture already uses one on-demand canonical RTSP source per camera/profile and one shared live copy/transcode publisher per live path. Browser readers receive short-lived, path-specific Laravel-signed tokens. The relay uses Laravel's HTTP auth callback for WebRTC reads and restricted internal RTSP credentials for publishing and reading. WebRTC is exposed through the same-origin reverse proxy, with the ICE UDP/TCP port published separately. The generated config disables unused RTMP/HLS protocols, uses RTSP over TCP, and validates under MediaMTX 1.21.1.

The browser player already staggers initial connections, releases hidden/off-screen receivers, retries failed sessions with bounded backoff, and checks frame progress rather than treating an established connection as proof of playback. FFmpeg copies compatible H.264 where possible and transcodes incompatible video once per active live path; source recording and preview consumers can share the canonical source. The legacy fallback still may use another direct camera connection when the canonical source is idle; this preserves the existing endpoint behavior without forcing an otherwise idle relay path to start.

## Verification

- Composer package audit reported no advisories and the locked package list had no available compatible updates at review time.
- Full PHP 8.5 Docker suite: **331 passed, 1,776 assertions**. Tests include the relay-source and direct-camera cases for legacy streaming.
- Final image tag: `rekanized/bigbrotha-app:20260929-php85-stream-review`.
- The test app, background worker, database, and relay health checks passed. The app reports PHP 8.5.11 and Laravel 13.34.0; the relay reports MediaMTX 1.21.1. Its actual generated configuration passed `mediamtx --validate-conf`.
- Public test-site `/login` and MediaMTX `reader.js` both returned HTTP 200. A headless Chromium WHEP reader received two tracks, decoded and presented 42 video frames, and reported a 1280-pixel video width without a client error during the final smoke test.
- Production `actualbigbrotha` container IDs, images, and running states matched the pre-deployment snapshot.

## Performance and remaining limits

The canonical source design and the legacy endpoint change limit duplicate camera sessions. They do not guarantee stall-free video: the earlier [live streaming audit](live-streaming-audit.md) measured multi-second packet delivery gaps before application processing on an IMOU source, and the [live-wall audit](live-wall-audit.md) recorded shorter test-camera freezes even when receivers stayed connected. The short browser check establishes working playback after this upgrade, not sustained throughput or latency across all cameras and browsers. Investigating camera/Wi-Fi conditions and measuring a longer multi-camera session remain necessary before claiming consistently smooth playback.

## References

- [MediaMTX 1.21.1 release notes](https://github.com/bluenviron/mediamtx/releases/tag/v1.21.1)
- [MediaMTX configuration reference](https://github.com/bluenviron/mediamtx/blob/main/mediamtx.yml)
- [Laravel 13 release notes](https://laravel.com/docs/13.x/releases)
- [PHP 8.5 release notes](https://www.php.net/releases/8.5/en.php)
