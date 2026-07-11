<?php

$defaultFfmpegBinaryCandidates = array_values(array_filter([
    is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : null,
    is_executable('/usr/local/bin/ffmpeg') ? '/usr/local/bin/ffmpeg' : null,
    'ffmpeg',
]));
$defaultFfprobeBinaryCandidates = array_values(array_filter([
    is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : null,
    is_executable('/usr/local/bin/ffprobe') ? '/usr/local/bin/ffprobe' : null,
    'ffprobe',
]));
$configuredFfmpegBinaryCandidates = (static function () use ($defaultFfmpegBinaryCandidates): array {
    $configured = env('FFMPEG_BINARIES');

    if (!is_string($configured) || trim($configured) === '') {
        return $defaultFfmpegBinaryCandidates;
    }

    $candidates = array_values(array_filter(array_map(
        static fn (string $candidate): string => trim($candidate),
        explode(',', $configured),
    )));

    return $candidates !== [] ? $candidates : $defaultFfmpegBinaryCandidates;
})();
$configuredFfprobeBinaryCandidates = (static function () use ($defaultFfprobeBinaryCandidates): array {
    $configured = env('FFPROBE_BINARIES');

    if (!is_string($configured) || trim($configured) === '') {
        return $defaultFfprobeBinaryCandidates;
    }

    $candidates = array_values(array_filter(array_map(
        static fn (string $candidate): string => trim($candidate),
        explode(',', $configured),
    )));

    return $candidates !== [] ? $candidates : $defaultFfprobeBinaryCandidates;
})();
$defaultTemporaryDirectory = storage_path('app/private/ffmpeg-temp');

return [
    'timeout' => (int) env('FFMPEG_TIMEOUT', 3600),

    'temporary_directory' => env('FFMPEG_TEMPORARY_DIRECTORY', $defaultTemporaryDirectory),

    'ffmpeg' => [
        'binaries' => $configuredFfmpegBinaryCandidates,
        'threads' => max(1, (int) env('FFMPEG_THREADS', 2)),
    ],

    'ffprobe' => [
        'binaries' => $configuredFfprobeBinaryCandidates,
    ],

    'streaming' => [
        'rw_timeout' => max(1000000, (int) env('FFMPEG_STREAM_RW_TIMEOUT', 10000000)),
        'input_probe_size' => max(1024, (int) env('FFMPEG_STREAM_PROBE_SIZE', 131072)),
        'input_analyze_duration' => max(0, (int) env('FFMPEG_STREAM_ANALYZE_DURATION', 1000000)),
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
        'audio_resample' => trim((string) env('FFMPEG_LIVE_AUDIO_RESAMPLE', 'aresample=async=1000:min_hard_comp=0.100:first_pts=0')),
        'wall_fps' => max(1, (int) env('FFMPEG_LIVE_WALL_FPS', 4)),
        'wall_mjpeg_quality' => min(31, max(2, (int) env('FFMPEG_LIVE_WALL_MJPEG_QUALITY', 7))),
        'relay_fragment_duration' => max(100000, (int) env('FFMPEG_LIVE_RELAY_FRAGMENT_DURATION', 500000)),
    ],

    'playback' => [
        'audio_bitrate' => (string) env('FFMPEG_PLAYBACK_AUDIO_BITRATE', '96k'),
        'fragment_duration' => max(100000, (int) env('FFMPEG_PLAYBACK_FRAGMENT_DURATION', env('FFMPEG_LIVE_RELAY_FRAGMENT_DURATION', 500000))),
        'input_fflags' => trim((string) env('FFMPEG_PLAYBACK_INPUT_FFLAGS', '+genpts+discardcorrupt')),
        'audio_resample' => trim((string) env('FFMPEG_PLAYBACK_AUDIO_RESAMPLE', 'aresample=async=1000:min_hard_comp=0.100:first_pts=0,asetpts=N/SR/TB')),
        'fps_mode' => trim((string) env('FFMPEG_PLAYBACK_FPS_MODE', 'passthrough')),
        'avoid_negative_ts' => trim((string) env('FFMPEG_PLAYBACK_AVOID_NEGATIVE_TS', 'make_zero')),
        'max_muxing_queue_size' => max(32, (int) env('FFMPEG_PLAYBACK_MAX_MUXING_QUEUE_SIZE', 1024)),
    ],
];
