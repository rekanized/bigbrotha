<?php

return [
    'queue' => env('CAMERA_RECORDING_QUEUE', 'recordings'),

    'segment_seconds' => max(15, (int) env('CAMERA_RECORDING_SEGMENT_SECONDS', 60)),

    'extension' => 'mkv',

    'job_timeout_seconds' => max(180, (int) env('CAMERA_RECORDING_JOB_TIMEOUT_SECONDS', 240)),

    'lock_seconds' => max(90, (int) env('CAMERA_RECORDING_LOCK_SECONDS', 180)),

    'stale_seconds' => max(300, (int) env('CAMERA_RECORDING_STALE_SECONDS', 420)),

    'worker' => [
        'ensure_running' => filter_var(env('CAMERA_RECORDING_ENSURE_WORKER', false), FILTER_VALIDATE_BOOL),
        'systemd_service' => env('CAMERA_RECORDING_WORKER_SYSTEMD_SERVICE', 'bigbrothas-recordings-queue.service'),
        'systemd_user_dir' => env('CAMERA_RECORDING_WORKER_SYSTEMD_USER_DIR', rtrim((string) env('HOME', storage_path('app/private')), '/').'/.config/systemd/user'),
        'systemctl_binary' => env('CAMERA_RECORDING_WORKER_SYSTEMCTL_BINARY', '/usr/bin/systemctl'),
        'ps_binary' => env('CAMERA_RECORDING_WORKER_PS_BINARY', '/usr/bin/ps'),
        'shell_binary' => env('CAMERA_RECORDING_WORKER_SHELL_BINARY', '/bin/sh'),
        'php_binary' => env('CAMERA_RECORDING_WORKER_PHP_BINARY', PHP_BINARY),
        'queue' => env('CAMERA_RECORDING_WORKER_QUEUE', env('CAMERA_RECORDING_QUEUE', 'recordings').',default'),
        'max_jobs' => max(1, (int) env('CAMERA_RECORDING_WORKER_MAX_JOBS', 50)),
        'max_time' => max(60, (int) env('CAMERA_RECORDING_WORKER_MAX_TIME', 3600)),
        'memory' => max(64, (int) env('CAMERA_RECORDING_WORKER_MEMORY', 256)),
        'fallback_start' => filter_var(env('CAMERA_RECORDING_WORKER_FALLBACK_START', false), FILTER_VALIDATE_BOOL),
        'log_path' => env('CAMERA_RECORDING_WORKER_LOG_PATH', storage_path('logs/recordings-queue-worker.log')),
    ],

    'motion' => [
        'analysis_seconds' => max(3, (int) env('CAMERA_MOTION_ANALYSIS_SECONDS', 5)),
        'analysis_fps' => max(1, (int) env('CAMERA_MOTION_ANALYSIS_FPS', 3)),
        'scaled_width' => max(96, (int) env('CAMERA_MOTION_ANALYSIS_WIDTH', 320)),
    ],

    'review_assets' => [
        'queue' => env('CAMERA_REVIEW_ASSET_QUEUE', env('CAMERA_RECORDING_QUEUE', 'recordings')),
        'job_timeout_seconds' => max(180, (int) env('CAMERA_REVIEW_ASSET_JOB_TIMEOUT_SECONDS', 240)),
        'lock_seconds' => max(60, (int) env('CAMERA_REVIEW_ASSET_LOCK_SECONDS', 120)),
        'preview_width' => max(192, (int) env('CAMERA_REVIEW_PREVIEW_WIDTH', 640)),
        'preview_fps' => max(2, (int) env('CAMERA_REVIEW_PREVIEW_FPS', 8)),
        'thumbnail_width' => max(120, (int) env('CAMERA_REVIEW_THUMBNAIL_WIDTH', 320)),
        'scrub_frame_interval_seconds' => max(1, (int) env('CAMERA_REVIEW_SCRUB_INTERVAL_SECONDS', 2)),
        'scrub_frame_width' => max(96, (int) env('CAMERA_REVIEW_SCRUB_FRAME_WIDTH', 160)),
        'scrub_frame_height' => max(54, (int) env('CAMERA_REVIEW_SCRUB_FRAME_HEIGHT', 90)),
        'scrub_columns' => max(2, (int) env('CAMERA_REVIEW_SCRUB_COLUMNS', 4)),
        'keyframe_interval_seconds' => max(1, (int) env('CAMERA_REVIEW_KEYFRAME_INTERVAL_SECONDS', 1)),
        'video_crf' => max(16, (int) env('CAMERA_REVIEW_VIDEO_CRF', 28)),
        'cache_max_entries' => max(12, (int) env('CAMERA_REVIEW_CACHE_MAX_ENTRIES', 36)),
        'cache_max_bytes' => max(16777216, (int) env('CAMERA_REVIEW_CACHE_MAX_BYTES', 157286400)),
    ],
];