<?php

$resolvedAppKey = (static function (): string {
    $configuredKey = trim((string) env('APP_KEY', ''));

    if ($configuredKey !== '') {
        return $configuredKey;
    }

    $keyFile = trim((string) env('APP_KEY_FILE', storage_path('app/private/app.key')));

    if ($keyFile === '' || !is_file($keyFile) || !is_readable($keyFile)) {
        return '';
    }

    $storedKey = @file_get_contents($keyFile);

    if (!is_string($storedKey)) {
        return '';
    }

    return trim($storedKey);
})();

$optionalEnvString = static function (string $key): ?string {
    $value = env($key);

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value !== '' ? $value : null;
};

$optionalEnvCsv = static function (string $key): ?array {
    $value = env($key);

    if (!is_string($value)) {
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
$defaultVersion = '1.17.1';
$defaultInstallDirectory = $defaultInstallRoot.'/releases/'.$defaultVersion;
$defaultBinaryPath = $defaultInstallDirectory.'/mediamtx';
$installMode = $optionalEnvString('MEDIAMTX_INSTALL_MODE') ?? 'download';
$defaultAppUrl = trim((string) env('APP_URL', 'http://localhost'));
$defaultAppUrl = $defaultAppUrl !== '' ? $defaultAppUrl : 'http://localhost';
$defaultWebRtcPublicUrl = rtrim($defaultAppUrl, '/').'/__webrtc';
$configuredWebRtcPublicUrl = $optionalEnvString('MEDIAMTX_WEBRTC_PUBLIC_URL') ?? $defaultWebRtcPublicUrl;
$defaultCallbackOrigin = (function () use ($configuredWebRtcPublicUrl, $defaultAppUrl): string {
    $sourceUrl = $configuredWebRtcPublicUrl !== '' ? $configuredWebRtcPublicUrl : $defaultAppUrl;
    $scheme = parse_url($sourceUrl, PHP_URL_SCHEME) ?? 'http';
    $host = parse_url($sourceUrl, PHP_URL_HOST) ?? 'localhost';
    $port = parse_url($sourceUrl, PHP_URL_PORT);

    return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
})();
$defaultAdditionalHost = parse_url($configuredWebRtcPublicUrl, PHP_URL_HOST) ?? parse_url($defaultAppUrl, PHP_URL_HOST) ?? 'localhost';
$configuredAuthCallbackUrl = $optionalEnvString('MEDIAMTX_AUTH_CALLBACK_URL');
$defaultAuthCallbackUrl = $configuredAuthCallbackUrl ?? $defaultCallbackOrigin.'/relay/auth/mediamtx';
$appKey = $resolvedAppKey;
$defaultTokenSecret = $appKey !== '' ? $appKey : hash('sha256', $defaultAppUrl.'|mediamtx-token-secret');
$configuredTokenSecret = $optionalEnvString('MEDIAMTX_AUTH_TOKEN_SECRET') ?? $defaultTokenSecret;

return [
    'version' => $defaultVersion,

    'install_mode' => $installMode,

    'auto_start' => $optionalEnvBool('MEDIAMTX_AUTO_START', true),

    'download_base_url' => $optionalEnvString('MEDIAMTX_DOWNLOAD_BASE_URL') ?? 'https://github.com/bluenviron/mediamtx/releases/download',

    'install_root' => $optionalEnvString('MEDIAMTX_INSTALL_ROOT') ?? $defaultInstallRoot,

    'install_directory' => $optionalEnvString('MEDIAMTX_INSTALL_DIRECTORY') ?? $defaultInstallDirectory,

    'binary_path' => $optionalEnvString('MEDIAMTX_BINARY_PATH') ?? $defaultBinaryPath,

    'config_path' => $optionalEnvString('MEDIAMTX_CONFIG_PATH') ?? $defaultInstallRoot.'/mediamtx.yml',

    'pid_path' => $optionalEnvString('MEDIAMTX_PID_PATH') ?? $defaultInstallRoot.'/mediamtx.pid',

    'log_path' => $optionalEnvString('MEDIAMTX_LOG_PATH') ?? storage_path('logs/mediamtx.log'),

    'download_timeout' => (int) env('MEDIAMTX_DOWNLOAD_TIMEOUT', 180),

    'rtsp' => [
        'listen_address' => $optionalEnvString('MEDIAMTX_RTSP_LISTEN_ADDRESS') ?? ':8554',
        'internal_base_url' => $optionalEnvString('MEDIAMTX_RTSP_INTERNAL_BASE_URL') ?? 'rtsp://127.0.0.1:8554',
        'publish_base_url' => $optionalEnvString('MEDIAMTX_RTSP_PUBLISH_BASE_URL') ?? 'rtsp://127.0.0.1:8554',
        'transports' => ['tcp'],
    ],

    'api' => [
        'enabled' => $optionalEnvBool('MEDIAMTX_API_ENABLED', true),
        'address' => $optionalEnvString('MEDIAMTX_API_ADDRESS') ?? ':9997',
        'base_url' => $optionalEnvString('MEDIAMTX_API_BASE_URL') ?? 'http://127.0.0.1:9997',
    ],

    'webrtc' => [
        'enabled' => $optionalEnvBool('MEDIAMTX_WEBRTC_ENABLED', true),
        'address' => $optionalEnvString('MEDIAMTX_WEBRTC_ADDRESS') ?? ':8889',
        'internal_base_url' => $optionalEnvString('MEDIAMTX_WEBRTC_INTERNAL_BASE_URL') ?? 'http://127.0.0.1:8889',
        'public_base_url' => $configuredWebRtcPublicUrl,
        'port' => (int) env('MEDIAMTX_WEBRTC_PORT', 8889),
        'allow_origins' => $optionalEnvCsv('MEDIAMTX_WEBRTC_ALLOW_ORIGINS') ?? [$defaultCallbackOrigin],
        'ips_from_interfaces' => $optionalEnvBool('MEDIAMTX_WEBRTC_IPS_FROM_INTERFACES', true),
        'local_udp_address' => $optionalEnvString('MEDIAMTX_WEBRTC_LOCAL_UDP_ADDRESS') ?? ':8189',
        'local_tcp_address' => $optionalEnvString('MEDIAMTX_WEBRTC_LOCAL_TCP_ADDRESS') ?? ':8189',
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
        'callback_secret' => $optionalEnvString('MEDIAMTX_AUTH_CALLBACK_SECRET') ?? hash('sha256', ($appKey !== '' ? $appKey : $defaultAuthCallbackUrl).'|mediamtx-auth-callback'),
        'token_secret' => $configuredTokenSecret,
        'token_ttl' => 180,
        'reader_user' => 'internal-reader',
        'reader_pass' => substr(hash('sha256', $configuredTokenSecret.'|mediamtx-reader'), 0, 32),
        'publisher_user' => 'publisher',
        'publisher_pass' => substr(hash('sha256', $configuredTokenSecret.'|mediamtx-publisher'), 0, 32),
    ],

    'transcode' => [
        'preset' => 'ultrafast',
        'video_bitrate' => '1200k',
        'video_crf' => 23,
        'video_maxrate' => '1800k',
        'video_bufsize' => '1800k',
        'audio_codec' => 'libopus',
        'audio_bitrate' => '96k',
        'audio_channels' => 1,
        'audio_sample_rate' => 48000,
        'gop' => 30,
        'start_timeout' => '20s',
        'close_after' => '30s',
    ],
];
