<?php

$defaultFfmpegBinary = '/home/administrator/.local/bin/ffmpeg';
$defaultFfprobeBinary = '/home/administrator/.local/bin/ffprobe';

return [
    'timeout' => (int) env('FFMPEG_TIMEOUT', 3600),

    'temporary_directory' => env('FFMPEG_TEMPORARY_DIRECTORY', storage_path('app/private/ffmpeg-temp')),

    'ffmpeg' => [
        'binaries' => array_values(array_filter([
            env('FFMPEG_BINARY'),
            is_file($defaultFfmpegBinary) ? $defaultFfmpegBinary : null,
            'ffmpeg',
        ])),
        'threads' => max(1, (int) env('FFMPEG_THREADS', 2)),
    ],

    'ffprobe' => [
        'binaries' => array_values(array_filter([
            env('FFPROBE_BINARY'),
            is_file($defaultFfprobeBinary) ? $defaultFfprobeBinary : null,
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
];