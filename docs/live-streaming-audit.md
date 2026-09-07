# Live streaming audit — 6–7 September 2026

## Scope and deployment boundary

Reviewed camera profile discovery and probing, canonical source ingestion, shared FFmpeg live transcoding/copying, MediaMTX lifecycle and authentication, WHEP proxying, browser playback/reconnects, and interference with recording readers. Changes are built into the test `bigbrotha` Docker stack published at `https://monitor-test.schollinetz.com`.

Production `actualbigbrotha` configuration, images, camera settings, and running containers were not changed. The IMOU exists in production rather than the test inventory. Measurements read its already-running canonical source through an isolated temporary relay; they did not open another hardware camera connection. Network captures collected packet-header timing, not saved camera footage.

## IMOU findings

The affected source advertises 1920×1080 H.264 Main profile and G.711 μ-law audio. A 50-second source sample contained 499 video packets (approximately 10 fps), with a 3.129-second delivery gap while media timestamps remained nearly continuous. A second sample measured a 3.156-second gap. The configured browser compatibility path produces 15 fps; that necessarily duplicates frames from a 10 fps source and does not imply that the camera sends 15 fps.

Host-interface captures independently confirmed incoming camera TCP payload gaps of 3.142, 3.099, and 3.088 seconds. The bidirectional sample showed no zero receive window; the smallest advertised receiver window was 2,378, and repeated TCP sequence starts were also present. Thus an upstream camera/network interruption exists before Laravel, live transcoding, and browser decoding. The evidence does not distinguish camera encoder stalls from Wi-Fi/network loss or retransmission delays. Camera-side Ethernet/Wi-Fi and encoder diagnostics are needed to eliminate that remaining cause; application changes cannot deliver new frames during these gaps.

A separate application buffering defect was reproduced with a six-second synthetic stream containing uninterrupted video and a three-second audio gap. With the previous FFmpeg interleaving default, output paused for 2.998 seconds. With the new positive 100 ms interleaving bound, the maximum observed output gap was 0.384 seconds. This demonstrates a preventable software stall, independently of the IMOU network problem. FFmpeg's interleaving bound is a media-timestamp limit, not a hard end-to-end latency guarantee.

The IMOU comparison also showed why a blanket switch to native timestamps is unsafe for live transcoding on this installation. The existing arrival-time repair performed better for this camera's live transcoder. The final change retains the previous timestamp defaults, adds native presentation/decode timing only for detected B-frame streams, bounds interleaving, and limits software encoder threads. Forced compatibility mode retains its previous native source timestamps. A 120-second simultaneous browser comparison produced 1,347 old-path frames and 1,408 revised-path frames, with no signaling errors. Both paths still paused during upstream interruptions; the comparison does **not** establish stall-free IMOU playback.

## Changes

| Area | Defect | Result |
| --- | --- | --- |
| FFmpeg source/live output | Missing audio could hold video in the default ten-second interleaving queue | Positive 100,000 μs bound plus packet flushing on both publishing stages |
| Timestamp policy | B-frame presentation/decode ordering must survive ingestion | Preserve native timestamps for detected B-frame inputs; retain established timing defaults for other cameras after comparative testing |
| Encoder CPU | Live software encoders ignored the configured FFmpeg thread limit | `-threads:v` uses `ffmpeg.ffmpeg.threads`, default 2 |
| ONVIF profiles | Descendant-wide XML queries could select an audio codec/name | Encoding and resolution come from VideoEncoderConfiguration; name comes from the profile itself |
| H.264 compatibility | H.264 with known B-frames was copied into WebRTC | Diagnostics persist B-frame count; detected B-frame streams transcode with no output B-frames |
| Probe fallback | An H.264 live relay could overwrite the original camera's HEVC/B-frame metadata | Live fallback preserves original source metadata; canonical source and original buffer probes remain authoritative |
| Browser authentication | A rejected embedded token could be reused during the “fresh session” retry | Retry fetches a new authenticated session |
| Browser recovery | Audio could advance `currentTime` while video remained frozen | Watch presented frames, with decoded-frame counters as fallback |
| Page-load recovery | Pages loaded while the relay was down rendered no receiver and never retried | Wall and standalone players stay in the automatic session retry loop |
| Browser races/startup | Late fetch/play failures could destroy a newer connection; stuck signaling could wait indefinitely | Connection-attempt guards, immediate observation of script/session promises, and a 65-second startup watchdog |
| Relay config reload | A watcher could read a partially overwritten file | Atomic replacement of complete configuration snapshots; unchanged content is not rewritten |
| Docker relay shutdown | The PHP-FPM image's inherited SIGQUIT made MediaMTX dump its Go runtime state | Relay service explicitly uses SIGTERM; verified graceful shutdown with exit code 0 and healthy restart |
| Legacy HTTP streaming | Long-running camera errors could grow PHP stderr buffers without bound | Keep only the last 16 KiB |
| Runtime dependencies | Docker's required Composer audit identified vulnerable locked packages | Patched Livewire, Guzzle and its supporting packages, and CommonMark; audit passes |

Unknown/unprobed H.264 retains the existing copy behavior. Refresh profiles and run diagnostics to populate correct source metadata and B-frame information for existing cameras. Existing recordings are not rewritten by this change.

## Configuration

- `FFMPEG_LIVE_MAX_INTERLEAVE_DELTA=100000`: live publishing interleaving limit in microseconds.
- `FFMPEG_RELAY_SOURCE_MAX_INTERLEAVE_DELTA=100000`: canonical source publishing limit.
- Both limits must be positive. Zero means unlimited interleaving waiting in FFmpeg, so the application clamps nonpositive values to 1.
- `FFMPEG_LIVE_USE_WALLCLOCK_TIMESTAMPS=true`: retained live clock repair; detected B-frame inputs preserve media/decode timing. Source timing otherwise retains the existing recording setting and forced-compatibility exception.
- `FFMPEG_THREADS=2`: now also applies to the live software video encoder.

Changing global relay commands reloads affected paths, so deploy during an acceptable interruption window. Docker's immutable-image OPcache requires rebuilding/recreating application containers.

## Verification

- Full Docker test suite: **308 passed, 1,642 assertions** on the final implementation.
- Real FFmpeg sparse-audio regression covers both source and live buffering defaults.
- Browser-native regression fixture: **9 assertions passed** in Chromium, covering fresh tokens, video freezes despite advancing audio, decoded-frame fallback, stuck startup, stale fetch errors, and superseded play promises.
- Composer validation and production dependency audit passed in the Docker test build.
- Authenticated public test-site playback was checked before deployment; post-deployment results are recorded below.

Docker commands used (host networking was needed for reliable dependency downloads on this builder):

```sh
docker build --network host --target test -t bigbrotha-stream-audit-test .
docker run --rm --cpus 4 --memory 2g bigbrotha-stream-audit-test
docker build --network host --target final -t rekanized/bigbrotha-app:20260906-stream-audit-final .
```

The browser regression file is `tests/Browser/live-wall-player.test.js`. Load `public/js/live-wall-player.js` and that fixture in a visible browser document, then await `runLiveWallPlayerTests()`. It uses native browser APIs and needs no frontend build tooling.

## Deployed verification

The test app, background, and relay services run `rekanized/bigbrotha-app:20260906-stream-audit-final` (image SHA-256 `09f0337daaa2485c6b8a314d7aad4a0a51a129d491795400d0d6edb05e13bd53`). The relay's Compose stop-signal override was subsequently applied by recreating only the test relay. All four test services report healthy. Production container IDs, image IDs, and start times match the pre-deployment snapshot.

Authenticated Chromium playback at the public test URL was exercised with the test Tapo camera. The page was opened while the test relay was stopped, then recovered automatically after the relay restarted. Across the 150-second observation, including startup recovery, it presented 1,338 frames. After playback began, the largest measured presentation gap was 1.250 seconds. Suspending the player closed its reader and detached the media stream; resuming presented another 163 frames in 12 seconds. An unauthenticated session request returned HTTP 401. These results verify test-site playback and recovery; they do not establish uninterrupted IMOU playback.

A subsequent controlled stop of the relay with SIGTERM logged `shutting down gracefully`, closed its listeners, and exited with code 0. Restart returned it to healthy status. This deployment configuration change requires Compose recreation, not a PHP image rebuild.

The Docker runtime configuration tests were rerun against the updated Compose file: **9 passed, 82 assertions**. The final public login check returned HTTP 200. Temporary audit credentials, packet captures, browser processes, and the isolated comparison relay were removed.

## References

- [FFmpeg format options](https://ffmpeg.org/ffmpeg-formats.html): `max_interleave_delta`, `use_wallclock_as_timestamps`, and `flush_packets` semantics.
- [MediaMTX WebRTC constraints](https://github.com/bluenviron/mediamtx/blob/main/docs/2-features/26-webrtc-specific-features.md): H.264 B-frame compatibility and transcoding.
- [MediaMTX 1.19.2 configuration watcher](https://github.com/bluenviron/mediamtx/blob/v1.19.2/internal/confwatcher/confwatcher.go): parent-directory watching supports atomic replacement.
- [Livewire security advisory](https://github.com/advisories/GHSA-g3hc-697w-wm82): patched in 4.3.4.
