<?php

namespace Tests\Feature\Relay;

use Tests\TestCase;

class MediaMtxConfigDefaultsTest extends TestCase
{
    public function test_blank_mediamtx_env_values_fall_back_to_app_defaults(): void
    {
        $appUrl = 'https://monitor.example.test';
        $appKey = 'base64:'.base64_encode(random_bytes(32));

        $this->withEnvironmentOverrides([
            'APP_URL' => $appUrl,
            'APP_KEY' => $appKey,
            'MEDIAMTX_MANAGED_EXTERNALLY' => '',
            'MEDIAMTX_WEBRTC_PUBLIC_URL' => '',
            'MEDIAMTX_WEBRTC_ADDITIONAL_HOSTS' => '',
            'MEDIAMTX_WEBRTC_ALLOW_ORIGINS' => '',
            'MEDIAMTX_AUTH_CALLBACK_URL' => '',
            'MEDIAMTX_AUTH_CALLBACK_SECRET' => '',
            'MEDIAMTX_AUTH_TOKEN_SECRET' => '',
        ], function () use ($appKey, $appUrl): void {
            $config = require base_path('config/mediamtx.php');

            $this->assertTrue($config['auto_start']);
            $this->assertFalse($config['managed_externally']);
            $this->assertSame('rtsp://relay:8554', $config['rtsp']['internal_base_url']);
            $this->assertSame('rtsp://relay:8554', $config['rtsp']['local_internal_base_url']);
            $this->assertSame('http://relay:9997', $config['api']['base_url']);
            $this->assertSame('http://relay:8889', $config['webrtc']['internal_base_url']);
            $this->assertSame($appUrl.'/__webrtc', $config['webrtc']['public_base_url']);
            $this->assertSame([$appUrl], $config['webrtc']['allow_origins']);
            $this->assertSame(['monitor.example.test'], $config['webrtc']['additional_hosts']);
            $this->assertSame(':8189', $config['webrtc']['local_udp_address']);
            $this->assertSame(':8189', $config['webrtc']['local_tcp_address']);
            $this->assertSame($appUrl.'/relay/auth/mediamtx', $config['auth']['callback_url']);
            $this->assertNotSame('', $config['auth']['callback_secret']);
            $this->assertSame($appKey, $config['auth']['token_secret']);
            $this->assertSame(180, $config['auth']['token_ttl']);
            $this->assertSame(['127.0.0.1', '::1', '172.16.0.0/12'], $config['auth']['reader_allowed_ips']);
            $this->assertSame(['127.0.0.1', '::1', '172.16.0.0/12'], $config['auth']['publisher_allowed_ips']);
            $this->assertSame('publisher', $config['auth']['publisher_user']);
            $this->assertNotSame('', $config['auth']['publisher_pass']);
            $this->assertSame('1200k', $config['transcode']['video_bitrate']);
            $this->assertSame(30, $config['transcode']['gop']);
        });
    }

    public function test_local_internal_rtsp_base_url_falls_back_to_publish_base_url_when_present(): void
    {
        $this->withEnvironmentOverrides([
            'MEDIAMTX_RTSP_INTERNAL_BASE_URL' => 'rtsp://relay:8554',
            'MEDIAMTX_RTSP_PUBLISH_BASE_URL' => 'rtsp://127.0.0.1:8554',
            'MEDIAMTX_RTSP_LOCAL_INTERNAL_BASE_URL' => '',
        ], function (): void {
            $config = require base_path('config/mediamtx.php');

            $this->assertSame('rtsp://relay:8554', $config['rtsp']['internal_base_url']);
            $this->assertSame('rtsp://127.0.0.1:8554', $config['rtsp']['publish_base_url']);
            $this->assertSame('rtsp://127.0.0.1:8554', $config['rtsp']['local_internal_base_url']);
        });
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function withEnvironmentOverrides(array $overrides, callable $callback): void
    {
        $original = [];

        foreach ($overrides as $key => $value) {
            $original[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env_value' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server_value' => $_SERVER[$key] ?? null,
            ];

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($original as $key => $state) {
                if ($state['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['process']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env_value'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server_value'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}