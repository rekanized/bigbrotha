<?php

namespace Tests\Feature;

use App\Models\Camera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LiveWallStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_wall_prefers_an_efficient_substream_for_wall_tiles(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');

        $camera = Camera::query()->create([
            'name' => 'Tapo C200',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'token' => 'profile_1',
                        'name' => 'mainStream',
                        'encoding' => 'H264',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.67:554/stream1',
                        'path' => '/stream1',
                        'probe_status' => 'Healthy',
                    ],
                    [
                        'token' => 'profile_2',
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.67:554/stream2',
                        'path' => '/stream2',
                    ],
                ],
            ],
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('live-wall.index'));

        $response
            ->assertOk()
            ->assertSee('http://relay.example:8889/camera-'.$camera->id.'-live', false)
            ->assertSee(route('live-wall.relay', ['camera' => $camera, 'profileIndex' => 1]), false)
            ->assertSee('minorStream');
    }

    public function test_it_streams_a_browser_safe_mjpeg_live_feed(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_transport' => 'tcp',
            'username' => 'operator',
            'password' => 'secret',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'minorStream',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.67:554/stream2',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-live-mjpeg.sh';
        File::put($ffmpegBinary, '#!/usr/bin/env bash'.PHP_EOL."printf '%s' '--bigbrothas-live\\r\\nContent-Type: image/jpeg\\r\\n\\r\\nframe-one\\r\\n--bigbrothas-live--\\r\\n'".PHP_EOL);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('live-wall.stream', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'multipart/x-mixed-replace;boundary=bigbrothas-live');

        $this->assertStringContainsString('frame-one', $response->streamedContent());
    }

    public function test_it_streams_a_copy_relay_without_reencoding_video(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_transport' => 'tcp',
            'username' => 'operator',
            'password' => 'secret',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'mainStream',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.67:554/stream1',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-live-relay.sh';
        File::put($ffmpegBinary, '#!/usr/bin/env bash'.PHP_EOL."printf '....ftypisomrelay-data'".PHP_EOL);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('live-wall.relay', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');

        $this->assertStringContainsString('ftypisomrelay-data', $response->streamedContent());
    }
}