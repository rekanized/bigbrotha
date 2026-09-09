# Motion triggering audit — September 2026

## Data path and meaning

1. The camera's saved recording RTSP profile enters the canonical MediaMTX source path. BigBrotha does not subscribe to the camera's ONVIF motion alarms for this workflow.
2. `MotionRecordingSegmenterService` copies that source into a rolling local Matroska buffer. Four-second target segments use packet flushing and 250 ms clusters so the current file exposes decodable video before it closes.
3. `RecordingMotionDetectorService` decodes low-resolution grayscale frames at the configured analysis rate (default 3 fps), applies the painted selection, removes isolated clusters, rejects camera-wide exposure/refresh artifacts, and weights accepted connected clusters. At default weighting, a connected group of four changed cells contributes eight effective trigger pixels.
4. The recording worker evaluates closed segments using saved settings. A qualifying segment opens or extends an event, with pre-roll and a resettable quiet deadline. The event eventually stitches into a saved clip. Segment boundaries, worker timing, and event rollover affect exact clip duration.
5. The authenticated motion-analysis POST evaluates a private, bounded snapshot of the current recorder file with an unsaved copy of the submitted mask and threshold. It never saves camera settings, opens another camera RTSP connection, or starts an event.
6. The modal's WebRTC video is a separately timed display of the saved recording path. Blue cells are selected; amber/red cells are accepted movement in the latest confirmed transition. Red means the draft threshold is met. The buffer peak and the saved recording event are separate facts.

## Improvements

- Actual moving cells and their share of the selected area are separate from the weighted trigger count. The threshold meter makes tuning easier; help explains that lowering the value increases sensitivity.
- Buffer-peak text no longer describes a quiet current overlay as current movement. The saved event remains separate even while a draft is being tested. Filtered noise, image refreshes, and broad image changes have explicit explanations.
- Event timing help describes pre-roll and a resettable quiet tail, replacing the incorrect fixed clip-length promise.
- `MotionEditorSampleService` shares the most recent analysis across requests with the same settings. One file-cache entry per camera avoids accumulating entries for every brush stroke. Unchanged input is reused, and growing input with identical settings is sampled at most once per analysis-frame interval. A non-blocking, independent camera lock prevents simultaneous editor decoders. A busy request waits on the next poll without holding a PHP worker in a lock wait.
- Snapshots copy only the byte count observed before opening the file. Source handles, snapshots, and locks are released after success, partial writes, or detector errors.
- Video freshness is tracked by segment plus decoded frame count on the server. Audio growth, cache hits, different drafts, or reopening a modal cannot repeatedly renew the same sample. Both server and browser expire samples after three seconds without progress; request latency is accounted for conservatively in the browser.
- Empty selections bypass video work. Unavailable measurements show an em dash and an empty meter instead of a misleading measured zero. A short rollover wait retains the previous confirmed overlay for at most 1.5 seconds.
- Decoding, filtering, and raw-video output use bounded ffmpeg threading. The unused `showinfo` filter and the legacy log-text-as-motion fallback are removed. There must be real decoded video to qualify movement. Artifact rejection precedes the second cluster traversal used for weighting.
- Mask run encoding and selected-cell counts are cached between edits, and canvas image buffers are reused. Painting invalidates old responses immediately, but defers serialization and new analysis until the stroke ends; unchanged strokes do not rebuild the mask.
- Polling remains single-flight, stops for hidden/detached editors, and backs off after transient failures. Authentication/permission/validation failures suspend futile retries; expired-session help explains recovery without automatically discarding the draft.

## Limits

This is sampled near-live recorder activity, not pixel-perfect synchronization with the WebRTC frame currently displayed. Default sampling is 3 fps and browser polling targets 650 ms; camera delivery, Matroska clustering, decoder lookahead, request time, and segment rollover add latency. Sample age measures observed recorder video progress, not camera capture-to-screen latency.

Preview analysis withholds the newest transition until at least one future frame is present and uses additional configured lookahead where available. A growing segment is provisional; only the worker's analysis of closed segments controls durable recordings. The editor cannot analyze an unsaved motion mode or a disabled camera. Broad real scene changes can also be rejected by the configured artifact gates; this remains pixel-change detection, not object recognition.

The local file cache is appropriate to this Docker deployment's shared local storage. Its 60-second retention bounds stale entries; it is not a transport for multi-host sampling. A different draft must be analyzed independently. Detector timeouts and the lock prevent overlapping work, but high-resolution source decoding and large masks still consume CPU.

## Validation

Validation results and deployment identity are recorded below after the final build.
