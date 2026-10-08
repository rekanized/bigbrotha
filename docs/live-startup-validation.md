# Live-wall startup validation — 2026-10-08

The implementation retains live outputs for 120 seconds after their last reader,
reduces tile startup/resume spacing from 150 ms to 50 ms (maximum 500 ms), and
serves a pinned MediaMTX v1.21.1 reader with shared codec capability detection.
`MEDIAMTX_LIVE_CLOSE_AFTER` overrides live retention; shared-source retention
remains 30 seconds after its own final reader disconnects.

## Startup measurements

Twenty trials per state and implementation used Chromium at 1280×800, an
isolated MediaMTX v1.21.1 relay and the application's generated FFmpeg commands.
Eight independent source/live pipelines received two synthetic 640×360, 15 fps
camera publishers: four outputs copied H.264 and four transcoded H.264 with
B-frames. AAC input audio was converted to Opus. All times below are seconds
from browser navigation to presented video, measured with video-frame callbacks.

| Initial pipeline state | First frame median, before → after | First frame p95, before → after | All eight median, before → after | All eight p95, before → after |
| --- | --- | --- | --- | --- |
| Both relay stages cold | 2.690 → 2.669 | 2.731 → 2.732 | 5.863 → 5.723 | 6.232 → 6.082 |
| Shared sources already running | 1.435 → 1.618 | 1.643 → 1.641 | 3.882 → 3.676 | 3.997 → 3.884 |
| Live outputs already running | 0.481 → 0.497 | 0.609 → 0.643 | 2.763 → 1.017 | 2.950 → 2.280 |

All 120 before/after trials presented all eight videos and exposed all eight
audio tracks, with no captured JavaScript errors. The median time to fill the
wall improved approximately **63% for already-running live outputs**, **5% for
already-running shared sources**, and **2% for completely cold relay pipelines**.
The 25% target was met for the first case only. A return within the new retention
window can use the already-running case instead of restarting the pipelines.

The initial page created 32 peer connections before the changes and 11 after:
eight actual receivers plus three shared capability probes, previously 24 probes.
The first-camera result did not consistently improve. Keyframe arrival and
pipeline startup remain significant constraints.

| Initial pipeline state | Median relay CPU seconds per startup, before → after | Median relay CPU utilization, before → after |
| --- | --- | --- |
| Both stages cold | 5.015 → 5.124 | 86.0% → 88.7% |
| Shared sources running | 3.799 → 3.702 | 96.2% → 97.9% |
| Live outputs running | 2.401 → 1.152 | 85.2% → 106.3% |

CPU values come from the isolated container's cgroup and include synthetic
publishers and prewarming readers; they exclude Chromium. 100% represents one
CPU core. The shorter warm-start measurement has higher average utilization but
lower total CPU time. Keeping outputs warm still consumes resources between
visits. These sequential trials ran on a shared host, not a dedicated benchmark
machine; some baseline trials overlapped regression/retention checks. Treat
small differences as inconclusive. Browser caches were disabled by the fixture.
The fixture excludes Laravel rendering, production authentication round trips,
real camera firmware and WAN delays. It does not establish production timings
or mobile performance.

## Shorter probing rejected

The same improved player was tested with 250 ms live-input analysis, retaining
the existing probe size and source settings. Twenty cold trials produced two
25-second timeouts with one camera missing both tracks. Successful cold trials
had a 5.261-second median for all eight frames, versus 5.723 seconds with the
one-second setting. Twenty trials with sources already running had no startup
failures and a 3.123-second median.

The following already-running-live-output experiment failed during prewarming
before browser navigation; that incomplete trial is not included in the timing
tables. The experiment did not meet the reliability requirement. The default
`FFMPEG_LIVE_ANALYZE_DURATION=1000000` is unchanged. No faster default is claimed.

## Retention and regression checks

In a separate real-time relay exercise, two live outputs stopped 120.22 seconds
after their readers disconnected. Their unused source stopped at 151.27 seconds,
including its independent 30-second grace period. A source with a continuous
recording-like reader remained online throughout the 155-second observation.

A separate eight-player exercise observed 444–449 decoded frames per output
over 30 seconds, with zero new WebRTC freeze events and continuing audio packets
on every output. Focus/restore resumed all feeds in 2.204 seconds, background
suspension/resume in 1.504 seconds, and simulated Livewire wall switching in
1.907 seconds. An expired bootstrap token fetched a fresh fixture session;
an unavailable path retried independently while the other seven kept playing.
Teardown left zero open peer connections. These lifecycle timings are single
checks, not the repeated benchmark medians above.

- 470 PHP tests passed, with 2,481 assertions, using in-memory SQLite in the
  isolated Docker test image. This includes timestamp-spacing, sparse-audio,
  relay configuration, session authorization and unavailable-camera tests.
- 45 browser checks passed at both 1280×800 and 390×844. New checks cover the
  bounded startup/resume stagger and capability sharing, including cancellation
  of one reader while other readers await the shared probes.
- PHP formatting, Compose checks and whitespace checks passed.

## Repeating the measurements

See [the benchmark runner](../tests/Performance/README.md). It generates synthetic
inputs, records individual trials and reports medians, nearest-rank p95 and CPU
usage. Run the browser regression fixture at
`tests/Browser/live-wall-player.html` for checks without cameras or sign-in.

No production deployment, camera configuration or database changes were made.
