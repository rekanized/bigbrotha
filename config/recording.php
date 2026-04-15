<?php

return [
    'queue' => 'recordings',

    'segment_seconds' => max(15, (int) env('CAMERA_RECORDING_SEGMENT_SECONDS', 60)),

    'extension' => 'mkv',

    'job_timeout_seconds' => max(180, (int) env('CAMERA_RECORDING_JOB_TIMEOUT_SECONDS', 240)),

    'lock_seconds' => max(90, (int) env('CAMERA_RECORDING_LOCK_SECONDS', 180)),

    'stale_seconds' => max(300, (int) env('CAMERA_RECORDING_STALE_SECONDS', 420)),

    'worker' => [
        'ensure_running' => true,
        'container_mode' => true,
        'processes' => max(1, (int) env('CAMERA_RECORDING_WORKER_PROCESSES', 1)),
        'dynamic_enabled' => false,
        'max_processes' => max(1, (int) env('CAMERA_RECORDING_WORKER_PROCESSES', 1)),
        'cameras_per_process' => max(1, (int) env('CAMERA_RECORDING_WORKER_CAMERAS_PER_PROCESS', 4)),
        'jobs_per_process' => max(1, (int) env('CAMERA_RECORDING_WORKER_JOBS_PER_PROCESS', 200)),
        'queue' => env('CAMERA_RECORDING_WORKER_QUEUE', 'recordings,default,review-assets'),
        'max_jobs' => max(1, (int) env('CAMERA_RECORDING_WORKER_MAX_JOBS', 50)),
        'max_time' => max(60, (int) env('CAMERA_RECORDING_WORKER_MAX_TIME', 3600)),
        'memory' => max(64, (int) env('CAMERA_RECORDING_WORKER_MEMORY', 256)),
    ],

    'health' => [
        'worker_heartbeat_path' => env('CAMERA_RECORDING_WORKER_HEARTBEAT_PATH', storage_path('app/private/bootstrap/recordings-worker.heartbeat')),
        'worker_max_age_seconds' => max(
            120,
            (int) env('CAMERA_RECORDING_WORKER_HEALTH_MAX_AGE_SECONDS', max(
                (int) env('CAMERA_RECORDING_JOB_TIMEOUT_SECONDS', 240),
                (int) env('CAMERA_REVIEW_ASSET_JOB_TIMEOUT_SECONDS', 240)
            ) + 120)
        ),
        'worker_max_queued_age_seconds' => max(
            120,
            (int) env('CAMERA_RECORDING_WORKER_HEALTH_MAX_QUEUED_AGE_SECONDS', max(
                (int) env('CAMERA_RECORDING_STALE_SECONDS', 420),
                (int) env('CAMERA_RECORDING_JOB_TIMEOUT_SECONDS', 240) + 180
            ))
        ),
        'scheduler_heartbeat_path' => env('CAMERA_RECORDING_SCHEDULER_HEARTBEAT_PATH', storage_path('app/private/bootstrap/recordings-scheduler.heartbeat')),
        'scheduler_max_age_seconds' => max(
            90,
            (int) env('CAMERA_RECORDING_SCHEDULER_HEALTH_MAX_AGE_SECONDS', max(
                180,
                ((int) env('SCHEDULER_INTERVAL_SECONDS', 60)) * 3
            ))
        ),
        'scheduler_tick_heartbeat_path' => env('CAMERA_RECORDING_SCHEDULER_TICK_HEARTBEAT_PATH', storage_path('app/private/bootstrap/recordings-tick.heartbeat')),
        'scheduler_tick_max_age_seconds' => max(
            120,
            (int) env('CAMERA_RECORDING_SCHEDULER_TICK_HEALTH_MAX_AGE_SECONDS', max(
                240,
                ((int) env('SCHEDULER_INTERVAL_SECONDS', 60)) * 4
            ))
        ),
    ],

    'continuous' => [
        'segmenter_enabled' => filter_var(env('CAMERA_CONTINUOUS_SEGMENTER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'segment_time_delta' => max(0, (float) env('CAMERA_CONTINUOUS_SEGMENT_TIME_DELTA', 0.05)),
        'startup_delay_ms' => max(0, (int) env('CAMERA_CONTINUOUS_STARTUP_DELAY_MS', 250)),
        'runtime_dir' => env('CAMERA_CONTINUOUS_RUNTIME_DIR', storage_path('app/private/continuous-recorders')),
        'file_suffix' => env('CAMERA_CONTINUOUS_FILE_SUFFIX', 'continuous'),
    ],

    'motion' => [
        'segmenter_enabled' => filter_var(env('CAMERA_MOTION_SEGMENTER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'use_relay_source' => filter_var(env('CAMERA_MOTION_USE_RELAY_SOURCE', false), FILTER_VALIDATE_BOOL),
        'segment_seconds' => max(2, (int) env('CAMERA_MOTION_SEGMENT_SECONDS', 4)),
        'runtime_dir' => env('CAMERA_MOTION_RUNTIME_DIR', storage_path('app/private/motion-recorders')),
        'idle_buffer_seconds' => max(30, (int) env('CAMERA_MOTION_IDLE_BUFFER_SECONDS', 180)),
        'max_stitched_seconds' => max(60, (int) env('CAMERA_MOTION_MAX_STITCHED_SECONDS', 180)),
        'startup_delay_ms' => max(0, (int) env('CAMERA_MOTION_STARTUP_DELAY_MS', 150)),
        'segment_time_delta' => max(0, (float) env('CAMERA_MOTION_SEGMENT_TIME_DELTA', env('CAMERA_CONTINUOUS_SEGMENT_TIME_DELTA', 0.05))),
        'pre_roll_seconds' => max(0, (int) env('CAMERA_MOTION_PRE_ROLL_SECONDS', 8)),
        'post_trigger_seconds' => max(1, (int) env('CAMERA_MOTION_POST_TRIGGER_SECONDS', 20)),
        'analysis_seconds' => max(3, (int) env('CAMERA_MOTION_ANALYSIS_SECONDS', 5)),
        'analysis_fps' => max(1, (int) env('CAMERA_MOTION_ANALYSIS_FPS', 3)),
        'grid_width' => max(32, (int) env('CAMERA_MOTION_ANALYSIS_WIDTH', 160)),
        'grid_height' => max(18, (int) env('CAMERA_MOTION_ANALYSIS_HEIGHT', 90)),
        'pixel_delta_threshold' => max(1, (int) env('CAMERA_MOTION_PIXEL_DELTA_THRESHOLD', 18)),
        'isolated_pixel_radius' => max(1, (int) env('CAMERA_MOTION_ISOLATED_PIXEL_RADIUS', 1)),
        'persistence_window_frames' => max(1, (int) env('CAMERA_MOTION_PERSISTENCE_WINDOW_FRAMES', 2)),
        'refresh_spike_activity_ratio' => max(0.5, min(1.0, (float) env('CAMERA_MOTION_REFRESH_SPIKE_ACTIVITY_RATIO', 0.85))),
    ],

    'review_assets' => [
        'queue' => env('CAMERA_REVIEW_ASSET_QUEUE', 'review-assets'),
        'job_timeout_seconds' => max(180, (int) env('CAMERA_REVIEW_ASSET_JOB_TIMEOUT_SECONDS', 240)),
        'lock_seconds' => max(60, (int) env('CAMERA_REVIEW_ASSET_LOCK_SECONDS', 120)),
        'dispatch_suppression_seconds' => max(300, (int) env('CAMERA_REVIEW_ASSET_DISPATCH_SUPPRESSION_SECONDS', 1800)),
        'scheduler_enabled' => filter_var(env('CAMERA_REVIEW_ASSET_SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'scheduler_limit' => max(1, (int) env('CAMERA_REVIEW_ASSET_SCHEDULER_LIMIT', 4)),
        'store_sprites_locally' => filter_var(env('CAMERA_REVIEW_STORE_SPRITES_LOCALLY', true), FILTER_VALIDATE_BOOL),
        'preview_width' => max(192, (int) env('CAMERA_REVIEW_PREVIEW_WIDTH', 640)),
        'preview_fps' => max(2, (int) env('CAMERA_REVIEW_PREVIEW_FPS', 8)),
        'scrub_frame_interval_seconds' => max(1, (int) env('CAMERA_REVIEW_SCRUB_INTERVAL_SECONDS', 10)),
        'scrub_frame_width' => max(96, (int) env('CAMERA_REVIEW_SCRUB_FRAME_WIDTH', 128)),
        'scrub_frame_height' => max(54, (int) env('CAMERA_REVIEW_SCRUB_FRAME_HEIGHT', 72)),
        'scrub_columns' => max(2, (int) env('CAMERA_REVIEW_SCRUB_COLUMNS', 4)),
        'keyframe_interval_seconds' => max(1, (int) env('CAMERA_REVIEW_KEYFRAME_INTERVAL_SECONDS', 1)),
        'video_crf' => max(16, (int) env('CAMERA_REVIEW_VIDEO_CRF', 28)),
        'playback_preset' => env('CAMERA_REVIEW_PLAYBACK_PRESET', 'medium'),
        'playback_video_crf' => max(18, (int) env('CAMERA_REVIEW_PLAYBACK_VIDEO_CRF', 26)),
        'playback_video_max_bitrate' => env('CAMERA_REVIEW_PLAYBACK_VIDEO_MAX_BITRATE', '1500k'),
        'playback_video_buffer_size' => env('CAMERA_REVIEW_PLAYBACK_VIDEO_BUFFER_SIZE', '3000k'),
        'playback_fps' => max(10, (int) env('CAMERA_REVIEW_PLAYBACK_FPS', 20)),
        'playback_audio_bitrate' => env('CAMERA_REVIEW_PLAYBACK_AUDIO_BITRATE', '96k'),
        'cache_max_entries' => max(12, (int) env('CAMERA_REVIEW_CACHE_MAX_ENTRIES', 36)),
        'cache_max_bytes' => max(16777216, (int) env('CAMERA_REVIEW_CACHE_MAX_BYTES', 157286400)),
    ],
];