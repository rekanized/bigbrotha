# Live-wall startup benchmark

This Linux-only runner uses Docker, Chromium, Python 3 and the Python
`websocket-client` package. It creates synthetic camera inputs, eight independent
source/live pipelines and eight browser players. Four outputs copy H.264 and
four transcode H.264 with B-frames; all carry AAC input converted to Opus.
It does not load deployment databases, credentials, recordings or cameras.

Build the test image and choose the revision preceding the startup changes:

```sh
docker build --target test -t bigbrotha:startup-test .
python3 tests/Performance/live-startup.py --before-ref <baseline-commit> --trials 20
```

Run from the repository root. Use `--chromium /path/to/chromium` and `--image`
to override the installed browser or test image. The test requires unused local
TCP ports 28554, 29997, 28889, 28190, 29180 and 29322, and UDP port 28190.
The relay uses host networking with loopback listeners. The runner refuses
occupied TCP ports and cleans up the container it created when it exits.

Each trial resets the isolated relay, starts the synthetic camera publishers,
and navigates to a fresh page. It measures three states separately:

- **cold:** both application relay stages stopped;
- **source-warm:** recording-like readers already hold all shared sources;
- **live-warm:** readers already hold all browser-compatible outputs.

The variants are the baseline player/upstream reader, the current player/shared
reader with one-second analysis, and the current player/shared reader with
250 ms analysis. `--variants after --trials 1` runs a short smoke test.

For sustained reception and server-side WHEP session cleanup checks, run:

```sh
python3 tests/Performance/live-startup.py --before-ref HEAD --variants after \
  --trials 1 --observe-seconds 30 --output tmp/live-playback-check
```

The optional observation settles playback for five seconds, then records each
receiver's frame, freeze, RTP packet, audio concealment and jitter-buffer deltas.
It checks that all eight receivers keep both media tracks flowing and that no
WebRTC sessions remain three seconds after teardown. Observation failures produce
a nonzero exit status; freeze counts are reported separately because rendering
performance depends on the test machine. Startup CPU/timing measurements exclude
the observation window. These counters verify media reception, not audible
speaker output or end-to-end camera latency.

The default output directory is `tmp/live-startup-benchmark`; use a new
`--output` directory for each comparison. `results.json` contains every trial,
`summary.json` contains medians and nearest-rank p95, and per-trial logs preserve
relay diagnostics. Timing starts at browser navigation and ends at presented
video frames, with a 25-second failure limit. Audio-track presence, JavaScript
errors and peer-connection counts are captured. CPU is the isolated container's
cgroup CPU time divided by measurement duration; 100% means one full core and
includes synthetic publishers and prewarming readers, but excludes Chromium.

These are local synthetic playback measurements, excluding Laravel page render,
real camera firmware, WAN delays and user-device performance. Do not run other
load tests during a comparison. Keep longer playback, lifecycle and retention
checks separate from this startup benchmark.

# PHP hot-path benchmarks

Run these inside the PHP 8.5 development/test image with the current source:

```sh
php tests/Performance/php-hot-paths.php
php tests/Performance/queue-summary.php
```

The first script constructs synthetic grayscale frames, masks and timestamps.
It does not boot application providers or connect to storage, cameras or a
database. Pass a case name, such as `timezone_format` or
`motion_latest_180_frames`, to run only that case. It reports the median of five
per-iteration timing samples, peak additional PHP allocation after warmup, and a
hash of the returned result. The mask case uses the maximum supported 640×640
grid; motion cases use the default 160×90 grid and 180 frames. Identical quiet
frames exercise a fast path; noisy camera footage will have different gains.

The queue script invokes the application's summary method against a private,
in-memory SQLite database containing 5,000 jobs across four queues, each with an
8 KiB synthetic payload. It reports the median of seven runs and the same memory
and result-hash measurements. It never reads deployment database settings.

For comparisons, use the same PHP image, INI settings, fixture script and quiet
host for both source revisions. Compare result hashes as well as timings. To
measure the CLI optimizer separately, run the first script with
`php -d opcache.enable_cli=1`; this does not change deployment configuration.
These are component measurements, not end-to-end application latency or process
RSS. See [the PHP 8.5 review](../../docs/php85-performance-review.md) for the
measured results and the wider validation performed.
