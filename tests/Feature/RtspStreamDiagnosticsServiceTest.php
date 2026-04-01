<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\Onvif\RtspStreamDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RtspStreamDiagnosticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_confirms_an_rtsp_stream_and_captures_a_preview_image(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-success.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
printf '%s' '{"streams":[{"codec_name":"h264","width":1920,"height":1080}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-success.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
output="${!#}"
    printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'MainStream',
            'token' => 'profile_main',
            'uri' => 'rtsp://192.168.1.67:554/stream1',
        ], 0);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('h264', $profile['video_codec']);
        $this->assertSame('1920x1080', $profile['video_resolution']);
        $this->assertNotNull($profile['preview_path']);
        $this->assertStringStartsWith('cameras/'.$camera->id.'/previews/', $profile['preview_path']);
        $this->assertFileExists(storage_path('app/private/'.$profile['preview_path']));
    }
}