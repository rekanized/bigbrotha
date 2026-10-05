# Deployment constraints and troubleshooting

## Supported runtime

Use the four-service Docker Compose stack. Host PHP is not the supported test
runtime. PostgreSQL is the deployment database; SQLite is included for isolated
tests and development.

The frontend uses Blade, Livewire, plain CSS, and browser JavaScript. There is no
Node package manager, Vite/Webpack pipeline, CSS preprocessor, or asset build.

## Access and authentication

- Complete initial setup with the private token from `php artisan setup:token`.
  Startup creates no default administrator or sample cameras in production.
- Set `APP_URL` to the exact public origin, including a directly mapped HTTP port.
  Requests must match its host; generated URLs use the configured origin.
- Use HTTPS for internet-facing deployments and configure `TRUSTED_PROXIES`
  narrowly. Forwarded client information is trusted only from those addresses.
- `WEBSITE_ALLOWED_IPS` restricts incoming HTTP access when populated. It does not
  provide outbound camera-network reachability.
- Google OAuth requires a successful validation round-trip and a matching provider
  callback URL. New Google-only setup must use the verified identity used during
  validation. Email allowlisting does not override an account's Google subject.
- Operators share access to the camera fleet, walls, and recordings. Administration
  is role restricted; this is not a per-camera or multi-tenant permission model.
- Authorization changes reject subsequent requests and new relay authorizations.
  Established media connections are not forcibly terminated by those changes.

The current Livewire/Alpine implementation requires inline scripts and expression
evaluation, so the CSP permits `unsafe-inline` and `unsafe-eval`. Escape dynamic
content and review changes to browser script sources carefully.

## Camera network and codecs

The `app` container must reach camera HTTP, ONVIF, and RTSP endpoints. Check the
address, device-service path, credentials, routing, and firewall rules when a probe
fails. Direct ONVIF intake does not depend on multicast discovery.

Some cameras omit optional ONVIF operations or permit only one RTSP reader.
BigBrotha shares canonical relay sources where possible and supports manual
RTSP-only configuration. Supported camera HTTPS probes can accept self-signed
certificates; use trusted routes and isolate camera networks.

H.264 with detected B-frames and incompatible source codecs require live
transcoding. This increases CPU demand. Camera firmware and upstream network
interruptions can still cause gaps; application buffering cannot guarantee smooth
playback when the source stops delivering packets.

## WebRTC and reverse proxies

The web reverse proxy handles HTTP and WHEP signaling through `/__webrtc/`.
Browsers also need access to `MEDIAMTX_ICE_PORT` over TCP/UDP. Exposing HTTPS alone
is insufficient. If that port cannot be reached, additional ICE/TURN infrastructure
may be required for the network arrangement.

If WHEP `PATCH` or `DELETE` requests lose the `/__webrtc/` prefix, check the Nginx
proxy block and `X-Forwarded-Prefix`. Keep relay APIs and RTSP listeners internal.

If FFmpeg publishing fails with `ANNOUNCE`/HTTP 401, check that the relay callback
and derived publisher credentials use the same application key and configuration.
Do not expose those credentials in logs or support requests.

## Storage and recovery

Private previews, review assets, buffers, and staging remain local. Only durable
recording clips use an enabled SMB destination. Configure a dedicated camera root
such as `//fileserver/share/cameras`; the application creates nested recording
folders within it.

A share must be reachable from both application and background containers and
allow the configured account to create, read, rename, and delete files. Test
recording and playback before relying on it. A network failure must not be treated
as proof that stored footage is missing. See [recording storage](recording-storage.md).

Retain the database, private storage, and `.docker-state/app.key` together. Losing
the key makes encrypted settings and passwords unreadable. Back up the external
recording tree separately when SMB is enabled. See [backup and restore](backup-and-restore.md).

## Diagnosing application health

```sh
docker compose ps
docker compose logs --tail=100 app background relay
docker compose exec app php artisan relay:status
docker compose exec app php artisan migrate:status
```

Use the same Compose files as the running installation. Inspect the administrator job
queue and audit log to narrow capture, publication, and permission failures. Healthy
services do not prove camera reachability, remote storage access, or browser ICE
connectivity. Do not run destructive migration, seeding, pruning, or purge commands
as a troubleshooting shortcut.

The background service may take several minutes to stop while existing jobs
finish. The Compose shutdown budget matches Supervisor's worker and scheduler
budgets; custom job timeouts need corresponding changes to both.
