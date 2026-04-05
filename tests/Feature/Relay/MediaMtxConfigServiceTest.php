<?php

namespace Tests\Feature\Relay;

use App\Models\Camera;
use App\Services\Relay\MediaMtxConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MediaMtxConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_config_keeps_audio_when_generating_run_on_demand_commands(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-audio-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.webrtc.local_tcp_address', '');

        $camera = Camera::query()->create([
            'name' => 'Audio Camera',
            'local_ip' => '192.168.1.91',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.91:554/minor',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString('camera-'.$camera->id.'-live:', $config);
        $this->assertStringContainsString("webrtcLocalTCPAddress: ''", $config);
        $this->assertStringContainsString('-map 0:v:0', $config);
        $this->assertStringContainsString('-map 0:a:0?', $config);
        $this->assertStringContainsString('-c:v copy', $config);
        $this->assertStringContainsString("-analyzeduration '0'", $config);
        $this->assertStringContainsString("-probesize '32768'", $config);
        $this->assertStringContainsString('-c:a', $config);
        $this->assertStringContainsString("-af 'aresample=async=1:first_pts=0'", $config);
        $this->assertStringContainsString("'libopus'", $config);
        $this->assertStringContainsString('-flush_packets 1', $config);
        $this->assertStringContainsString('-muxdelay 0', $config);
        $this->assertStringContainsString('-muxpreload 0', $config);
        $this->assertStringContainsString('runOnDemandCloseAfter: 30s', $config);
        $this->assertStringNotContainsString('libx264', $config);
        $this->assertStringNotContainsString(' -an ', $config);
    }

    public function test_build_config_transcodes_non_h264_video_sources(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-video-transcode-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $camera = Camera::query()->create([
            'name' => 'H265 Camera',
            'local_ip' => '192.168.1.92',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'encoding' => 'H265',
                        'video_codec' => 'h265',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.92:554/main',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString('camera-'.$camera->id.'-live:', $config);
        $this->assertStringContainsString('-c:v libx264', $config);
        $this->assertStringContainsString('-profile:v baseline', $config);
        $this->assertStringContainsString('-b:v', $config);
    }
}