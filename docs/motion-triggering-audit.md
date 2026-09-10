# Motion triggering audit — September 2026

## Current data path

The modal controls BigBrotha motion recording, not the camera's ONVIF motion alarms. The saved recording profile enters the canonical MediaMTX source path. The recording worker retains its rolling stream-copy buffer and analyzes closed segments with the saved mask and threshold. Qualifying motion opens or extends an event with pre-roll and a resettable quiet deadline.

The live editor now reads that same internal source through one temporary shared sampler. It does not open an additional connection to the physical camera. The `camera-motion:sample` process continuously decodes low-resolution grayscale frames while an authenticated editor is active. `MotionEditorSampleService` applies draft settings to the newest confirmed frame window through `RecordingMotionDetectorService::analyzeFrames()`.

Default sampling is 6 fps, twice the recorder's 3 fps analysis rate. Each preview evaluation selects every second sample, alternating phases as new frames arrive. Adjacent compared frames therefore remain one-third of a second apart. Simply comparing consecutive 6 fps frames would halve the motion interval and change sensitivity. The existing noise, cluster-weighting, broad image-change, and refresh-spike gates remain shared with the recording detector.

The modal requests an update every 150 ms, counting request time toward that interval, with one request in flight and a minimum 20 ms gap. It draws accepted movement in the latest confirmed transition. A short lookahead remains necessary for artifact rejection. The preview no longer waits for recorder segment rollover or repeatedly starts ffmpeg to decode the same growing file.

## Display semantics

- Blue cells select where movement counts. Amber cells show accepted movement below the draft threshold; red cells meet it.
- Moving cells are counted once. Their percentage uses the selected area. Effective trigger pixels also include cluster bonus; four connected changed cells contribute eight effective pixels at default weighting.
- The meter shows progress toward the threshold. Lower values trigger on smaller movements.
- The saved recording event is separate from current movement. A live preview is a draft estimate; only the recorder's closed-segment analysis controls durable events.
- Missing data is shown as unavailable, not as a measured zero. Sample age uses the confirmed frame's receipt time and conservatively includes request latency. Cached samples, reopened editors, and draft changes cannot renew old frame timestamps.
- Timing help describes pre-roll and a resettable quiet tail instead of promising a fixed clip length.

## Performance and lifecycle

`MotionEditorStreamService` shares one decoder across viewers and draft masks. Its frame window is bounded (11 raw frames at defaults), and process stdout/stderr buffers are drained continuously. Local file-cache entries contain frame data and a short viewer lease; temporary pixels never enter SMB recording storage.

A kernel file lock prevents duplicate producers and is released if the producer crashes. Startup requests have a two-second cooldown. The producer ends its ffmpeg process when its five-second viewer lease expires, its source signature changes, or no frames arrive for ten seconds. RTSP input also has a five-second timeout. Opening another editor renews the shared lease rather than creating another decoder. SIGTERM/SIGINT cleanup stops the child process and releases the lock. Camera deletion, disabling, changing recording mode, closing the modal, and hidden-tab suspension all stop lease renewal.

The source signature includes the internal RTSP source, grid dimensions, and sampling parameters. A mismatched signature prevents stale frames from a previous source reaching the overlay. Unsaved masks and thresholds do not restart decoding. Identical sample/settings results share a separate bounded analysis cache and non-blocking analysis lock. Each live evaluation calculates only the newest confirmed transition, while retaining surrounding frames for artifact checks. Recording clips continue to evaluate every transition. The obsolete historical peak display was removed so live requests do not recompute history solely to draw a summary.

The browser caches mask encoding and selected-cell counts, reuses canvas buffers, defers new analysis until brush strokes end, drops responses for old draft revisions, and suspends polling for hidden/detached editors. Authentication and validation failures stop futile retries; expired-session help explains recovery without automatically discarding the draft.

The earlier snapshot implementation reduced some duplicate decoding but still showed approximately one distinct movement sample per second in the recorded test observation, with repeated waits at four-second segment boundaries. Continuous sampling addresses both costs: each source frame is decoded once, and no segment boundary interrupts the modal's analysis.

## Limits

This is sampled live activity, not pixel-perfect synchronization with the separately timed WebRTC video. Source delivery, decoder reorder, future-frame confirmation, HTTP latency, and browser scheduling still contribute delay. Camera/network stalls cannot be eliminated by polling faster. Sample age is not a camera capture-to-screen latency measurement.

Preview phases and recent windows differ from the worker's closed-segment boundaries, so preview qualification is explicitly separate from saved recording state. Broad real scene changes can also be rejected by artifact gates. This remains pixel-change detection rather than object recognition.

The sampler is intended for this Docker deployment with shared local storage. One PHP producer and one ffmpeg decoder consume resources per actively edited camera, independent of viewer count; both stop after the idle lease expires. File cache is not a multi-host stream transport. Motion mode, RTSP support, and camera enablement must be saved before sampling starts.

## Validation and deployment

- Docker test suite: **326 tests passed, 1,739 assertions**. Coverage includes detector equivalence for the latest transition, artifact rejection, draft isolation, shared sample caching, source changes, duplicate-producer prevention, and an actual ffmpeg producer's bounded history and lease cleanup.
- Native Chromium checks: **37 motion-editor assertions and 26 player checks passed**. The deployed modal was also checked at 1440, 390, 320, and 844 pixel viewport widths: no horizontal overflow, the mask retained the video's aspect ratio, and no browser exceptions occurred.
- The deployed test camera delivered **122 distinct confirmed samples over 22.57 seconds**, or **5.36 fresh updates/second**, after startup. Median analysis request time was **76.5 ms**, with **132 ms p95**. Median confirmed-frame age at the server was **415 ms**, maximum **565 ms**; this is not camera capture-to-screen latency. There were no waiting responses during that steady observation. Cold startup took about **2.54 seconds**.
- One shared PHP producer and one ffmpeg process were present during the observation. Their sampled lifetime-average CPU use was **2.3% and 3.3%**, with about **56 MiB and 57 MiB RSS**, respectively. These figures exclude HTTP workers and depend on the source and machine. Both processes were gone six seconds after the modal closed.
- Earlier snapshot observations produced roughly one distinct sample/second and repeated segment-rollover waits. Those observations were at a different time and load, so they are a directional comparison rather than a controlled benchmark.
- Deployed application, background worker, and relay: `rekanized/bigbrotha-app:20260910-motion-live-v2`, image `sha256:fbc0c1c7ea3891e3065fcf26a73c11df3124b137ca17ed926269201090e0aab2`. All four test services were healthy, the relay API was reachable, and eight deployed application files matched the workspace. The `actualbigbrotha` production container IDs, image IDs, and start times were unchanged from the September 10 pre-deployment baseline.
