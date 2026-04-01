# Video Platform Architecture

## Purpose

This application is an operator-facing camera platform for ONVIF and RTSP devices. The implemented foundation covers discovery, provisioning, camera management, RTSP URL retrieval, backend stream checks, preview capture, and shared WebRTC live-wall delivery.

## Runtime Stack

- Laravel 13.
- PHP 8.3.
- Livewire 4 for server-driven UI behavior.
- Blade templates and standard CSS.
- ffmpeg and ffprobe configured through `config/ffmpeg.php` and service bindings in `app/Providers/AppServiceProvider.php`.
- MediaMTX as the shared WebRTC relay managed from Laravel and installed through Composer-driven commands.

## Route Map

- `/` via `App\Http\Controllers\DashboardController`.
- `/camera-fleet` via `App\Http\Controllers\CameraFleetController` and `App\Livewire\CameraFleet\Manager`.
- `/camera-fleet/{camera}/profiles/{profileIndex}/preview` via `App\Http\Controllers\CameraFleetStreamPreviewController`.
- `/live-wall` via `App\Http\Controllers\LiveWallController`.
- `/live-wall/{camera}/stream` via `App\Http\Controllers\LiveWallStreamController@mjpeg`.
- `/live-wall/{camera}/relay` via `App\Http\Controllers\LiveWallStreamController@relay`.
- `/discovery/onvif-sweep` via `App\Http\Controllers\Discovery\OnvifSweepController` and `App\Livewire\Discovery\OnvifSweep`.

## Core Domain Object

`App\Models\Camera` is the central persistence model.

It stores:

- identity and network fields.
- ONVIF and RTSP endpoint parts.
- credentials.
- ONVIF and RTSP capability flags.
- metadata for ONVIF verification and RTSP profiles.
- enable state and last-seen timestamps.

Important model helpers:

- `onvifEndpoint()`.
- `rtspEndpoint()`.
- `rtspProfiles()`.
- `latestRtspPreview()`.

## Discovery And Provisioning

- `App\Services\Discovery\OnvifWsDiscoveryService` handles WS-Discovery multicast probing.
- `App\Services\Onvif\OnvifDeviceProbeService` performs manual authenticated SOAP `GetDeviceInformation` requests.
- `App\Services\Onvif\OnvifCameraProvisioningService` maps verified probe results into `Camera` records.

## Stream Services

- `App\Services\Onvif\OnvifRtspStreamService` retrieves ONVIF media capabilities, profiles, and RTSP stream URIs.
- `App\Services\Onvif\RtspStreamDiagnosticsService` validates RTSP connectivity and captures preview frames.
- `App\Services\CameraStorageService` manages per-camera storage folders.
- `App\Services\CameraLiveStreamService` selects efficient wall profiles, proxies a browser-safe MJPEG live feed, and exposes a copied relay stream without re-encoding the camera video.
- `App\Services\Relay\MediaMtxConfigService` generates MediaMTX paths from enabled cameras.
- `App\Services\Relay\MediaMtxInstaller` downloads the pinned MediaMTX release into private storage.
- `App\Services\Relay\MediaMtxProcessService` syncs config, starts the relay process, and checks relay health.

## Camera Fleet UI

The main operator management surface is `App\Livewire\CameraFleet\Manager` with the Blade view at `resources/views/livewire/camera-fleet/manager.blade.php`.

Current behavior includes:

- create, edit, enable, disable, and delete cameras.
- modal-based editing.
- RTSP profile refresh from ONVIF.
- per-profile RTSP connection testing.
- preview capture and preview display.
- latest preview thumbnail directly in each fleet row.

## Live Wall Delivery

`App\Http\Controllers\LiveWallController` now prepares each enabled camera with a preferred wall profile before rendering the Blade view at `resources/views/live-wall/index.blade.php`.

Current live viewing behavior:

- prefers a lower-cost RTSP profile such as a minor or sub stream when available.
- uses a shared MediaMTX WebRTC relay for operator wall playback.
- transcodes once per active camera into WebRTC-safe H.264 baseline output through ffmpeg `runOnDemand` publishing, instead of one ffmpeg job per viewer.
- embeds the MediaMTX WebRTC player page directly in each wall tile.
- exposes `App\Http\Controllers\LiveWallStreamController@relay` for a no-transcode path that remuxes the selected video stream with `-c:v copy` into fragmented MP4.

Current relay management behavior:

- `config/mediamtx.php` pins the MediaMTX version and relay ports.
- `App\Services\Relay\MediaMtxInstaller` downloads the relay into `storage/app/private/mediamtx/releases/{version}`.
- `App\Services\Relay\MediaMtxConfigService` renders `storage/app/private/mediamtx/mediamtx.yml` from enabled cameras.
- `App\Services\Relay\MediaMtxProcessService` syncs config, starts the relay, and checks the Control API.
- `routes/console.php` exposes `relay:install`, `relay:sync`, `relay:start`, `relay:stop`, and `relay:status`.

The WebRTC wall is intended for operator viewing with shared fan-out. The copy relay remains available for downstream consumers that want copied camera video without a re-encode step.

## Reverse Proxy Deployment

The application is now proxy-aware for same-host Nginx deployments.

Relevant behavior:

- `App\Http\Middleware\TrustReverseProxyHeaders` trusts configured `X-Forwarded-*` headers through `TRUSTED_PROXIES`.
- `App\Http\Middleware\RestrictWebsiteIp` evaluates the client IP after proxy normalization and is driven by `WEBSITE_ALLOWED_IPS`.
- MediaMTX player traffic is expected to be published behind a proxied path such as `/__webrtc/` through `MEDIAMTX_WEBRTC_PUBLIC_URL`.
- Media traffic still requires direct ICE reachability on the configured WebRTC transport ports, typically `8189/udp` and optionally `8189/tcp`.

## Storage Layout

Current preview storage layout:

`storage/app/private/cameras/{id}/previews`

Preview metadata stores relative paths like:

`cameras/{id}/previews/{slug}.jpg`

Older `storage/app/private/stream-previews` folders may still exist from previous iterations, but new preview writes should use the per-camera layout above.

## Preview Serving

`App\Http\Controllers\CameraFleetStreamPreviewController` serves saved previews.

Current behavior:

- resolves the saved preview path from the RTSP profile metadata.
- validates that the file is a real image.
- serves the image when valid.
- falls back to an inline SVG placeholder when the file is missing, corrupt, or incompatible.

This avoids broken image icons in the Camera Fleet UI.

## Access Restriction

`App\Http\Middleware\RestrictWebsiteIp` now reads the allow list from `WEBSITE_ALLOWED_IPS`.

This restriction applies to the web application, but not to UDP multicast discovery traffic.

If `WEBSITE_ALLOWED_IPS` is blank, the allow list is effectively disabled.