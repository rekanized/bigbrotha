# Architecture

BigBrotha is a Laravel 13 application running on PHP 8.5. Blade and Livewire 4
render the interface, with plain CSS and browser JavaScript served from `public/`.
Composer is the only dependency manager; there is no frontend compilation step.

## Docker services

The supported deployment consists of four Compose services:

| Service | Responsibility |
| --- | --- |
| `database` | PostgreSQL 18 persistence. |
| `app` | Nginx, PHP-FPM, web requests, and application bootstrap. |
| `background` | Laravel scheduler and queue workers. |
| `relay` | MediaMTX and on-demand FFmpeg streaming processes. |

All application roles use the same image. It includes FFmpeg, ffprobe, MediaMTX,
and `smbclient`. PostgreSQL and relay management endpoints remain on the internal
Compose network. The web port and WebRTC ICE TCP/UDP port are published.

The `app` role applies pending migrations, synchronizes relay configuration, and
warms route, view, and event caches. Configuration is not cached because providers
apply database-backed authentication and storage settings at runtime. Other roles
wait for application bootstrap before starting their workloads.

See [installation](startup-from-scratch.md) for image, build, and development modes.

## Application boundaries

| Area | Main entry points |
| --- | --- |
| HTTP routes and access control | `routes/web.php`, `bootstrap/app.php`, `app/Http/Middleware/` |
| Camera management | `app/Livewire/CameraFleet/Manager.php`, `app/Services/CameraFleet/`, `app/Services/Onvif/` |
| Live walls and playback | `app/Livewire/LiveWall/TilesManager.php`, `app/Http/Controllers/LiveWall*`, `app/Services/Relay/`, `public/js/live-wall-player.js` |
| Recording and review | `app/Services/CameraRecordingService.php`, segmenter and storage services, `app/Jobs/`, `app/Livewire/Recordings/`, `RecordingController` |
| Authentication and administration | `app/Services/AuthenticationSettingsService.php`, `app/Services/LocalAuthenticationService.php`, `app/Http/Controllers/Auth/`, `app/Livewire/Admin/` |
| Scheduled work | `routes/console.php`, `app/Console/Commands/` |

There is no separate JSON API subsystem. Authenticated web routes provide live
sessions, motion analysis, and recording timeline data to browser components.

## Domain models

- `Camera` holds network identity, encrypted credentials, stream profiles, and
  recording/motion policy.
- `CameraRecording` stores durable segment state and file metadata.
- `CameraMotionState` tracks active motion events and recorder state.
- `LiveWall` and `LiveWallTile` store named layouts and camera assignments.
- `AppSetting`, `User`, and `AllowedLoginEmail` store configuration and access policy.
- `AuditLog` stores operator and application events with sensitive fields excluded.

Schema changes use new migrations under `database/migrations/`. Timestamps are
stored in UTC and formatted using application settings.

## Camera intake and live streams

Operators probe an ONVIF device URL or configure an RTSP-only camera manually.
ONVIF discovery retrieves device information, network details, and media profiles.
Diagnostics validate streams and generate private preview images. Saved passwords
are encrypted and never reloaded into the editor; a blank password preserves the
existing credential.

MediaMTX configuration creates a canonical source path for each camera profile.
Derived live and recording consumers reuse that source to limit simultaneous
hardware-camera connections. Live delivery uses WebRTC, with transcoding when the
source codec or frame structure requires it. Nginx proxies WHEP signaling through
`/__webrtc/`; media connectivity also requires the configured ICE port.

Browser receivers retry interrupted sessions and release their connections when
closed. Stream authorization uses short-lived tokens scoped to the account and
one relay path. Account changes affect subsequent authorization requests; an
already established WebRTC connection is not forcibly terminated.

See [camera operations](camera-fleet-workflow.md) and
[deployment constraints](known-issues-and-constraints.md).

## Recording and storage

Continuous and motion recording use shared relay sources and local FFmpeg buffers.
The scheduler synchronizes recorder processes, imports completed segments,
analyzes motion, publishes durable clips, and applies retention policies. Queue
workers handle recording and review-asset jobs.

Motion events preserve configured pre-roll and post-trigger footage. Painted masks
select the pixels evaluated by the detector. The motion editor uses a lease-bound
sampler of the shared recording source and caches decoded frames across settings
changes.

Durable clips can use local private storage or an administrator-configured SMB
share. Previews, review assets, active buffers, and staging remain local. Playback,
review, and downloads use authenticated controllers rather than public file URLs.
See [recording storage](recording-storage.md).

## User interface

Authenticated screens share one navigation definition. At widths of 1181 pixels
and above, navigation appears in a fixed left sidebar. Smaller screens use the
compact header and mobile drawer. Login and setup have their own layouts.

The application shell provides a keyboard skip link, active-page navigation, and
accessible modal controls. Live-wall focus mode temporarily hides navigation and
makes it inert. The wall's bottom dock contains wall and audio controls.

CSS and JavaScript are linked directly from Blade with file-versioned URLs. Icons
use inline SVG and committed [brand assets](brand-assets.md).

## Authentication and persistence

Initial setup requires a private server-side token. Operators can use local
accounts, Google OAuth, or both. Administrative actions require the current stored
administrator role, including Livewire updates. Local sign-in is rate limited, and
password changes invalidate sessions carrying an older password hash.

The public origin comes from `APP_URL`. Trusted proxies must match the deployment.
Private camera credentials, Google/SMB secrets, recording files, and deployment
configuration are excluded from public responses and logs.

Persistent state comprises the PostgreSQL volume, shared private storage, and
`.docker-state/app.key`. The encryption key is required to recover stored secrets.
See [backup and restore](backup-and-restore.md) and [security](../SECURITY.md).
