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
