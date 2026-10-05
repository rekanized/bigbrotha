# Camera operations

## Add an ONVIF camera

1. Open **Camera Fleet** and choose **Add camera**.
2. Enter the device service URL, for example
   `http://192.0.2.10:80/onvif/device_service`, and any required credentials.
3. Probe the endpoint. BigBrotha retrieves device information, network details,
   and available RTSP profiles.
4. Review the resulting draft. Confirm the address, ports, credentials, stream
   paths, and capabilities before saving.
5. Run stream diagnostics and inspect the preview for the intended profile.

The URL is a direct device endpoint. Camera discovery does not require multicast,
but the application container must have routed access to the camera network.
Devices can support ONVIF information requests without exposing every optional
media or network-interface operation.

## Add an RTSP-only camera

Choose RTSP-only mode in the new-camera dialog. Enter the camera address, RTSP
port, stream path, transport, and credentials. Save the camera, refresh its saved
endpoint, and run diagnostics. ONVIF is not required for this workflow.

Use the live stream path for wall playback and the recording path for capture.
They can refer to different profiles. If a recording path is not set, it defaults
to the live path.

## Edit credentials and profiles

Saved passwords are never loaded into the editor. Leave the password field blank
to retain the existing value, or enter a replacement to rotate it. **Show** reveals
only the replacement currently entered in the form.

Refreshing profiles preserves matching saved diagnostic and preview information.
Discovered stream URLs omit embedded usernames and passwords. Device error
messages redact credential-bearing URLs and sensitive query values.

Changing an active profile, transport, or compatibility setting can reload the
shared relay source and briefly interrupt its readers.

## Diagnostics and previews

Diagnostics use ffprobe to inspect the stream and FFmpeg to capture a preview.
The configured transport is tried first; supported fallback behavior can use TCP
or an active relay when a camera rejects another direct reader.

Check the source codec, resolution, frame structure, and transport reported for the
selected profile. H.264 streams with detected B-frames require compatibility
transcoding for WebRTC. An unprobed profile may need fresh diagnostics before
BigBrotha can choose the appropriate delivery mode.

Previews are stored under `storage/app/private/cameras/{id}/previews` and served
through authenticated routes. Background work refreshes thumbnails periodically;
missing or invalid preview files display a placeholder.

## Configure recording

Choose **Off**, **Continuous**, or **Motion** for each camera. Set the recording
profile and retention period before enabling capture.

For motion recording, paint the detection mask and configure the trigger threshold,
pre-roll, and post-trigger durations. Use the live motion preview to assess the
latest detector decision. An active recording event can remain open during its
post-trigger period after immediate motion stops.

Recorders reuse shared relay sources. Closed buffer segments are processed in
bounded batches; deferred segments remain available for subsequent scheduler
ticks. A temporary upload or source failure can delay publication without making
an otherwise healthy application container unhealthy.

Use **Recordings** to filter clips, inspect capture results, play available footage,
and download files. **Timeline Review** aligns footage from multiple cameras and
preserves capture gaps. A camera's retention policy removes expired durable clips
and associated review assets.

See [recording storage](recording-storage.md) for local and SMB storage behavior.

## Build a live wall

Open **Wall Tiles** to create or select a named wall, arrange its tiles, and assign
cameras. Open **Live Wall** to watch the layout. Camera focus mode expands one feed;
Escape restores the grid. Audio and wall-switching controls remain in the wall dock.

A successful HTTP connection does not establish WebRTC media reachability.
Browsers also need the configured ICE TCP/UDP port, and the relay must reach the
camera. See [deployment constraints](known-issues-and-constraints.md).

## Routine troubleshooting

```sh
./docker/compose.sh ps
./docker/compose.sh logs --tail=100 app background relay
./docker/compose.sh exec app php artisan relay:status
```

Keep the same `--local` or `--dev` mode used to start the installation. Check camera
credentials, routing, source availability, and codec compatibility before changing
application settings. Do not include credentials, private footage, or unredacted
logs in support requests.
