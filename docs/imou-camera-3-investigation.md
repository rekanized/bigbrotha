# Camera 3 playback investigation — 10 September 2026

## Result

The recurring freeze is reproducible at `https://monitor-test.schollinetz.com/live-wall/3/player`. Incoming camera traffic pauses before the canonical source, live transcoder, and WebRTC receiver. Ordinary ICMP echo replies pause at the same time and then arrive together. This is a camera or camera-network delivery interruption, not evidence of a browser codec failure. The investigation did **not** establish whether the remaining cause is camera firmware, its network interface, or the access network. No application workaround tested here produced consistently smooth playback, so none was retained.

The September 6–7 investigation used an IMOU in production. Camera **3 now exists in the test inventory**. This investigation used that test object and did not modify production `actualbigbrotha`, camera encoder settings, firmware, or network configuration.

## Current camera and delivery path

- Model: IPC-C26E-V2; source: 1920×1080 H.264 Main, about 10 fps, no detected B-frames; audio: G.711 μ-law.
- The test camera already has forced H.264 compatibility transcoding enabled, with a 4,000 kbps CBR target. Its live output is H.264 Baseline plus Opus. Merely enabling compatibility mode therefore cannot address this incident.
- Recording is off for test Camera 3.
- Hardware RTSP → `camera-3-source-profile-0` → shared FFmpeg transcoder → `camera-3-live` → authenticated WHEP → Chromium.
- ONVIF reports one enabled interface named `eth2`. This name alone does not establish whether the actual connection is Ethernet or Wi-Fi.

## Measurements

Measurements were made on the test deployment using packet timing, media timestamps, and browser receiver statistics. No camera footage was saved for this investigation.

| Observation | Result |
| --- | --- |
| Authenticated browser baseline, 120 seconds | 1,640 presented frames; largest presentation gap 3.000 seconds; video receiver reported 8 freezes totaling 10.642 seconds |
| WebRTC transport in that baseline | Zero reported audio or video packet loss |
| Canonical source packet delivery | Repeated gaps of 3.113–3.182 seconds in video delivery, also affecting audio |
| Live relay packet delivery | Matching gaps of 3.123–3.186 seconds |
| Host-interface incoming RTSP TCP traffic | Concurrent camera connections stopped receiving payloads for approximately 3.12 seconds together |
| Direct UDP RTSP comparison, approximately 95 seconds | Repeated video gaps of 3.154–3.155 seconds; changing transport does not remove the interruption |
| ICMP, 350 requests at 200 ms spacing | 350 replies, zero loss, median approximately 3.37 ms, maximum approximately 3,140 ms |
| ICMP interruption timing | Two reply bursts approximately 34.26 seconds apart; queued replies arrived with RTTs descending from about 3.1 seconds to normal latency |

For example, queued ping replies returned together at Unix times `1789048337.83` and `1789048372.09`. A direct UDP video gap ended at `1789048337.77`, within about 60 ms of the first ping-reply burst. This independent network-level correlation is the key evidence: changing HTML playback or H.264 encoding in the application cannot prevent that loss of timely delivery.

The source media timestamps continued approximately normally through the interruptions. Queued frames arrived afterward, explaining the visible catch-up. This distinguishes late delivery from proof that the camera stopped encoding frames.

## Rejected application workarounds

Comparisons reused the test canonical source through temporary authenticated relay paths. One bounded direct UDP read tested transport independently. Temporary paths and browser sessions were removed afterward.

- A four-second browser jitter-buffer target, with both existing and native relay timestamps, still stalled and dropped frames.
- Native timestamps plus paced FFmpeg input and larger startup probing reduced some gaps but did not eliminate recurring stalls.
- Reordering delayed audio/video at the source before pacing playback improved the initial observation. The longer 180-second run still had a 1.717-second maximum presentation gap and 39 receiver freezes totaling 20.461 seconds, with 256 dropped video frames. These runs differ in duration and startup, so the raw totals are not a controlled performance score; they are sufficient to reject a claim of smooth playback.
- A video-only comparison was exploratory and was not retained: removing operator audio is not a satisfactory fix.

The experimental smoothing setting, generated-command changes, and associated tests were removed. Original source/live commands were restored, and the final Docker image contains the established playback implementation. No camera metadata was changed.

## Required next diagnostic

Check the camera connection independently of this server's video application. If wireless, compare a wired connection or a nearby dedicated access point while running the same ping and browser observation. If already wired, compare the cable, switch port, and a second LAN host. Inspect camera and access-point/switch logs around the 34-second cycle. Roaming, scanning, power saving, firmware behavior, and interface faults are hypotheses, not established diagnoses.

Use at least five minutes of simultaneous ping and live playback after changing the connection. A successful repair must eliminate the recurring multi-second ping/video pauses without relying on extra playback delay. Do not change camera firmware or network settings shared with production without establishing the relevant deployment boundary.

For reproducible packet measurements, collect arrival times separately from media PTS/DTS using `ffprobe -show_packets`; ensure standard-output buffering is accounted for. Use the existing internal authenticated source for routine probes, and keep RTSP credentials out of reports and shell output. A packet-only host capture and `ping -D -i 0.2` provide the independent network correlation. Browser `requestVideoFrameCallback` and inbound RTP statistics distinguish presentation gaps, dropped frames, and transport loss.

## References

- [Previous live streaming audit](live-streaming-audit.md).
- [FFmpeg format options](https://ffmpeg.org/ffmpeg-formats.html): timestamp replacement and interleaving semantics.
- [FFmpeg 5.1 input pacing implementation](https://github.com/FFmpeg/FFmpeg/blob/n5.1.8/fftools/ffmpeg.c): real-time input pacing is based on input media timestamps.
- [MediaMTX WebRTC codec constraints](https://mediamtx.org/docs/features/webrtc-specific-features).

## Follow-up — 16 September 2026

The issue remains reproducible on the `bigbrotha` test stack. Read-only ONVIF
`GetNetworkInterfaces` now confirms an IEEE 802.11 configuration on `eth2`,
and two successful `GetDot11Status` requests report signal strength **Bad**.
An intervening attempt timed out. This establishes wireless operation and a
reported poor signal; it does not prove whether radio conditions, access-point
behavior, or camera firmware causes each interruption. No camera or access-point
configuration was changed. The operator cannot change the connection now.

Synchronized measurements through the public test site and existing canonical
source produced these results:

| Measurement | Result |
| --- | --- |
| Standalone Chromium player, 360 seconds | 4,890 presented frames; maximum presentation gap 3.067 seconds; 21 receiver freezes totaling 40.785 seconds; 28 dropped video frames |
| WebRTC transport in that run | Zero reported audio or video packet loss |
| Canonical source video arrival | Maximum gap 3.194 seconds |
| Live relay video arrival | Maximum gap 3.203 seconds |
| Camera ping, 1,700 requests at 200 ms spacing | 1,687 replies; median 3.37 ms; maximum 3,074.216 ms; 178 replies above 100 ms |
| Gateway control ping, 1,700 requests | All replies received; median 0.688 ms; maximum 8.209 ms |
| Passive host-interface capture | Two existing camera TCP connections paused together, repeatedly for approximately 3.1 seconds |

The packet probes used line-buffered ffprobe output to distinguish media PTS
from arrival time. The passive capture retained packet-header text only, and
the probes did not save footage or open additional hardware RTSP sessions.
For example, both captured camera connections resumed at Unix time
`1789559495.558` after a 3.162-second payload gap; the canonical video resumed
at `1789559495.564`, followed by the live relay at `1789559495.590`.
This ordering places the interruption before Docker ingest and transcoding.

A temporary derived path tested native input timestamps, real-time pacing,
eight seconds of startup probing, and 10 fps output matching the camera.
Its packet output still paused for up to 1.447 seconds. Adding a four-second
browser jitter-buffer target also produced visible pauses and dropped frames.
In the 240-second side-by-side browser comparison, the normal path reported
23 freezes totaling 33.763 seconds and 111 dropped video frames; the buffered
path reported 15 freezes totaling 21.511 seconds and 223 dropped video frames.
The buffered path's initial four-second presentation gap includes buffer
startup, but later multi-second gaps also occurred. Both paths reported zero
video packet loss. These totals describe that observation, not a general
performance guarantee or an acceptable latency tradeoff.
These experiments did not meet the smooth-playback requirement and must not
be enabled as a fix. They used the existing canonical source and did not alter
the normal camera path, camera metadata, or saved recording policy.

The relay and live-wall Docker regression subset passed **47 tests and 287
assertions**, including the real FFmpeg sparse-audio buffering regression.
Passing these tests does not establish smooth camera playback. The next
meaningful repair remains an improved camera connection followed by at least
five minutes of simultaneous network and presented-frame measurements.

The final normal two-camera wall observation lasted 180 seconds. Camera 3
presented 915 frames and suffered a **100.479-second presentation gap**.
Relay logs show source and live-publisher I/O timeouts, followed by automatic
restart and browser recovery. This gap includes relay/browser recovery time;
it must not be described as a measured 100-second Wi-Fi blackout. Camera 2
remained connected, presented 2,427 frames, and had a maximum presentation gap
of 0.417 seconds. Receiver counters reset when camera 3 reconnected, so its
last connection's freeze totals cannot represent the entire wall observation.

Camera focus, exclusive audio selection, and simulated browser offline/online
recovery succeeded; both receivers resumed presenting frames. No unhandled
JavaScript errors were observed. All **26 browser regression checks** passed,
and an unauthenticated camera-session request returned HTTP 401. The test
stack was healthy after deployment, and production container IDs, image IDs,
and start times were unchanged. Temporary experimental paths and diagnostic
sessions were removed. Playback is still **not fixed**.

The test image `rekanized/bigbrotha-app:20260916-camera3-audit-final` derives from
the deployed `20260910-motion-live-v2` image and adds this investigation
record at `/app/docs/imou-camera-3-investigation.md`. Application code,
dependencies, and media binaries are inherited unchanged. No buffering
workaround is enabled. Existing uncommitted recording/storage work is
excluded from this image. This is an investigation build, not a playback fix.
