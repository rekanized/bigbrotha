# Live playback validation — 2026-10-06

The playback changes preserve media timestamps across relay hops, bound software
decoder/filter concurrency, and prevent unnecessary browser receiver restarts.
They also fix stale session/track callbacks, repeated use of rejected bootstrap
tokens, background-tab stall detection, modal receiver cleanup, and audio controls
that could resume paused playback.

## Automated checks

- Current workspace: **469 PHP tests passed, 2,471 assertions**, using the isolated
  Docker test image and an in-memory SQLite database.
- **36 browser checks passed at each of 1280×800 and 390×844**, using Chromium.
  Open `tests/Browser/live-wall-player.html` to repeat them without cameras.
- PHP formatting for the changed PHP files, Compose checks, and `git diff --check`
  passed.
- The new FFmpeg burst-arrival regression fails with the previous live timestamp
  default and passes with timestamp preservation. Both relay hops retain all 30
  fixture frames and their 15 fps presentation spacing.
- The existing sparse-audio integration test passes: a three-second audio gap
  does not hold back live video for seconds.

## Synthetic WebRTC exercise

An isolated MediaMTX instance received generated 640×360, 15 fps video with audio
through the application's FFmpeg source and live commands. Eight Chromium tiles
shared two sources: four copied H.264 and four transcoded H.264 with B-frames.
No camera feeds, deployment databases, or production relay settings were changed.

Replacing timestamps with packet-arrival times produced 31 near-zero frame
intervals in a 73-interval sample. Preserving timestamps produced 73 evenly
spaced intervals, each rounding to 67 ms.

In the final run, after 30 seconds of startup settling, all eight receivers
reported 15 fps and zero new WebRTC freeze events over a 60-second observation.
Six receivers had zero new RTP frame drops; two had one each. Chromium's separate
presentation counters reported 1–47 dropped frames per tile, so this is not a
claim of frame-perfect rendering on every browser or device.

Repeated initialization preserved the existing receivers. Focusing a tile
released the other seven receivers; restoring the wall resumed all feeds in
approximately 2.9 seconds. Restarting the isolated relay recovered all eight
feeds automatically in approximately 23.8 seconds. Page teardown closed all
eight peer connections, with zero remaining open connections.

A separate five-second decoder fixture retained all 75 frames while reducing
process threads from 13 with automatic decoder threading to 6 with two decoder
threads. This is a concurrency measurement, not a production CPU benchmark.

## Deployment scope

These checks validate the workspace changes and synthetic playback. The running
production stack was inspected and remained healthy; it was not redeployed.
Timestamp compatibility overrides and the independent recording timestamp policy
are documented in `known-issues-and-constraints.md`.
