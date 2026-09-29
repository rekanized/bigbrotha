---
name: recording-storage
description: Work on BigBrotha recording schedules, motion detection, queue jobs, timeline review, retention, or private and SMB file storage.
---

# Recording and storage

Read [workflow](../../../docs/camera-fleet-workflow.md) for operator policy and [constraints](../../../docs/known-issues-and-constraints.md) for SMB, worker, motion, and playback limits.

## Pipeline

- `Camera` stores mode (`off`, `continuous`, `motion`), profile, retention, and motion settings. `CameraRecording` stores each durable segment and status; `CameraMotionState` tracks the active motion event. Schema changes belong in `database/migrations/`.
- `routes/console.php` schedules `camera-recordings:tick` every minute and `camera-recordings:prune` hourly. The tick reconciles stale work, starts/inspects persistent continuous and motion segmenters, and dispatches queued captures. `ProcessCameraRecordingJob` uses a per-camera cache lock; `CameraRecordingService` orchestrates processing.
- `ContinuousRecordingSegmenterService` and `MotionRecordingSegmenterService` keep ffmpeg processes and local runtime buffers under private storage. `RecordingMotionDetectorService` and `RecordingMotionMaskService` apply the saved motion area/mask; motion events use pre-roll and extend until quiet post-trigger time. Recorder readers use a canonical internal MediaMTX source instead of taking an extra camera RTSP session.
- `GenerateRecordingReviewAssetsJob` and `RecordingReviewAssetService` make browser playback/review assets after save. `RecordingTimelineReviewService`, `RecordingController`, and `Livewire/Recordings/` power the private browser and timeline routes. The review job may normalize the durable recording path to playable MP4. Do not infer current format from historical `.mkv` examples.
- `CameraStorageService` owns path normalization, staging, verified writes, reads, cleanup, and SMB fallback. Only finished recording clips move to the optional `camera_private` SMB disk configured by `CameraStorageServiceProvider`; previews, review assets, motion buffers, and staging stay local. Database rows store relative `cameras/{id}/...` paths. Private files are served through authenticated controllers.

## Operations and checks

The `background` Supervisor runs one scheduler and workers on `recordings,default,review-assets`. `config/recording.php`, `config/queue.php`, and `config/ffmpeg.php` define timeouts, queue names, retry policy, binaries, and motion settings. Worker heartbeats and `camera-recordings:healthcheck {role}` distinguish a healthy container from a stuck recorder.

Use `tests/Feature/CameraRecording*Test.php`, `RecordingBrowserTest.php`, `TimelineReviewTest.php`, `RecordingWorkerCommandTest.php`, and `tests/Unit/RecordingMotionMaskServiceTest.php` for focused checks. Retention prune, orphan purge, queue reconciliation without `--dry-run`, and SQLite import change data; inspect the target stack before invoking them. Never put an SMB password or camera RTSP credentials in a command log.
