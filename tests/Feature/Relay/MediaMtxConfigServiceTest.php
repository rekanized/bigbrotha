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

    public function test_build_config_keeps_browser_safe_audio_transcoding_for_live_run_on_demand_commands(): void
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
            'rtsp_path' => '/minor',
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
        $sourceBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-source-profile-0');
        $liveBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-live');

        $this->assertStringContainsString('camera-'.$camera->id.'-live:', $config);
        $this->assertStringContainsString('camera-'.$camera->id.'-source-profile-0:', $config);
        $this->assertStringContainsString("webrtcLocalTCPAddress: ''", $config);
        $this->assertStringContainsString("-i 'rtsp://192.168.1.91:554/minor'", $sourceBlock);
        $this->assertStringContainsString('-c copy', $sourceBlock);
        $this->assertStringContainsString('-map 0:v:0', $liveBlock);
        $this->assertStringContainsString('-map 0:a:0?', $liveBlock);
        $this->assertStringContainsString('-sn', $liveBlock);
        $this->assertStringContainsString('-dn', $liveBlock);
        $this->assertStringContainsString("-i 'rtsp://192.168.1.91:554/minor'", $liveBlock);
        $this->assertStringNotContainsString("camera-{$camera->id}-source-profile-0", $liveBlock);
        $this->assertStringContainsString('-c:v copy', $liveBlock);
        $this->assertStringContainsString("-timeout '10000000'", $liveBlock);
        $this->assertStringContainsString("-rtbufsize '64M'", $liveBlock);
        $this->assertStringContainsString("-fflags '+genpts+discardcorrupt'", $liveBlock);
        $this->assertStringContainsString("-use_wallclock_as_timestamps '1'", $liveBlock);
        $this->assertStringContainsString("-analyzeduration '1000000'", $liveBlock);
        $this->assertStringContainsString("-probesize '131072'", $liveBlock);
        $this->assertStringContainsString("-fps_mode 'passthrough'", $liveBlock);
        $this->assertStringContainsString("-avoid_negative_ts 'make_zero'", $liveBlock);
        $this->assertStringContainsString('-c:a', $liveBlock);
        $this->assertStringContainsString("-af 'aresample=async=1000:min_hard_comp=0.100:first_pts=0'", $liveBlock);
        $this->assertStringContainsString("'libopus'", $liveBlock);
        $this->assertStringContainsString("-ac '2'", $liveBlock);
        $this->assertStringContainsString("-ar '48000'", $liveBlock);
        $this->assertStringContainsString("-max_muxing_queue_size '1024'", $liveBlock);
        $this->assertStringContainsString('runOnDemandStartTimeout: 45s', $liveBlock);
        $this->assertStringContainsString('runOnDemandStartTimeout: 30s', $sourceBlock);
        $this->assertStringContainsString('runOnDemandCloseAfter: 30s', $config);
        $this->assertStringNotContainsString('libx264', $liveBlock);
        $this->assertStringNotContainsString('-rw_timeout', $liveBlock);
        $this->assertStringNotContainsString(' -an ', $liveBlock);
    }

    public function test_build_config_uses_copy_pipeline_for_source_run_on_demand_commands(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-recording-copy-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $camera = Camera::query()->create([
            'name' => 'Recording Relay Camera',
            'local_ip' => '192.168.1.93',
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
                        'encoding' => 'H264',
                        'video_codec' => 'h264',
                        'audio_codec' => 'pcm_alaw',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.93:554/main',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();
        $sourceBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-source-profile-0');

        $this->assertStringContainsString("-thread_queue_size '1024'", $sourceBlock);
        $this->assertStringContainsString("-timeout '20000000'", $sourceBlock);
        $this->assertStringContainsString("-rtbufsize '128M'", $sourceBlock);
        $this->assertStringContainsString("-fflags '+genpts+discardcorrupt'", $sourceBlock);
        $this->assertStringContainsString("-use_wallclock_as_timestamps '1'", $sourceBlock);
        $this->assertStringContainsString("-analyzeduration '1000000'", $sourceBlock);
        $this->assertStringContainsString("-probesize '262144'", $sourceBlock);
        $this->assertStringContainsString("-fps_mode 'passthrough'", $sourceBlock);
        $this->assertStringContainsString("-avoid_negative_ts 'make_zero'", $sourceBlock);
        $this->assertStringContainsString('-sn', $sourceBlock);
        $this->assertStringContainsString('-dn', $sourceBlock);
        $this->assertStringContainsString('-c copy', $sourceBlock);
        $this->assertStringNotContainsString('-c:a', $sourceBlock);
        $this->assertStringNotContainsString('-af', $sourceBlock);
        $this->assertStringNotContainsString('libopus', $sourceBlock);
        $this->assertStringNotContainsString('libx264', $sourceBlock);
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
            'rtsp_path' => '/main',
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
        $liveBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-live');

        $this->assertStringContainsString('camera-'.$camera->id.'-live:', $config);
        $this->assertStringContainsString('-c:v libx264', $config);
        $this->assertStringContainsString('-profile:v baseline', $config);
        $this->assertStringContainsString("-ac '2'", $config);
        $this->assertStringContainsString("-ar '48000'", $config);
        $this->assertStringContainsString('-b:v', $config);
        $this->assertStringContainsString("-fps_mode 'cfr'", $liveBlock);
        $this->assertStringContainsString("-r '15'", $liveBlock);
    }

    public function test_build_config_can_use_nvidia_hardware_acceleration_for_hevc_live_transcoding(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-nvidia-transcode-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.transcode.hardware_acceleration.engine', 'nvidia');

        $camera = Camera::query()->create([
            'name' => 'NVIDIA HEVC Camera',
            'local_ip' => '192.168.1.95',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/main',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'encoding' => 'H265',
                        'video_codec' => 'hevc',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.95:554/main',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString('-hwaccel cuda', $config);
        $this->assertStringContainsString('-hwaccel_output_format cuda', $config);
        $this->assertStringContainsString('-c:v hevc_cuvid', $config);
        $this->assertStringContainsString('-c:v h264_nvenc', $config);
        $this->assertStringContainsString('-tune ll', $config);
        $this->assertStringContainsString("-fps_mode 'cfr'", $config);
        $this->assertStringContainsString("-r '15'", $config);
    }

    public function test_build_config_can_use_quicksync_hardware_acceleration_for_hevc_live_transcoding(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-qsv-transcode-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.transcode.hardware_acceleration.engine', 'qsv');

        $camera = Camera::query()->create([
            'name' => 'QuickSync HEVC Camera',
            'local_ip' => '192.168.1.96',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/main',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'encoding' => 'H265',
                        'video_codec' => 'hevc',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.96:554/main',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString('-hwaccel qsv', $config);
        $this->assertStringContainsString('-hwaccel_output_format qsv', $config);
        $this->assertStringContainsString('-c:v hevc_qsv', $config);
        $this->assertStringContainsString('-c:v h264_qsv', $config);
        $this->assertStringContainsString('-look_ahead 0', $config);
        $this->assertStringContainsString("-fps_mode 'cfr'", $config);
        $this->assertStringContainsString("-r '15'", $config);
    }

    public function test_build_config_applies_per_camera_cbr_settings_to_live_transcoding(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-camera-cbr-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $camera = Camera::query()->create([
            'name' => 'Tuned HEVC Camera',
            'local_ip' => '192.168.1.97',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/main',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'live_transcode' => [
                    'quality' => Camera::LIVE_TRANSCODE_QUALITY_QUALITY,
                    'rate_control' => Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR,
                    'bitrate_kbps' => 3500,
                ],
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'encoding' => 'H265',
                        'video_codec' => 'hevc',
                        'resolution' => '1920x1080',
                        'uri' => 'rtsp://192.168.1.97:554/main',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();
        $liveBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-live');

        $this->assertStringContainsString("-preset 'fast'", $liveBlock);
        $this->assertStringContainsString("-b:v '3500k'", $liveBlock);
        $this->assertStringContainsString("-minrate '3500k'", $liveBlock);
        $this->assertStringContainsString("-maxrate '3500k'", $liveBlock);
        $this->assertStringContainsString("-bufsize '7000k'", $liveBlock);
        $this->assertStringContainsString("-x264-params 'nal-hrd=cbr:force-cfr=1'", $liveBlock);
        $this->assertStringNotContainsString('-crf', $liveBlock);
    }

    public function test_live_run_on_demand_uses_the_camera_rtsp_uri_instead_of_a_relay_reader_url(): void
    {
        $binaryDirectory = storage_path('framework/testing');
        $ffmpegBinary = $binaryDirectory.'/ffmpeg-mediamtx-local-reader-test';

        File::ensureDirectoryExists($binaryDirectory);
        File::put($ffmpegBinary, "#!/usr/bin/env bash\nexit 0\n");
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://app:8554');
        config()->set('mediamtx.rtsp.publish_base_url', 'rtsp://127.0.0.1:8554');
        config()->set('mediamtx.rtsp.local_internal_base_url', 'rtsp://127.0.0.1:8554');

        $camera = Camera::query()->create([
            'name' => 'Docker Relay Camera',
            'local_ip' => '192.168.1.94',
            'http_port' => 80,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/minor',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.94:554/minor',
                    ],
                ],
            ],
        ]);

        $config = app(MediaMtxConfigService::class)->buildConfig();
        $liveBlock = $this->pathBlock($config, 'camera-'.$camera->id.'-live');

        $this->assertStringContainsString("-i 'rtsp://192.168.1.94:554/minor'", $liveBlock);
        $this->assertStringNotContainsString('rtsp://internal-reader:', $liveBlock);
        $this->assertStringNotContainsString("camera-{$camera->id}-source-profile-0", $liveBlock);
    }

    private function pathBlock(string $config, string $path): string
    {
        $pattern = '/^  '.preg_quote($path, '/').":\n(?:    .*\n)*/m";

        preg_match($pattern, $config, $matches);

        $this->assertNotEmpty($matches, 'Expected to find the '.$path.' block in the generated MediaMTX config.');

        return $matches[0];
    }
}
