# Camera Fleet Workflow

## Goal

The Camera Fleet is the main operator workflow for turning discovered or known devices into managed camera records with saved RTSP profiles and preview diagnostics.

## Entry Paths

### ONVIF Sweep

Use `/discovery/onvif-sweep` to run WS-Discovery across the network.

Best when:

- cameras respond to multicast.
- the network allows WS-Discovery traffic.

### Manual ONVIF Probe

The same screen supports direct ONVIF endpoint verification with:

- ONVIF URL.
- optional username.
- optional password.

Best when:

- multicast discovery returns nothing.
- a specific device address is already known.
- authentication is required to validate the device.

Successful manual probes can be saved directly into the fleet.

## Provisioning Flow

Verified manual probe results can create or update a camera using:

- device service URL.
- credentials.
- manufacturer.
- model.
- serial number.
- verification metadata.

The provisioning flow tries to match existing cameras before creating duplicates.

## Camera Fleet Screen

Open `/camera-fleet` to manage saved cameras.

Each row currently shows:

- camera identity.
- enabled or disabled state.
- latest preview thumbnail when available.
- last stream-check status.
- ONVIF and RTSP readiness.

Use `View / edit` to open the modal editor.

## Camera Editor Modal

The modal is the main management surface for a selected camera.

It supports:

- editing network and endpoint fields.
- editing credentials.
- toggling ONVIF and RTSP support.
- saving camera changes.
- refreshing RTSP profiles from ONVIF.

Password behavior:

- on create, the supplied password is saved.
- on edit, leaving the password blank keeps the saved password.

## RTSP Retrieval

When RTSP profiles are refreshed:

1. `OnvifRtspStreamService` locates the media service with `GetCapabilities`.
2. It loads media profiles with `GetProfiles`.
3. It resolves RTSP URIs with `GetStreamUri`.
4. It saves results under `metadata['rtsp_profiles']`.
5. It updates the primary RTSP path and port on the camera when possible.

## RTSP Test And Preview

Use `Test connection and preview` on a saved profile to confirm playback before it is used elsewhere.

The diagnostics flow:

1. injects credentials into the RTSP URI when needed.
2. runs ffprobe for stream validation and metadata.
3. ensures per-camera preview storage exists.
4. runs ffmpeg to capture a frame.
5. saves the updated probe and preview state back into the RTSP profile metadata.

Saved profile metadata may include:

- `probe_status`
- `probe_checked_at`
- `probe_message`
- `video_codec`
- `video_resolution`
- `preview_path`
- `preview_generated_at`
- `preview_message`
- `transport`

## Preview Display

Preview images appear:

- inside RTSP profile cards in the modal.
- in fleet rows through `latestRtspPreview()`.

If a saved preview file is not a valid image, the preview route serves a placeholder instead of a broken icon.

## Live Wall Playback

Use `/live-wall` to view enabled cameras in the operator wall.

Current behavior:

1. the wall picks a lower-cost RTSP profile when a camera exposes one.
2. the selected profile is mapped to a shared MediaMTX path and transcoded on demand into a WebRTC-safe stream.
3. the browser-facing tile renders a Laravel-owned player shell instead of the stock public MediaMTX page.
4. the player requests `/live-wall/{camera}/session` to receive a short-lived signed MediaMTX read token and the proxied WHEP URL for the selected camera path.
5. the browser loads the official per-path MediaMTX `reader.js` and opens the WHEP session with that token.
6. MediaMTX validates both the WebRTC read and the internal ffmpeg RTSP publisher through the Laravel auth callback.
7. each tile still exposes a `Copy relay` link that remuxes the selected camera video with ffmpeg stream copy instead of a re-encode.
8. relay configuration can be refreshed with `php artisan relay:sync` when enabled cameras, RTSP selections, or relay auth settings change.

This gives operators a shared live view path for multiple simultaneous viewers while keeping a separate no-transcode path available for consumers that do not need WebRTC.

## Reverse Proxy Workflow

When the operator UI is published behind Nginx on a host like `monitor.schollinetz.com`:

1. Laravel serves the main application from the normal web root.
2. Nginx proxies MediaMTX HTTP player and WHEP requests under a public prefix such as `/__webrtc/`.
3. MediaMTX advertises that public prefix through `MEDIAMTX_WEBRTC_PUBLIC_URL` and `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`.
4. the proxy should preserve the prefixed WHEP session URLs with `X-Forwarded-Prefix` and `proxy_redirect`, otherwise WHEP `PATCH` and `DELETE` calls will fall back to unprefixed session URLs and fail.
5. ICE transport still needs direct host or firewall exposure on the configured WebRTC ports.
6. the MediaMTX auth callback URL and internal publisher credentials must be in sync with Laravel config before secure playback can work.

The same-host deployment model should expose MediaMTX directly on `8189` rather than trying to proxy that same port back through a site-level Nginx `stream` block.

## Deletion

Deleting a camera also removes its per-camera preview storage through `CameraStorageService`.