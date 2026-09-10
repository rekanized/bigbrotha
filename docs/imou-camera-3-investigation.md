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
