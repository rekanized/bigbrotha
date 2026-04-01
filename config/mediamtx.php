<?php

$defaultInstallRoot = storage_path('app/private/mediamtx');
$defaultVersion = env('MEDIAMTX_VERSION', '1.17.1');
$defaultInstallDirectory = $defaultInstallRoot.'/releases/'.$defaultVersion;

return [
    'version' => $defaultVersion,

    'auto_start' => filter_var(env('MEDIAMTX_AUTO_START', true), FILTER_VALIDATE_BOOL),

    'download_base_url' => env('MEDIAMTX_DOWNLOAD_BASE_URL', 'https://github.com/bluenviron/mediamtx/releases/download'),

    'install_root' => env('MEDIAMTX_INSTALL_ROOT', $defaultInstallRoot),

    'install_directory' => env('MEDIAMTX_INSTALL_DIRECTORY', $defaultInstallDirectory),

    'binary_path' => env('MEDIAMTX_BINARY', $defaultInstallDirectory.'/mediamtx'),

    'config_path' => env('MEDIAMTX_CONFIG_PATH', $defaultInstallRoot.'/mediamtx.yml'),

    'pid_path' => env('MEDIAMTX_PID_PATH', $defaultInstallRoot.'/mediamtx.pid'),

    'log_path' => env('MEDIAMTX_LOG_PATH', storage_path('logs/mediamtx.log')),

    'download_timeout' => max(30, (int) env('MEDIAMTX_DOWNLOAD_TIMEOUT', 180)),

    'rtsp' => [
        'listen_address' => env('MEDIAMTX_RTSP_ADDRESS', ':8554'),
        'internal_base_url' => env('MEDIAMTX_RTSP_INTERNAL_URL', 'rtsp://127.0.0.1:8554'),
        'transports' => ['tcp'],
    ],

    'api' => [
        'enabled' => true,
        'address' => env('MEDIAMTX_API_ADDRESS', ':9997'),
        'base_url' => env('MEDIAMTX_API_URL', 'http://127.0.0.1:9997'),
    ],

    'webrtc' => [
        'enabled' => true,
        'address' => env('MEDIAMTX_WEBRTC_ADDRESS', ':8889'),
        'internal_base_url' => env('MEDIAMTX_WEBRTC_INTERNAL_URL', 'http://127.0.0.1:8889'),
        'public_base_url' => env('MEDIAMTX_WEBRTC_PUBLIC_URL'),
        'port' => (int) env('MEDIAMTX_WEBRTC_PORT', 8889),
        'allow_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIAMTX_WEBRTC_ALLOW_ORIGINS', '*'))))),
        'local_udp_address' => env('MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS', ':8189'),
        'local_tcp_address' => env('MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS', ':8189'),
        'additional_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS', ''))))),
        'iframe_query' => http_build_query([
            'controls' => 'false',
            'muted' => 'true',
            'autoplay' => 'true',
            'playsInline' => 'true',
            'disablepictureinpicture' => 'true',
        ]),
    ],

    'transcode' => [
        'preset' => env('MEDIAMTX_TRANSCODE_PRESET', 'ultrafast'),
        'video_bitrate' => env('MEDIAMTX_TRANSCODE_VIDEO_BITRATE', '1200k'),
        'gop' => max(15, (int) env('MEDIAMTX_TRANSCODE_GOP', 30)),
        'start_timeout' => env('MEDIAMTX_RUN_ON_DEMAND_START_TIMEOUT', '20s'),
        'close_after' => env('MEDIAMTX_RUN_ON_DEMAND_CLOSE_AFTER', '15s'),
    ],
];
