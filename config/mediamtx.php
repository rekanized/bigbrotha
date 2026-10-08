<?php

$resolvedAppKey = (static function (): string {
    $configuredKey = trim((string) env('APP_KEY', ''));

    if ($configuredKey !== '') {
        return $configuredKey;
    }

    $keyFile = trim((string) env('APP_KEY_FILE', storage_path('app/private/app.key')));

    if ($keyFile === '' || ! is_file($keyFile) || ! is_readable($keyFile)) {
        return '';
    }

    $storedKey = @file_get_contents($keyFile);

    if (! is_string($storedKey)) {
        return '';
    }

    return trim($storedKey);
})();

$optionalEnvString = static function (string $key): ?string {
    $value = env($key);

    if (! is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value !== '' ? $value : null;
};

$envStringAllowEmpty = static function (string $key): ?string {
    $value = env($key);

    if (! is_string($value)) {
        return null;
    }

    return trim($value);
};

$optionalEnvCsv = static function (string $key): ?array {
    $value = env($key);

    if (! is_string($value)) {
        return null;
    }

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

$optionalEnvBool = static function (string $key, bool $default): bool {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    $resolved = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

    return $resolved ?? $default;
};

$defaultInstallRoot = storage_path('app/private/mediamtx');
$defaultBinaryPath = '/usr/local/bin/mediamtx';
$defaultAppUrl = trim((string) env('APP_URL', 'http://app'));
$defaultAppUrl = $defaultAppUrl !== '' ? $defaultAppUrl : 'http://app';
$defaultWebRtcPublicUrl = rtrim($defaultAppUrl, '/').'/__webrtc';
$configuredWebRtcPublicUrl = $optionalEnvString('MEDIAMTX_WEBRTC_PUBLIC_URL') ?? $defaultWebRtcPublicUrl;
$defaultCallbackOrigin = (function () use ($configuredWebRtcPublicUrl, $defaultAppUrl): string {
    $sourceUrl = $configuredWebRtcPublicUrl !== '' ? $configuredWebRtcPublicUrl : $defaultAppUrl;
    $scheme = parse_url($sourceUrl, PHP_URL_SCHEME) ?? 'http';
    $host = parse_url($sourceUrl, PHP_URL_HOST) ?? 'app';
    $port = parse_url($sourceUrl, PHP_URL_PORT);

    return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
})();
$defaultAdditionalHost = parse_url($configuredWebRtcPublicUrl, PHP_URL_HOST) ?? parse_url($defaultAppUrl, PHP_URL_HOST) ?? 'app';
$configuredAuthCallbackUrl = $optionalEnvString('MEDIAMTX_AUTH_CALLBACK_URL');
$defaultAuthCallbackUrl = $configuredAuthCallbackUrl ?? $defaultCallbackOrigin.'/relay/auth/mediamtx';
$defaultIcePort = max(1, (int) env('MEDIAMTX_ICE_PORT', 8190));
$appKey = $resolvedAppKey;
$defaultTokenSecret = $appKey;
$configuredTokenSecret = $optionalEnvString('MEDIAMTX_AUTH_TOKEN_SECRET') ?? $defaultTokenSecret;

return [
    'auto_start' => $optionalEnvBool('MEDIAMTX_AUTO_START', true),

    'managed_externally' => $optionalEnvBool('MEDIAMTX_MANAGED_EXTERNALLY', false),

    'binary_path' => $optionalEnvString('MEDIAMTX_BINARY_PATH') ?? $defaultBinaryPath,

    'config_path' => $optionalEnvString('MEDIAMTX_CONFIG_PATH') ?? $defaultInstallRoot.'/mediamtx.yml',

    'pid_path' => $optionalEnvString('MEDIAMTX_PID_PATH') ?? $defaultInstallRoot.'/mediamtx.pid',

    'log_path' => $optionalEnvString('MEDIAMTX_LOG_PATH') ?? storage_path('logs/mediamtx.log'),

    'rtsp' => [
        'listen_address' => $optionalEnvString('MEDIAMTX_RTSP_LISTEN_ADDRESS') ?? ':8554',
        'internal_base_url' => $optionalEnvString('MEDIAMTX_RTSP_INTERNAL_BASE_URL') ?? 'rtsp://relay:8554',
        'publish_base_url' => $optionalEnvString('MEDIAMTX_RTSP_PUBLISH_BASE_URL') ?? 'rtsp://relay:8554',
        'local_internal_base_url' => $optionalEnvString('MEDIAMTX_RTSP_LOCAL_INTERNAL_BASE_URL')
            ?? $optionalEnvString('MEDIAMTX_RTSP_PUBLISH_BASE_URL')
            ?? 'rtsp://relay:8554',
        'transports' => ['tcp'],
    ],

    'api' => [
        'enabled' => $optionalEnvBool('MEDIAMTX_API_ENABLED', true),
        'address' => $optionalEnvString('MEDIAMTX_API_ADDRESS') ?? ':9997',
        'base_url' => $optionalEnvString('MEDIAMTX_API_BASE_URL') ?? 'http://relay:9997',
    ],

    'webrtc' => [
        'enabled' => $optionalEnvBool('MEDIAMTX_WEBRTC_ENABLED', true),
        'address' => $optionalEnvString('MEDIAMTX_WEBRTC_ADDRESS') ?? ':8889',
        'internal_base_url' => $optionalEnvString('MEDIAMTX_WEBRTC_INTERNAL_BASE_URL') ?? 'http://relay:8889',
        'public_base_url' => $configuredWebRtcPublicUrl,
        'port' => (int) env('MEDIAMTX_WEBRTC_PORT', 8889),
        'allow_origins' => $optionalEnvCsv('MEDIAMTX_WEBRTC_ALLOW_ORIGINS') ?? [$defaultCallbackOrigin],
        'ips_from_interfaces' => $optionalEnvBool('MEDIAMTX_WEBRTC_IPS_FROM_INTERFACES', false),
        'local_udp_address' => $envStringAllowEmpty('MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS') ?? ':'.$defaultIcePort,
        'local_tcp_address' => $envStringAllowEmpty('MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS') ?? ':'.$defaultIcePort,
        'additional_hosts' => $optionalEnvCsv('MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS') ?? [$defaultAdditionalHost],
        'iframe_query' => http_build_query([
            'controls' => 'false',
            'muted' => 'true',
            'autoplay' => 'true',
            'playsInline' => 'true',
            'disablepictureinpicture' => 'true',
        ]),
    ],

    'auth' => [
        'enabled' => true,
        'callback_url' => $defaultAuthCallbackUrl,
        'callback_secret' => $optionalEnvString('MEDIAMTX_AUTH_CALLBACK_SECRET') ?? ($appKey !== '' ? hash('sha256', $appKey.'|mediamtx-auth-callback') : ''),
        'token_secret' => $configuredTokenSecret,
        'token_ttl' => 180,
        'reader_allowed_ips' => $optionalEnvCsv('MEDIAMTX_AUTH_READER_ALLOWED_IPS') ?? ['127.0.0.1', '::1', '172.16.0.0/12'],
        'publisher_allowed_ips' => $optionalEnvCsv('MEDIAMTX_AUTH_PUBLISHER_ALLOWED_IPS') ?? ['127.0.0.1', '::1', '172.16.0.0/12'],
        'reader_user' => 'internal-reader',
        'reader_pass' => ($configuredTokenSecret !== '' ? substr(hash('sha256', $configuredTokenSecret.'|mediamtx-reader'), 0, 32) : ''),
        'publisher_user' => 'publisher',
        'publisher_pass' => ($configuredTokenSecret !== '' ? substr(hash('sha256', $configuredTokenSecret.'|mediamtx-publisher'), 0, 32) : ''),
    ],

    'transcode' => [
        'video_codec' => $optionalEnvString('MEDIAMTX_TRANSCODE_VIDEO_CODEC') ?? 'libx264',
        'force_video_transcode' => $optionalEnvBool('MEDIAMTX_TRANSCODE_FORCE_VIDEO', false),
        'video_fps' => max(1, (int) env('MEDIAMTX_TRANSCODE_VIDEO_FPS', 15)),
        'video_fps_mode' => trim((string) env('MEDIAMTX_TRANSCODE_VIDEO_FPS_MODE', 'cfr')),
        'preset' => 'ultrafast',
        'video_bitrate' => '1200k',
        'video_crf' => 23,
        'video_maxrate' => '1800k',
        'video_bufsize' => '1800k',
        'audio_codec' => 'libopus',
        'audio_bitrate' => '96k',
        'audio_channels' => max(1, (int) env('MEDIAMTX_TRANSCODE_AUDIO_CHANNELS', 2)),
        'audio_sample_rate' => 48000,
        'gop' => 30,
        'start_timeout' => '30s',
        'live_start_timeout' => '45s',
        'close_after' => '30s',
        'live_close_after' => $optionalEnvString('MEDIAMTX_LIVE_CLOSE_AFTER') ?? '120s',
        'hardware_acceleration' => [
            'engine' => strtolower(trim((string) env('MEDIAMTX_TRANSCODE_HWACCEL', ''))),
            'device' => $optionalEnvString('MEDIAMTX_TRANSCODE_HWACCEL_DEVICE'),
            'decoder' => $optionalEnvString('MEDIAMTX_TRANSCODE_HWACCEL_DECODER'),
            'encoder' => $optionalEnvString('MEDIAMTX_TRANSCODE_HWACCEL_ENCODER'),
            'input_args' => $optionalEnvCsv('MEDIAMTX_TRANSCODE_HWACCEL_INPUT_ARGS') ?? [],
            'output_args' => $optionalEnvCsv('MEDIAMTX_TRANSCODE_HWACCEL_OUTPUT_ARGS') ?? [],
        ],
    ],
];
