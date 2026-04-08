<?php

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

$defaultInstallRoot = storage_path('app/private/mediamtx');
$defaultVersion = '1.17.1';
$defaultInstallDirectory = $defaultInstallRoot.'/releases/'.$defaultVersion;
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
$appKey = trim((string) env('APP_KEY', ''));
$defaultTokenSecret = $appKey !== '' ? $appKey : hash('sha256', $defaultAppUrl.'|mediamtx-token-secret');
$configuredTokenSecret = $optionalEnvString('MEDIAMTX_AUTH_TOKEN_SECRET') ?? $defaultTokenSecret;

return [
    'version' => $defaultVersion,

    'auto_start' => true,

    'download_base_url' => 'https://github.com/bluenviron/mediamtx/releases/download',

    'install_root' => $defaultInstallRoot,

    'install_directory' => $defaultInstallDirectory,

    'binary_path' => $defaultInstallDirectory.'/mediamtx',

    'config_path' => $defaultInstallRoot.'/mediamtx.yml',

    'pid_path' => $defaultInstallRoot.'/mediamtx.pid',

    'log_path' => storage_path('logs/mediamtx.log'),

    'download_timeout' => 180,

    'rtsp' => [
        'listen_address' => ':8554',
        'internal_base_url' => 'rtsp://127.0.0.1:8554',
        'transports' => ['tcp'],
    ],

    'api' => [
        'enabled' => true,
        'address' => ':9997',
        'base_url' => 'http://127.0.0.1:9997',
    ],

    'webrtc' => [
        'enabled' => true,
        'address' => ':8889',
        'internal_base_url' => 'http://127.0.0.1:8889',
        'public_base_url' => $configuredWebRtcPublicUrl,
        'port' => 8889,
        'allow_origins' => $optionalEnvCsv('MEDIAMTX_WEBRTC_ALLOW_ORIGINS') ?? [$defaultCallbackOrigin],
        'local_udp_address' => ':8189',
        'local_tcp_address' => ':8189',
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
