<?php

$defaultFfmpegBinary = base_path('bin/ffmpeg');
$defaultFfprobeBinary = base_path('bin/ffprobe');

return [
    'timeout' => (int) env('FFMPEG_TIMEOUT', 3600),

    'temporary_directory' => env('FFMPEG_TEMPORARY_DIRECTORY', storage_path('app/private/ffmpeg-temp')),

    'ffmpeg' => [
        'binaries' => array_values(array_filter([
            env('FFMPEG_BINARIES', $defaultFfmpegBinary),
            'ffmpeg',
        ])),
        'threads' => max(1, (int) env('FFMPEG_THREADS', 2)),
    ],

    'ffprobe' => [
        'binaries' => array_values(array_filter([
            env('FFPROBE_BINARIES', $defaultFfprobeBinary),
            'ffprobe',
        ])),
    ],

    'streaming' => [
        'rw_timeout' => max(1000000, (int) env('FFMPEG_STREAM_RW_TIMEOUT', 10000000)),
        'input_probe_size' => max(1024, (int) env('FFMPEG_STREAM_PROBE_SIZE', 32768)),
        'input_analyze_duration' => max(0, (int) env('FFMPEG_STREAM_ANALYZE_DURATION', 0)),
        'wall_fps' => max(1, (int) env('FFMPEG_LIVE_WALL_FPS', 4)),
        'wall_mjpeg_quality' => min(31, max(2, (int) env('FFMPEG_LIVE_WALL_MJPEG_QUALITY', 7))),
        'relay_fragment_duration' => max(100000, (int) env('FFMPEG_LIVE_RELAY_FRAGMENT_DURATION', 500000)),
    ],

    'recording' => [
        'rw_timeout' => max(1000000, (int) env('FFMPEG_RECORDING_RW_TIMEOUT', env('FFMPEG_STREAM_RW_TIMEOUT', 20000000))),
        'thread_queue_size' => max(8, (int) env('FFMPEG_RECORDING_THREAD_QUEUE_SIZE', 1024)),
        'rtbufsize' => (string) env('FFMPEG_RECORDING_RTBUF_SIZE', '128M'),
        'input_probe_size' => max(1024, (int) env('FFMPEG_RECORDING_PROBE_SIZE', 262144)),
        'input_analyze_duration' => max(0, (int) env('FFMPEG_RECORDING_ANALYZE_DURATION', 1000000)),
        'input_fflags' => trim((string) env('FFMPEG_RECORDING_INPUT_FFLAGS', '+genpts+discardcorrupt')),
        'use_wallclock_timestamps' => filter_var(env('FFMPEG_RECORDING_USE_WALLCLOCK_TIMESTAMPS', true), FILTER_VALIDATE_BOOL),
        'fps_mode' => trim((string) env('FFMPEG_RECORDING_FPS_MODE', 'passthrough')),
        'avoid_negative_ts' => trim((string) env('FFMPEG_RECORDING_AVOID_NEGATIVE_TS', 'make_zero')),
        'max_muxing_queue_size' => max(32, (int) env('FFMPEG_RECORDING_MAX_MUXING_QUEUE_SIZE', 1024)),
    ],

    'motion' => [
        'rw_timeout' => max(1000000, (int) env('FFMPEG_MOTION_RW_TIMEOUT', env('FFMPEG_RECORDING_RW_TIMEOUT', env('FFMPEG_STREAM_RW_TIMEOUT', 20000000)))),
        'thread_queue_size' => max(8, (int) env('FFMPEG_MOTION_THREAD_QUEUE_SIZE', 1024)),
        'rtbufsize' => (string) env('FFMPEG_MOTION_RTBUF_SIZE', '64M'),
        'input_probe_size' => max(1024, (int) env('FFMPEG_MOTION_PROBE_SIZE', 131072)),
        'input_analyze_duration' => max(0, (int) env('FFMPEG_MOTION_ANALYZE_DURATION', 500000)),
        'input_fflags' => trim((string) env('FFMPEG_MOTION_INPUT_FFLAGS', '+genpts+discardcorrupt')),
        'use_wallclock_timestamps' => filter_var(env('FFMPEG_MOTION_USE_WALLCLOCK_TIMESTAMPS', true), FILTER_VALIDATE_BOOL),
    ],

    'live' => [
        'rw_timeout' => max(1000000, (int) env('FFMPEG_LIVE_RW_TIMEOUT', env('FFMPEG_STREAM_RW_TIMEOUT', 10000000))),
        'thread_queue_size' => max(8, (int) env('FFMPEG_LIVE_THREAD_QUEUE_SIZE', 1024)),
        'rtbufsize' => (string) env('FFMPEG_LIVE_RTBUF_SIZE', '64M'),
        'input_probe_size' => max(1024, (int) env('FFMPEG_LIVE_PROBE_SIZE', 131072)),
        'input_analyze_duration' => max(0, (int) env('FFMPEG_LIVE_ANALYZE_DURATION', 1000000)),
        'input_fflags' => trim((string) env('FFMPEG_LIVE_INPUT_FFLAGS', '+genpts+discardcorrupt')),
        'use_wallclock_timestamps' => filter_var(env('FFMPEG_LIVE_USE_WALLCLOCK_TIMESTAMPS', true), FILTER_VALIDATE_BOOL),
        'fps_mode' => trim((string) env('FFMPEG_LIVE_FPS_MODE', 'passthrough')),
        'avoid_negative_ts' => trim((string) env('FFMPEG_LIVE_AVOID_NEGATIVE_TS', 'make_zero')),
        'max_muxing_queue_size' => max(32, (int) env('FFMPEG_LIVE_MAX_MUXING_QUEUE_SIZE', 1024)),
        'wall_fps' => max(1, (int) env('FFMPEG_LIVE_WALL_FPS', 4)),
        'wall_mjpeg_quality' => min(31, max(2, (int) env('FFMPEG_LIVE_WALL_MJPEG_QUALITY', 7))),
        'relay_fragment_duration' => max(100000, (int) env('FFMPEG_LIVE_RELAY_FRAGMENT_DURATION', 500000)),
    ],

    'playback' => [
        'audio_bitrate' => (string) env('FFMPEG_PLAYBACK_AUDIO_BITRATE', '128k'),
        'fragment_duration' => max(100000, (int) env('FFMPEG_PLAYBACK_FRAGMENT_DURATION', env('FFMPEG_LIVE_RELAY_FRAGMENT_DURATION', 500000))),
        'audio_resample' => trim((string) env('FFMPEG_PLAYBACK_AUDIO_RESAMPLE', 'aresample=async=1:first_pts=0')),
        'fps_mode' => trim((string) env('FFMPEG_PLAYBACK_FPS_MODE', 'passthrough')),
        'avoid_negative_ts' => trim((string) env('FFMPEG_PLAYBACK_AVOID_NEGATIVE_TS', 'make_zero')),
        'max_muxing_queue_size' => max(32, (int) env('FFMPEG_PLAYBACK_MAX_MUXING_QUEUE_SIZE', 1024)),
    ],
];