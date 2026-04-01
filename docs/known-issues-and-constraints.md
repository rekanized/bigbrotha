# Known Issues And Constraints

## Frontend Constraint

This repository is intentionally no-build.

Do not add:

- npm or npx.
- Vite, Webpack, or other frontend build pipelines.
- Tailwind, Bootstrap, Sass, Less, PostCSS, or similar preprocessors.

Use Blade, standard CSS under `public/css`, and plain JavaScript only when necessary.

## HTTP Access Restriction

`App\Http\Middleware\RestrictWebsiteIp` is now driven by `WEBSITE_ALLOWED_IPS`.

This affects:

- dashboard access.
- Camera Fleet pages.
- preview routes.
- ONVIF Sweep pages.

This does not affect UDP multicast WS-Discovery.

If `WEBSITE_ALLOWED_IPS` is blank, the HTTP restriction is effectively disabled.

If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers before evaluating the allow list.

## Application Authentication

Operator pages are expected to sit behind Laravel session authentication.

Current design assumptions:

- Google OAuth is the primary sign-in mechanism.
- MediaMTX WebRTC reads are authorized through Laravel with short-lived signed tokens.
- The MediaMTX HTTP auth callback must remain reachable from the relay process and must be exempt from CSRF protection.
- The callback should be protected by a shared secret query parameter or loopback-only access.
- The internal ffmpeg publisher used by MediaMTX `runOnDemand` needs its own RTSP credentials through `MEDIAMTX_PUBLISHER_USER` and `MEDIAMTX_PUBLISHER_PASS`.

## Relay Process Detection

`MediaMtxProcessService` cannot rely only on `kill -0` or `posix_kill(pid, 0)` for liveness checks.

On this host, PHP-FPM runs as `www-data` while MediaMTX may be started by another user. In that situation, signal-based liveness checks can fail with `EPERM` even though the relay is still running.

Current expectation:

- relay detection should reconcile stale pid files.
- relay detection should validate the expected MediaMTX binary and config path from process arguments.
- secure player bootstrap should use `ensureRunning()` rather than a raw status check so the relay can self-heal before returning `503`.

## ONVIF Discovery Reality

`OnvifWsDiscoveryService` has already been hardened to:

- probe from detected LAN IPv4 addresses.
- send typed and untyped probes.
- parse namespace variants.

If discovery still returns no devices, likely causes are outside Laravel:

- multicast blocked by network topology.
- VLAN or router behavior.
- host network restrictions.
- camera-side discovery disabled.
- devices not responding on the current segment.

The practical fallback is the manual ONVIF probe flow on `/discovery/onvif-sweep`.

## Preview Storage History

The current preview layout is:

`storage/app/private/cameras/{id}/previews`

Older `storage/app/private/stream-previews` directories may still remain from previous implementations. Those folders are historical and should not be used for new writes.

## Preview Rendering Behavior

The preview controller validates that a saved preview file is a real image before serving it.

If the file is missing, corrupt, or contains invalid bytes, the route returns a placeholder SVG image instead of a broken image response.

## Media Binary Assumptions

This environment currently uses:

- `/home/administrator/.local/bin/ffmpeg`
- `/home/administrator/.local/bin/ffprobe`

If RTSP diagnostics fail unexpectedly, verify `config/ffmpeg.php` and the bindings in `AppServiceProvider`.

## Live Wall Delivery Tradeoff

The current live wall uses MediaMTX plus WebRTC instead of per-viewer MJPEG.

Implications:

- wall tiles still require ffmpeg to decode and re-encode for browser-safe playback, but only once per active camera path instead of once per viewer.
- the implementation mitigates this by preferring lower-cost RTSP substreams when available.
- MediaMTX requires its own HTTP and ICE ports in addition to the Laravel web port.
- a separate `/live-wall/{camera}/relay` endpoint is available for a no-transcode path that remuxes copied video into fragmented MP4.

If browsers still fail to connect over WebRTC, check `webrtcAdditionalHosts`, `webrtcLocalUDPAddress`, `webrtcLocalTCPAddress`, host firewall rules, and TURN requirements before changing the Laravel UI.

## Secure Live Wall Troubleshooting

If the player stays on `Loading secure stream…`, check these in order:

1. confirm `/live-wall/{camera}/session` returns JSON instead of `503`, `401`, or HTML.
2. run `php artisan relay:status` and confirm MediaMTX is installed, running, and the API is reachable.
3. inspect `storage/logs/mediamtx.log` for these common failure modes:
	- `deadline exceeded while waiting connection`: the browser reached WHEP but ICE did not complete.
	- `closed: source of path ... has timed out`: the camera source did not come up in time.
	- `method ANNOUNCE failed: 401 Unauthorized`: the internal ffmpeg publisher was rejected by MediaMTX auth.
	- `authHTTPAddress is empty`: the auth callback URL resolved to blank config.
4. verify the generated `storage/app/private/mediamtx/mediamtx.yml` contains:
	- the expected `authHTTPAddress` with the callback secret.
	- a `runOnDemand` RTSP publish target that includes the internal publisher credentials.
5. if MediaMTX is behind `/__webrtc/`, verify the Nginx block preserves the prefix on WHEP session `Location` headers.
6. if the relay log shows sessions being created and then timing out, check `8189/udp` and optionally `8189/tcp` reachability before changing Laravel code.
7. after changing `.env` values related to relay auth, run `php artisan config:clear`, `php artisan view:clear`, and `php artisan relay:sync`.
8. if the browser still appears to run old PHP or Blade behavior after cache clears, reload PHP-FPM only as a last resort for stale OPcache.

## Nginx Reverse Proxy Requirements

For a site published at a hostname like `monitor.schollinetz.com`, there are two separate traffic classes:

- Laravel UI HTTP traffic.
- MediaMTX WebRTC traffic.

Recommended setup:

1. proxy the MediaMTX HTTP player and WHEP handshake through Nginx on the same HTTPS origin, for example `/__webrtc/`.
2. expose MediaMTX ICE transport on `8189/udp` and ideally `8189/tcp` to the public internet or upstream load balancer.
3. set `MEDIAMTX_WEBRTC_PUBLIC_URL=https://monitor.schollinetz.com/__webrtc`.
4. set `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS=monitor.schollinetz.com`.
5. set `MEDIAMTX_AUTH_CALLBACK_URL` to the Laravel validation route, typically `https://monitor.schollinetz.com/relay/auth/mediamtx`.
6. set `MEDIAMTX_AUTH_CALLBACK_SECRET` so only MediaMTX can call that validation route.
7. set `TRUSTED_PROXIES` to your Nginx proxy IPs or `*` if you fully trust the proxy layer.

Example Nginx HTTP location for the player and WebRTC handshake:

```nginx
location /__webrtc/ {
	proxy_pass http://127.0.0.1:8889/;
	proxy_http_version 1.1;
	proxy_set_header Host $host;
	proxy_set_header X-Forwarded-Host $host;
	proxy_set_header X-Forwarded-Proto $scheme;
	proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
	proxy_set_header X-Forwarded-Prefix /__webrtc;
	proxy_redirect http://127.0.0.1:8889/ /__webrtc/;
	proxy_redirect ~^(/.+)$ /__webrtc$1;
}
```

If Nginx has the stream module enabled, example TCP and UDP forwarding for ICE on port `8189`:

```nginx
stream {
	server {
		listen 8189 udp;
		proxy_pass 127.0.0.1:8189;
	}

	server {
		listen 8189;
		proxy_pass 127.0.0.1:8189;
	}
}
```

For a same-host deployment where MediaMTX already listens directly on `:8189`, prefer opening that port in the host firewall instead of binding the same public port again through a site-level Nginx configuration file.

Important deployment note:

- `stream {}` cannot be nested inside a normal `server {}` block.
- many distributions load `stream` configuration from a separate top-level include such as `/etc/nginx/nginx.conf` or `/etc/nginx/stream-conf.d/*.conf`.
- if MediaMTX and Nginx run on the same host, the simplest setup is often to let MediaMTX own `8189` directly and use Nginx only for `/__webrtc/` HTTP proxying.

If you cannot expose `8189` at all, WebRTC will usually require a TURN server instead of plain Nginx HTTP proxying alone.

If the iframe URL is under `/__webrtc/...` but the browser console shows `PATCH` or `DELETE` requests failing on `/camera-*-live/whep/...` without the `/__webrtc` prefix, the reverse proxy is not preserving the WebRTC prefix on WHEP session URLs. Check the `/__webrtc/` proxy block, especially `X-Forwarded-Prefix`, before changing Laravel code.

If MediaMTX starts the camera path but the log shows `method ANNOUNCE failed: 401 Unauthorized`, the local ffmpeg publisher credentials are not aligned with the Laravel auth callback. Check `MEDIAMTX_PUBLISHER_USER`, `MEDIAMTX_PUBLISHER_PASS`, clear Laravel config, and re-sync the relay config before debugging WebRTC itself.

## High-Value Tests

When changing this platform, the most relevant tests are:

- `tests/Unit/OnvifWsDiscoveryServiceTest.php`
- `tests/Feature/OnvifDeviceProbeServiceTest.php`
- `tests/Unit/OnvifCameraProvisioningServiceTest.php`
- `tests/Feature/OnvifRtspStreamServiceTest.php`
- `tests/Feature/RtspStreamDiagnosticsServiceTest.php`
- `tests/Feature/CameraFleetManagerTest.php`
- `tests/Feature/LiveWallStreamTest.php`
- `tests/Feature/Relay/MediaMtxAuthCallbackTest.php`
- `tests/Feature/Relay/MediaMtxProcessServiceTest.php`