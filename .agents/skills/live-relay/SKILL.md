---
name: live-relay
description: Change BigBrotha wall layout, MediaMTX path generation, live WebRTC playback, relay authentication, or proxy behavior.
---

# Live relay

Read [the architecture](../../../docs/video-platform-architecture.md) for the full path diagram and [known limits](../../../docs/known-issues-and-constraints.md) for codec and proxy cases.

## Request and media path

1. `/wall-tiles` uses `LiveWall/TilesManager.php` and the `LiveWall` / `LiveWallTile` models. `LiveWallController` selects a saved active wall and its enabled camera tiles.
2. `CameraLiveStreamService` selects a wall profile. `LiveWallPlayerController` renders a Laravel shell; `LiveWallSessionController` checks relay health and returns a path-specific WHEP URL, MediaMTX reader URL, and short-lived signed read token.
3. `public/js/live-wall-player.js` uses MediaMTX `reader.js`, manages reconnection and video-stall detection. `MediaMtxAuthController` accepts browser WebRTC reads only after `MediaMtxAccessTokenService` validates the token. Internal RTSP readers/publishers use separate derived credentials plus allowed IPs.
4. `MediaMtxConfigService` builds config from enabled camera profiles and `MediaMtxPathNamer` names canonical `camera-{id}-source[-profile-{n}]` paths. Derived live paths read the internal source. `runOnDemand` ffmpeg opens the hardware camera once, then republishes locally. Browser-safe H.264 can copy; HEVC, H.264 with detected B-frames, or forced compatibility cases transcode. Audio is converted to Opus.

`MediaMtxProcessService` syncs generated YAML under `storage/app/private/mediamtx/` and reports status. In Compose, MediaMTX runs as the separate `relay` service; `app` Nginx proxies `/__webrtc/` signaling. The API and RTSP ports remain internal. WebRTC ICE uses the published `MEDIAMTX_ICE_PORT` TCP/UDP (default 8190); HTTPS signaling alone is insufficient.

## Change and verify

- Treat `config/mediamtx.php` as the default/override map. `APP_URL` determines the public `/__webrtc` URL; `TRUSTED_PROXIES` and forwarded prefix/Location rewriting matter behind an outer proxy. Relay secrets derive from `APP_KEY` unless overridden.
- Keep the callback `/relay/auth/mediamtx` reachable from relay and CSRF-exempt. Never expose publisher credentials or session tokens in logs or views. Avoid rebuilding relay config on each tile reconnect; the session endpoint intentionally uses a cheap status check.
- After changing path/auth/config behavior, run focused `tests/Feature/Relay/*` and `tests/Feature/LiveWallStreamTest.php`. Inspect `php artisan relay:status` and `app`/`relay` logs. If WHEP succeeds but video stalls, check ICE reachability and camera/codec diagnostics before changing UI code.
