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

- authenticated root redirects and operator pages.
- Camera Fleet pages.
- preview routes.

This does not grant network reachability to camera HTTP, ONVIF, or RTSP endpoints by itself.

If `WEBSITE_ALLOWED_IPS` is blank, the HTTP restriction is effectively disabled.

If the app is behind Nginx or another reverse proxy, set `TRUSTED_PROXIES` so Laravel trusts `X-Forwarded-*` headers before evaluating the allow list.

## Application Authentication

Operator pages are expected to sit behind Laravel session authentication.

Current design assumptions:

- Brand-new deployments remain on `/setup` until onboarding chooses at least one active sign-in method.
- Manual local accounts and Google OAuth can be enabled together.
- The setup and admin auth flows require a successful Google round-trip before Google credentials are saved in an enabled state.
- The first authenticated Google operator is promoted to admin automatically if no admin account exists yet.
- After that bootstrap login, only Google email addresses stored in the admin allowlist may complete Google sign-in.
- MediaMTX WebRTC reads are authorized through Laravel with short-lived signed tokens.
- The MediaMTX HTTP auth callback must remain reachable from the relay process and must be exempt from CSRF protection.
- The callback should be protected by a shared secret query parameter or loopback-only access.
- The internal ffmpeg publisher used by MediaMTX `runOnDemand` uses credentials from `config/mediamtx.php`, derived from `APP_KEY` by default unless explicitly overridden.
- The internal relay reader for live-preview and recording fallback reads also uses the dedicated reader credentials from `config/mediamtx.php` and is expected to come from loopback only.

## Relay Process Detection

`MediaMtxProcessService` cannot rely only on `kill -0` or `posix_kill(pid, 0)` for liveness checks.

On this host, PHP-FPM runs as `www-data` while MediaMTX may be started by another user. In that situation, signal-based liveness checks can fail with `EPERM` even though the relay is still running.

Current expectation:

- relay detection should reconcile stale pid files.
- relay detection should validate the expected MediaMTX binary and config path from process arguments.
- secure player bootstrap should use `ensureRunning()` rather than a raw status check so the relay can self-heal before returning `503`.

## ONVIF Probe Reality

If the direct ONVIF probe in Camera Fleet fails, likely causes are outside Laravel:


- the device service URL is wrong.
- the camera is not reachable from this host or container.
- the ONVIF endpoint requires credentials that were not supplied.
- the camera exposes ONVIF device information but not media profiles or network-interface details.

The practical workflow is to start from the direct probe step inside `/camera-fleet`, confirm the ONVIF device response, then adjust the hydrated draft before saving.

For the repository Docker stack, the important requirement is simple routed reachability from the `app` container to the camera LAN or routed camera subnet. Confirm firewall and network pathing before changing Laravel code.

## Preview Storage History

The current preview layout is:

`storage/app/private/cameras/{id}/previews`

Older `storage/app/private/stream-previews` directories may still remain from previous implementations. Those folders are historical and should not be used for new writes.

## SMB Camera Storage Requirements

The admin settings page at `/admin/settings` can now route the logical camera storage tree onto an SMB share.

Current constraints:

- only durable saved recording clip files under `storage/app/private/cameras/{id}/recordings/YYYY/MM/DD/*` are rerouted; previews, review assets, manifests, sprites, and other private-storage paths remain local.
- the configured path must include at least a host and share, and it should point at the dedicated camera-storage root itself, for example `//fileserver/share/cameras`, `smb://fileserver/share/cameras`, or `//fileserver/share/Applications/bigbrotha/cameras`.
- if an older saved path points at the parent directory above `cameras`, the application now normalizes it onto that directory's `cameras` child for compatibility. New operator-facing values should still use the explicit `.../cameras` path.
- older recordings that were already uploaded before that normalization fix can still exist under the legacy parent-root layout such as `Applications/bigbrotha/{camera}/recordings/...`; SMB-backed reads now fall back to that legacy layout so timeline playback and downloads continue to work while the share is cleaned up or migrated.
- active FFmpeg work files stay local under `storage/app/private/ffmpeg-temp`, including network-backed camera staging files. The SMB share is only contacted when a finished clip or asset is published, and the publish step now creates the full normalized remote directory chain when needed.
- the SMB username field may include a workgroup or domain prefix such as `DOMAIN\operator`.
- SMB paths reject embedded URI credentials, control characters, query/fragment suffixes, and `.` / `..` traversal segments. Credentials reject line breaks so they cannot alter Samba authentication-file fields.
- the host must provide an SMB backend that `icewind/smb` can use. In practice that means `smbclient` must be available in `PATH` or the php smbclient extension must be installed.
- when SMB mode is enabled, ffmpeg still writes clip captures to local staging paths first; Laravel uploads the finished clip under a temporary remote name, promotes that complete upload onto the final path, verifies the remote size, and only then deletes the local staged clip. Retry uploads reuse an already-complete matching remote file.
- direct `smbclient` fallbacks use a per-operation mode-0600 authentication file that is deleted immediately after the command; the SMB password is not placed in the process argument list.
- temporary motion buffers, continuous segmenter work files, previews, and review-asset outputs stay on container-local private storage even when clip storage is network-backed.
- previews, review assets, playback downloads, and streamed remux reads may create short-lived local cache files while serving content from SMB-backed storage.

## Preview Rendering Behavior

The preview controller validates that a saved preview file is a real image before serving it.

If the file is missing, corrupt, or contains invalid bytes, the route returns a placeholder SVG image instead of a broken image response.

## Media Binary Assumptions

The default application expectation is that `config/ffmpeg.php` resolves `ffmpeg` and `ffprobe` from the runtime image, preferring `/usr/bin/*` or `/usr/local/bin/*` and then falling back to `PATH` lookups.

Docker deployments use the image-installed MediaMTX binary at `MEDIAMTX_BINARY_PATH=/usr/local/bin/mediamtx`, which is how the repository `Dockerfile` is wired.

If RTSP diagnostics fail unexpectedly, verify the configured binaries exist, are executable, and that any optional `FFMPEG_BINARIES`, `FFPROBE_BINARIES`, or `FFMPEG_TEMPORARY_DIRECTORY` overrides still point at the intended locations.

## Application Key Durability

- Docker startup persists the Laravel application key at `./.docker-state/app.key` on the host.
- Every Laravel container reads that same mounted file through `APP_KEY_FILE`, so container recreation does not rotate the encryption key.
- Do not delete `./.docker-state/app.key` during rebuilds, cleanups, or host migrations if the database still contains encrypted values.

## Docker Deployment Security And PostgreSQL 18

- `.env.docker` contains the database credential and must be mode `0600`; `docker/compose.sh` enforces that permission when it manages the deployment.
- `APP_URL` and `DB_PASSWORD` are required Compose values. The stack no longer starts with the public `bigbrotha` database-password default.
- `CAMERA_RECORDING_WORKER_PROCESSES` is the worker service's Compose `scale`, so `docker compose up -d` through the supported wrapper honors the requested replica count without a separate `--scale` flag.
- Docker JSON logs are rotated, the relay runs as `www-data` with all capabilities dropped, and `no-new-privileges` is enabled for the stack services.
- PostgreSQL 18 changed its official-image `PGDATA` to a version-specific directory below `/var/lib/postgresql`. The Compose volume must therefore target `/var/lib/postgresql`, not `/var/lib/postgresql/data`.
- An existing PostgreSQL 18 deployment started with the old child mount stores its real cluster in an anonymous parent volume. Do not recreate that database container until `docker/migrate-postgres-18-volume.sh` has created a logical backup and copied the stopped cluster into the named `db-data` volume.
- The migration script deliberately retains both the logical backup and the original anonymous source volume. Remove the old volume only after application-level verification and an appropriate retention period.
- Use `docker/rotate-db-password.sh` to replace an inherited default database password and recreate all dependent services with the new credential.

## Recording Worker Requirements

Per-camera recording is scheduler-orchestrated, with queue-backed motion work and a persistent ffmpeg segmenter for continuous mode.

Current expectations:

- `php artisan schedule:run` must execute every minute so `camera-recordings:tick` and `camera-recordings:prune` keep running.
- a queue worker must process `recordings,default,review-assets` in that order so motion clips and legacy continuous recovery rows stay ahead of SMB-heavy review-asset generation; the minute scheduler still has to run because continuous segmenters are started, recovered, and imported there.
- the recommended worker shape is a bounded process such as `php artisan queue:work --queue=recordings,default,review-assets --max-jobs=50 --max-time=3600 --memory=256` so worker memory is recycled regularly.
- the repository Docker stack satisfies those requirements with dedicated `worker` and `scheduler` containers.
- if you need more recorder capacity, raise `CAMERA_RECORDING_WORKER_PROCESSES` and start the stack through `./docker/compose-up.sh` so Compose scales the `worker` service to the same replica count.
- the scheduler remains a scheduling loop only; it is not a fallback worker supervisor.
- the scheduler now also runs a bounded failed-job retry sweep, so entries in `failed_jobs` are automatically requeued after the configured cooldown until `QUEUE_FAILED_AUTO_RETRY_MAX_RETRIES` is reached; the Admin settings queue panel shows the recorded exception excerpt and current retry state for each failed row.
- recording rows now recover stale `queued` and `processing` states on later scheduler ticks, but that is a recovery path for dead workers, not a substitute for a healthy recorder worker pool.
- continuous recording no longer trusts the minute scheduler as the clip boundary. Once the scheduler boots a camera's segmenter, ffmpeg keeps rotating segment-muxer files on its own so scheduler jitter does not create minute-aligned gaps.
- continuous recording timestamps are now anchored to the imported segment filename timestamp and the configured segment duration, rather than to delayed scheduler enqueue times or PHP cleanup timestamps.
- the recorder writes direct-to-disk ffmpeg copy segments; PHP should orchestrate jobs, not stream payload bytes.
- if older crashes, tests, or manual row cleanup leave files behind without matching `camera_recordings` rows, use `php artisan camera-recordings:orphans` to audit them and `php artisan camera-recordings:orphans --purge` to remove the orphan files plus matching `_review` assets.

## Shared Storage Permissions

This host runs web traffic as `www-data` while scheduler and queue commands may run as the deploy user.

Current expectations:

- shared Laravel runtime paths such as `storage/logs` and `storage/app/private/ffmpeg-temp` must remain group-writable across both users.
- if `storage/logs/laravel.log` becomes owner-only, `php artisan schedule:run` can fail in an earlier scheduled task before `camera-recordings:tick` runs, which stalls new recordings even when the queue workers are healthy.
- if review playback or review-asset generation reports `Permission denied` in `storage/app/private/ffmpeg-temp`, inspect directory modes under that tree before changing recorder logic.

## Operator Timezone Setting

- Operator-facing timestamps now use the admin-configured display timezone instead of hard-coded UTC labels.
- The admin route is `/admin/settings` and is restricted to authenticated admin users.
- Internal recording storage, retention logic, scheduler timestamps, and review asset metadata still use UTC for consistency.
- The default fallback display timezone is `Europe/Stockholm`, which matches Amsterdam's offset and daylight-saving rules.

## Motion Recording Tradeoff

The current movement-recording implementation uses grayscale frame differencing on a saved low-resolution motion mask.

Implications:

- it is intentionally basic pixel-change detection, not object classification.
- the configured area is stored as painted mask coordinates on a normalized motion grid instead of as one rectangle.
- the threshold is the percentage of selected mask pixels that must change between sampled frames before recording starts.
- motion mode now depends on a persistent per-camera rolling buffer of short closed segments, not a one-shot buffered capture window.
- the first detected motion segment opens the event, pre-roll is recovered from the buffered segments before that point, and the event stays open while later motion segments keep resetting the quiet post-trigger deadline.
- long-running stitched motion events now roll over onto a new recording once they reach the configured `recording.motion.max_stitched_seconds` limit; the handoff happens on the next closed buffered-segment boundary so a continuously active camera produces multiple bounded clips instead of one unbounded event.
- once a motion event has already been stitched into a local staged clip, later SMB upload or verification retries no longer pin the full raw motion buffer in place; the rolling `motion-recorders` spool can fall back to the normal idle-buffer window while Laravel retries the staged upload.
- quiet motion scans remain transient work with no durable row until a real motion event opens, while stale legacy motion rows without an owning motion state are still discarded during recovery.
- the hourly `camera-recordings:prune` maintenance pass reconciles `recorded` rows only when storage can confirm the backing segment file is actually missing; transient SMB reachability failures leave the row unchanged so healthy clips are not reclassified as failed, and rows previously failed by that reconcile step are restored automatically once the file becomes reachable again.
- motion recording should read from the local MediaMTX source path for the selected profile so mask updates, relay playback, and recorder segmenters do not compete by opening separate direct RTSP sessions to the same camera stream.
- the recorder and motion editor should no longer use direct-camera diagnostic fallbacks during mask editing; if the internal MediaMTX source path cannot be resolved, the motion editor should fail closed instead of opening an extra hardware RTSP session.
- MediaMTX path design now treats `camera-*-source*` as the only allowed hardware-ingest paths. Derived `camera-*-live*` playback paths and recorder workers are expected to read those loopback RTSP paths rather than the physical camera URI.
- because the pre-roll buffer is isolated to the motion workflow, motion cameras spend extra capture time around event evaluation and briefly suppress overlapping triggers while the current event clip is still being compiled.
- the scheduler now refuses to enqueue a new motion evaluation for a camera while any older motion row for that camera is still pending, which prevents backlog explosions when the worker or host is unhealthy.
- ffmpeg runtime settings are now split by workload: recording, motion, and relay ingest prefer RTSP over TCP, larger demux queues and realtime buffers, wallclock-backed timestamp generation, and passthrough frame timing so unstable camera timecodes do not propagate into saved clips or relayed playback.
- finalized MP4 review assets should keep `+faststart`, and the Timeline Review fallback route now also materializes a short-lived finalized MP4 with `+faststart` before serving it so browser seeks and audio playback do not depend on fragmented stdout remuxing.
- H.264 is copied into a review MP4 only when ffprobe confirms strictly increasing video DTS values. Cameras that repeat or omit DTS values are normalized through the CFR H.264 path instead; this safety gate now applies to both durable review generation and emergency request-time playback.
- recording and review commands map only the first optional audio stream (`0:a:0?`) so multi-audio cameras do not unexpectedly expand a clip or produce an ambiguous browser playback asset.
- WebRTC relay audio uses asynchronous resampling with a bounded hard-compensation threshold before Opus encoding, which absorbs camera clock drift while keeping the browser-facing audio timeline anchored at zero.

## Recording Playback Tradeoff

Saved footage is still captured in the recorder's copy-friendly container first, but playback no longer needs to do most of its compatibility work inside the browser request itself: the recorder's existing post-save review-assets job now rewrites the durable saved recording itself into a browser-playable MP4 with audio under the real recordings directory.

Implications:

- playback still depends on ffmpeg being available on the worker host when the post-save normalization job runs, but most steady-state browser playback no longer depends on spawning ffmpeg in the request path.
- Timeline Review now uses the same buffered review-stream route as the standalone Recordings page for the stage player. Preview assets are still used for thumbnails and scrub metadata, but the stage itself does not hop between preview, buffered review, and streamed remux routes.
- opening many recorded tiles at once should usually reuse those already-normalized MP4 recording files instead of starting several remux jobs in parallel; the main remaining cost is background normalization when clips are first saved or when an old recording still has not been rewritten yet.
- the browser review flow avoids exposing private storage paths directly, but the emergency fallback route still depends on request-time ffmpeg work if an older recording has not been normalized yet.
- the original file remains downloadable even if the browser player cannot render the remuxed segment.
- the review-stream route can still remux to a short-lived local MP4 file as an emergency fallback, but the preferred steady-state path is the normalized MP4 that replaced the original saved recording.

## Timeline Review Preview Assets

Timeline Review now generates private derived assets for saved recordings.

Current behavior:

- each recorded segment is normalized into a full browser-playback MP4 with audio in the real recordings directory so the main Recordings player can serve the saved file without live transcoding in the request path.
- each recorded segment can also produce a scrub sprite sheet plus manifest metadata so the stage can show in-frame hover previews without opening the full clip.
- manifest and scrub-sprite review files live under a private `_review` directory beside the parent recording path and are pruned with the parent recording.
- when camera storage is routed to SMB, scrub sprite sheets are stored under the local private `review-sprites/...` tree instead of on the network share so `preview-sprite` stays local and does not fan out into SMB reads; those local sprite files are still pruned with the parent recording.
- the initial timeline page and rail JSON route now trust recorded database rows plus the review-asset manifest state; they do not synchronously probe the backing recording file on SMB during page load.
- the timeline preview page now renders a plain Blade shell from controller payloads; public/js/recordings-review.js owns active camera switching, focus movement, stage playback, and rail virtualization without a mounted Livewire review component morphing the player DOM.
- on network-backed camera storage, timeline page, stage, and rail payload assembly now skips synchronous `_review/manifest.json` probes during request handling, emits deterministic scrub-sprite metadata for the client, and uses the shared fallback thumbnail asset instead of per-recording thumbnail routes; the rail no longer preloads `preview-sprite` URLs on first paint, and scrub sprites are left for on-demand hover preview so the timeline does not fan out into dozens of SMB-backed sprite requests at once. When local sprite storage is enabled, the emitted `preview-sprite` route serves that local `review-sprites/...` copy instead of touching SMB.
- the review shell should keep only camera-summary data in its public Livewire state; the rail now loads segment windows on demand instead of hydrating every segment for every selected camera into the initial payload.
- the timeline stage player uses the buffered review-stream route so it behaves like the standalone Recordings viewer while still supporting timeline seeks against SMB-backed clips.
- if scrub sprite generation has not completed yet, the thumbnail route still returns a placeholder image, but the stage player continues to use the buffered review-stream route instead of swapping to another stage source.
- use `php artisan camera-recordings:queue-review-assets --camera_id=...` or a `--date_from` / `--date_to` display-date range to selectively queue missing scrub-sprite backfills when you want to drive the async queue directly; the command still refuses an unfiltered whole-library queue sweep.
- the minute scheduler now also runs a bounded synchronous safety-net backfill through `camera-recordings:build-review-assets --missing --limit=...`, ordered newest-first, so recent clips still pick up playback normalization and scrub-sprite assets even when the async review queue is delayed; tune it with `CAMERA_REVIEW_ASSET_SCHEDULER_ENABLED` and `CAMERA_REVIEW_ASSET_SCHEDULER_LIMIT`.
- recordings worker capacity is fixed to the Docker replica count. Set `CAMERA_RECORDING_WORKER_PROCESSES` to the exact number of worker replicas you want and start the stack through `./docker/compose-up.sh` so the running container pool matches the expected worker count.
- the vertical rail relies on client-side virtualization plus `content-visibility` for thumbnail cards, so off-screen rail nodes should stay out of the DOM unless they are close to the viewport.
- scrub sprite requests are deduplicated, loaded only when a thumbnail approaches the viewport or an operator actively scrubs, validated against the manifest's expected sprite-grid dimensions, kept in a bounded recent-success cache, and placed on a short failure cooldown so a missing or placeholder sprite cannot be requested repeatedly during fast scrolling.
- native timeline scrolling takes precedence over scrubbing on touch devices; operators drag the explicit blue focus handle to scrub, while ordinary rail swipes retain inertial scrolling. Unmodified mouse-wheel input scrolls and Ctrl/Command + wheel zooms.
- timeline clip selection now uses half-open bounds, so a focus time that lands exactly on the shared edge between two adjacent clips resolves to the later clip instead of duplicating the earlier one.

## Live Wall Delivery Tradeoff

The current live wall uses MediaMTX plus WebRTC instead of per-viewer MJPEG.

Implications:

- wall tiles still require ffmpeg to decode and re-encode for browser-safe playback, but only once per active camera path instead of once per viewer.
- the implementation mitigates this by preferring lower-cost RTSP substreams when available.
- MediaMTX requires its own HTTP and ICE ports in addition to the Laravel web port.
- a separate `/live-wall/{camera}/relay` endpoint is available for a no-transcode path that remuxes copied video into fragmented MP4.
- live-wall tiles must stay live-only. If a camera feed is unavailable, the tile should fail closed, show the stream error, and retry the live session instead of swapping to a saved preview image.

If browsers still fail to connect over WebRTC, check `webrtcAdditionalHosts`, `webrtcLocalUDPAddress`, `webrtcLocalTCPAddress`, host firewall rules, and TURN requirements before changing the Laravel UI.

## HEVC WebRTC Transcoding

Browsers commonly render a grey or blank WebRTC tile when the relay publishes HEVC video, even if the WHEP session itself succeeds. For any camera feed that arrives as HEVC video plus AAC audio, the shared WebRTC path should publish H.264 video plus Opus audio instead.

The software ffmpeg shape used by the relay is:

```bash
ffmpeg -nostdin -hide_banner -loglevel error \
	-rtsp_transport tcp \
	-thread_queue_size 1024 \
	-timeout 10000000 \
	-rtbufsize 64M \
	-fflags +genpts+discardcorrupt \
	-use_wallclock_as_timestamps 1 \
	-analyzeduration 1000000 \
	-probesize 131072 \
	-i 'rtsp://operator:secret@camera.example/live' \
	-map 0:v:0 -map 0:a:0? -sn -dn \
	-fps_mode cfr \
	-avoid_negative_ts make_zero \
	-r 15 \
	-c:v libx264 -pix_fmt yuv420p -profile:v baseline \
	-preset ultrafast -tune zerolatency -bf 0 -refs 1 \
	-g 30 -keyint_min 30 -sc_threshold 0 \
	-crf 23 -b:v 1200k -maxrate 1800k -bufsize 1800k \
	-af 'aresample=async=1:first_pts=0' \
	-c:a libopus -ac 2 -ar 48000 -b:a 96k \
	-max_muxing_queue_size 1024 \
	-f rtsp -rtsp_transport tcp 'rtsp://publisher:***@relay:8554/camera-1-live'
```

The critical sync-repair flags are `-fflags +genpts`, `-use_wallclock_as_timestamps 1`, `-fps_mode cfr`, `-r 15`, `-af aresample=async=1:first_pts=0`, and `-avoid_negative_ts make_zero`. Together they prevent the common HEVC-video plus AAC-audio drift where MediaMTX reaches the browser but the tracks do not stay aligned or show up as a grey tile.

When GPU offload is available, the relay can switch to hardware-assisted decode and H.264 encode through `MEDIAMTX_TRANSCODE_HWACCEL`:

- `MEDIAMTX_TRANSCODE_HWACCEL=nvidia` inserts `-hwaccel cuda -hwaccel_output_format cuda -c:v hevc_cuvid` on input and uses `-c:v h264_nvenc -tune ll -rc cbr` on output.
- `MEDIAMTX_TRANSCODE_HWACCEL=qsv` inserts `-hwaccel qsv -hwaccel_output_format qsv -c:v hevc_qsv` on input and uses `-c:v h264_qsv -look_ahead 0` on output.
- `MEDIAMTX_TRANSCODE_HWACCEL_DEVICE`, `MEDIAMTX_TRANSCODE_HWACCEL_DECODER`, `MEDIAMTX_TRANSCODE_HWACCEL_ENCODER`, `MEDIAMTX_TRANSCODE_HWACCEL_INPUT_ARGS`, and `MEDIAMTX_TRANSCODE_HWACCEL_OUTPUT_ARGS` remain available for host-specific overrides.

Representative accelerated command shapes are:

```bash
ffmpeg -nostdin -hide_banner -loglevel error \
	-rtsp_transport tcp -thread_queue_size 1024 -timeout 10000000 -rtbufsize 64M \
	-fflags +genpts+discardcorrupt -use_wallclock_as_timestamps 1 \
	-analyzeduration 1000000 -probesize 131072 \
	-hwaccel cuda -hwaccel_output_format cuda -c:v hevc_cuvid \
	-i 'rtsp://operator:secret@camera.example/live' \
	-map 0:v:0 -map 0:a:0? -sn -dn -fps_mode cfr -avoid_negative_ts make_zero -r 15 \
	-c:v h264_nvenc -profile:v baseline -preset p4 -tune ll -bf 0 -g 30 -keyint_min 30 \
	-rc cbr -b:v 1200k -maxrate 1800k -bufsize 1800k \
	-af 'aresample=async=1:first_pts=0' -c:a libopus -ac 2 -ar 48000 -b:a 96k \
	-max_muxing_queue_size 1024 -f rtsp -rtsp_transport tcp 'rtsp://publisher:***@relay:8554/camera-1-live'
```

```bash
ffmpeg -nostdin -hide_banner -loglevel error \
	-rtsp_transport tcp -thread_queue_size 1024 -timeout 10000000 -rtbufsize 64M \
	-fflags +genpts+discardcorrupt -use_wallclock_as_timestamps 1 \
	-analyzeduration 1000000 -probesize 131072 \
	-hwaccel qsv -hwaccel_output_format qsv -c:v hevc_qsv \
	-i 'rtsp://operator:secret@camera.example/live' \
	-map 0:v:0 -map 0:a:0? -sn -dn -fps_mode cfr -avoid_negative_ts make_zero -r 15 \
	-c:v h264_qsv -profile:v baseline -preset veryfast -look_ahead 0 -bf 0 -g 30 -keyint_min 30 \
	-b:v 1200k -maxrate 1800k -bufsize 1800k \
	-af 'aresample=async=1:first_pts=0' -c:a libopus -ac 2 -ar 48000 -b:a 96k \
	-max_muxing_queue_size 1024 -f rtsp -rtsp_transport tcp 'rtsp://publisher:***@relay:8554/camera-1-live'
```

The browser-side receiver should still initialize the HTML media element in a muted state. The shared `public/js/live-wall-player.js` player now retries playback after the first user interaction if autoplay is blocked and the standalone player exposes an explicit audio-enable button so Opus audio is only unmuted on a deliberate gesture.

## Secure Live Wall Troubleshooting

If the player stays on `Loading secure stream…`, check these in order:

1. confirm `/live-wall/{camera}/session` returns JSON instead of `503`, `401`, or HTML.
2. run `php artisan relay:status` and confirm MediaMTX is installed, running, and the API is reachable.
3. inspect `storage/logs/mediamtx.log` for these common failure modes:
	- `deadline exceeded while waiting connection`: the browser reached WHEP but ICE did not complete.
	- `closed: source of path ... has timed out`: the camera source did not come up in time.
	- `method ANNOUNCE failed: 401 Unauthorized`: the internal ffmpeg publisher was rejected by MediaMTX auth.
	- `authHTTPAddress is empty`: the auth callback URL resolved to blank config.
4. if the relay uses the two-stage `camera-*-source*` to `camera-*-live*` topology, remember that a cold live start has to wait for the upstream source path to ingest from the camera first. Keep the live-path `runOnDemandStartTimeout` higher than the source-path timeout so chained startup does not collapse at the same 20 to 30 second boundary on both legs.
5. verify the generated `storage/app/private/mediamtx/mediamtx.yml` contains:
	- the expected `authHTTPAddress` with the callback secret.
	- a `runOnDemand` RTSP publish target that includes the internal publisher credentials.
6. if MediaMTX is behind `/__webrtc/`, verify the Nginx block preserves the prefix on WHEP session `Location` headers.
7. if the relay log shows sessions being created and then timing out, check `8189/udp` and optionally `8189/tcp` reachability before changing Laravel code.
8. after changing `.env` values related to relay auth, run `php artisan config:clear`, `php artisan view:clear`, and `php artisan relay:sync`.
9. in Docker, keep `MEDIAMTX_AUTH_CALLBACK_URL` on `http://web:8080/relay/auth/mediamtx`; the nginx container listens on port `8080`, not port `80`, and an unreachable callback makes MediaMTX reject otherwise valid internal RTSP reads with `401 Unauthorized`.
10. if the browser still appears to run old PHP or Blade behavior after cache clears, reload PHP-FPM only as a last resort for stale OPcache.

## Nginx Reverse Proxy Requirements

For a site published at a hostname like `monitor.schollinetz.com`, there are two separate traffic classes:

- Laravel UI HTTP traffic.
- MediaMTX WebRTC traffic.

Recommended setup:

1. proxy the MediaMTX HTTP player and WHEP handshake through Nginx on the same HTTPS origin, for example `/__webrtc/`.
2. expose MediaMTX ICE transport on `8189/udp` and ideally `8189/tcp` to the public internet or upstream load balancer.
3. set `APP_URL=https://monitor.schollinetz.com` so Laravel derives the default relay URL `https://monitor.schollinetz.com/__webrtc` and callback URL `https://monitor.schollinetz.com/relay/auth/mediamtx`.
4. add `MEDIAMTX_WEBRTC_PUBLIC_URL`, `MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS`, or `MEDIAMTX_AUTH_CALLBACK_URL` only if the relay is published on a different origin or prefix than the app itself.
5. add `MEDIAMTX_AUTH_CALLBACK_SECRET` only if you need an explicit callback secret instead of the default derived from `APP_KEY`.
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

If MediaMTX starts the camera path but the log shows `method ANNOUNCE failed: 401 Unauthorized`, the local ffmpeg publisher credentials are not aligned with the Laravel auth callback. Check the derived publisher credentials in `config/mediamtx.php`, clear Laravel config, and re-sync the relay config before debugging WebRTC itself.

## High-Value Tests

When changing this platform, the most relevant tests are:

- `tests/Feature/OnvifDeviceProbeServiceTest.php`
- `tests/Feature/OnvifRtspStreamServiceTest.php`
- `tests/Feature/RtspStreamDiagnosticsServiceTest.php`
- `tests/Feature/CameraFleetManagerTest.php`
- `tests/Feature/CameraRecordingCommandTest.php`
- `tests/Feature/CameraRecordingMotionCommandTest.php`
- `tests/Feature/CameraRecordingMaintenanceCommandTest.php`
- `tests/Feature/LiveWallStreamTest.php`
- `tests/Feature/Relay/MediaMtxAuthCallbackTest.php`
- `tests/Feature/Relay/MediaMtxProcessServiceTest.php`
