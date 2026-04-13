<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\Onvif\RtspStreamDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    public function test_it_replaces_an_existing_read_only_preview_file(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Side Gate',
            'local_ip' => '192.168.1.68',
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

        $ffprobeBinary = $binaryDirectory.'/ffprobe-read-only-preview.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-read-only-preview.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $previewPath = 'cameras/'.$camera->id.'/previews/saved-endpoint-1.jpg';
        $absolutePreviewPath = storage_path('app/private/'.$previewPath);

        File::ensureDirectoryExists(dirname($absolutePreviewPath));
        File::put($absolutePreviewPath, 'stale-preview');
        chmod($absolutePreviewPath, 0444);

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'Saved endpoint',
            'uri' => 'rtsp://192.168.1.68:554/stream1',
            'preview_path' => $previewPath,
        ], 0);

        clearstatcache(true, $absolutePreviewPath);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame($previewPath, $profile['preview_path']);
        $this->assertFileExists($absolutePreviewPath);
        $this->assertNotSame('stale-preview', file_get_contents($absolutePreviewPath));
    }

    public function test_it_falls_back_to_tcp_when_udp_stream_testing_is_not_permitted(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Warehouse',
            'local_ip' => '192.168.1.69',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'rtsp_transport' => 'udp',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-udp-fallback.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
transport=""
previous=""
for arg in "$@"; do
    if [[ "$previous" == "-rtsp_transport" ]]; then
        transport="$arg"
    fi
    previous="$arg"
done

if [[ "$transport" == "udp" ]]; then
    echo 'rtsp://operator:secret@192.168.1.69:554/stream1: Operation not permitted' >&2
    exit 1
fi

printf '%s' '{"streams":[{"codec_name":"h264","width":1920,"height":1080}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-udp-fallback.sh';
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
            'uri' => 'rtsp://192.168.1.69:554/stream1',
        ], 0);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('TCP', $profile['transport']);
        $this->assertSame('RTSP connection confirmed from this host.', $profile['probe_message']);
        $this->assertSame('direct', $profile['probe_source']);
        $this->assertTrue((bool) ($profile['transport_persistable'] ?? false));
        $this->assertNotNull($profile['preview_path']);
        $this->assertFileExists(storage_path('app/private/'.$profile['preview_path']));
    }

    public function test_it_uses_an_active_live_relay_when_the_camera_blocks_a_second_direct_session(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Hallway',
            'local_ip' => '192.168.1.71',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'rtsp_transport' => 'tcp',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'token' => 'profile_minor',
                        'uri' => 'rtsp://192.168.1.71:554/stream2',
                        'resolution' => '1280x720',
                        'encoding' => 'H264',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-relay-fallback.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" == *"camera-1-live"* ]]; then
    printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
    exit 0
fi

echo 'rtsp://operator:secret@192.168.1.71:554/stream2: Operation not permitted' >&2
exit 1
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-relay-fallback.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" != *"camera-1-live"* ]]; then
    echo 'rtsp://operator:secret@192.168.1.71:554/stream2: Operation not permitted' >&2
    exit 1
fi

output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.api.base_url', 'http://relay-api.example');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        Http::fake([
            'http://relay-api.example/v3/paths/list' => Http::response([
                'items' => [
                    [
                        'name' => 'camera-'.$camera->id.'-live',
                        'ready' => true,
                        'online' => true,
                    ],
                ],
            ], 200),
        ]);

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'MinorStream',
            'token' => 'profile_minor',
            'uri' => 'rtsp://192.168.1.71:554/stream2',
            'resolution' => '1280x720',
            'encoding' => 'H264',
        ], 0);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('TCP', $profile['transport']);
        $this->assertSame('Direct RTSP playback was blocked by the camera, so stream verification used the active shared relay instead.', $profile['probe_message']);
        $this->assertSame('Preview captured successfully through the active shared relay because the camera rejected an additional direct session.', $profile['preview_message']);
        $this->assertSame('relay', $profile['probe_source']);
        $this->assertFalse((bool) ($profile['transport_persistable'] ?? true));
        $this->assertNotNull($profile['preview_path']);
        $this->assertFileExists(storage_path('app/private/'.$profile['preview_path']));
        $this->assertSame('h264', $profile['video_codec']);
        $this->assertSame('1280x720', $profile['video_resolution']);
    }

    public function test_it_uses_an_active_recording_relay_when_the_live_path_is_idle(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Kids Room',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'rtsp_transport' => 'tcp',
            'recording_profile_index' => 1,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'token' => 'profile_main',
                        'uri' => 'rtsp://192.168.1.67:554/stream1',
                        'resolution' => '1920x1080',
                        'encoding' => 'H264',
                    ],
                    [
                        'name' => 'MinorStream',
                        'token' => 'profile_minor',
                        'uri' => 'rtsp://192.168.1.67:554/stream2',
                        'resolution' => '1280x720',
                        'encoding' => 'H264',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-recording-relay-fallback.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" == *"camera-1-recording-profile-1"* ]]; then
    printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
    exit 0
fi

echo 'rtsp://operator:secret@192.168.1.67:554/stream2: Operation not permitted' >&2
exit 1
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-recording-relay-fallback.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" != *"camera-1-recording-profile-1"* ]]; then
    echo 'rtsp://operator:secret@192.168.1.67:554/stream2: Operation not permitted' >&2
    exit 1
fi

output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.api.base_url', 'http://relay-api.example');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        Http::fake([
            'http://relay-api.example/v3/paths/list' => Http::response([
                'items' => [
                    [
                        'name' => 'camera-'.$camera->id.'-live',
                        'ready' => false,
                        'online' => false,
                    ],
                    [
                        'name' => 'camera-'.$camera->id.'-recording-profile-1',
                        'ready' => true,
                        'online' => true,
                    ],
                ],
            ], 200),
        ]);

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'MinorStream',
            'token' => 'profile_minor',
            'uri' => 'rtsp://192.168.1.67:554/stream2',
            'resolution' => '1280x720',
            'encoding' => 'H264',
        ], 1);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('TCP', $profile['transport']);
        $this->assertSame('Direct RTSP playback was blocked by the camera, so stream verification used the active shared relay instead.', $profile['probe_message']);
        $this->assertSame('Preview captured successfully through the active shared relay because the camera rejected an additional direct session.', $profile['preview_message']);
        $this->assertSame('relay', $profile['probe_source']);
        $this->assertFalse((bool) ($profile['transport_persistable'] ?? true));
        $this->assertNotNull($profile['preview_path']);
        $this->assertSame('h264', $profile['video_codec']);
        $this->assertSame('1280x720', $profile['video_resolution']);
    }

    public function test_it_uses_the_local_motion_recorder_buffer_when_the_camera_rejects_additional_rtsp_sessions(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.168.1.66',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'rtsp_transport' => 'tcp',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'token' => 'profile_minor',
                        'uri' => 'rtsp://192.168.1.66:554/stream2',
                        'path' => '/stream2',
                        'resolution' => '1280x720',
                        'encoding' => 'H264',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-motion-buffer-fallback.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" == *"motion-recorders/camera-1/segments"* ]]; then
    printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
    exit 0
fi

echo 'rtsp://operator:secret@192.168.1.66:554/stream2: Operation not permitted' >&2
exit 1
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-motion-buffer-fallback.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" != *"motion-recorders/camera-1/segments"* ]]; then
    echo 'rtsp://operator:secret@192.168.1.66:554/stream2: Operation not permitted' >&2
    exit 1
fi

output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $runtimeDirectory = storage_path('app/private/motion-recorders');
        $segmentDirectory = $runtimeDirectory.'/camera-'.$camera->id.'/segments';
        File::ensureDirectoryExists($segmentDirectory);
        File::put($segmentDirectory.'/20260413_114520-buffer.mkv', 'buffered-video');
        File::put($runtimeDirectory.'/camera-'.$camera->id.'.json', json_encode([
            'camera_id' => $camera->id,
            'source_index' => 0,
            'source_signature' => 'ignored-by-test',
            'output_pattern' => $segmentDirectory.'/%Y%m%d_%H%M%S-buffer.mkv',
            'started_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'MinorStream',
            'token' => 'profile_minor',
            'uri' => 'rtsp://192.168.1.66:554/stream2',
            'path' => '/stream2',
            'resolution' => '1280x720',
            'encoding' => 'H264',
        ], 0);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('Direct RTSP playback was blocked by the camera, so stream verification used the active motion recorder buffer for this same profile instead.', $profile['probe_message']);
        $this->assertSame('Preview captured successfully from the local motion recorder buffer because the camera rejected an additional direct session.', $profile['preview_message']);
        $this->assertSame('motion-buffer', $profile['probe_source']);
        $this->assertFalse((bool) ($profile['transport_persistable'] ?? true));
        $this->assertSame('h264', $profile['video_codec']);
        $this->assertSame('1280x720', $profile['video_resolution']);
        $this->assertNotNull($profile['preview_path']);
        $this->assertFileExists(storage_path('app/private/'.$profile['preview_path']));
    }

    public function test_it_can_start_an_idle_live_relay_when_the_camera_blocks_a_second_direct_session(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Nursery',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'rtsp_transport' => 'tcp',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'token' => 'profile_minor',
                        'uri' => 'rtsp://192.168.1.67:554/stream2',
                        'resolution' => '1280x720',
                        'encoding' => 'H264',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-relay-idle-fallback.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" == *"camera-1-live"* ]]; then
    printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
    exit 0
fi

echo 'rtsp://operator:secret@192.168.1.67:554/stream2: Operation not permitted' >&2
exit 1
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-relay-idle-fallback.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
joined="$*"

if [[ "$joined" != *"camera-1-live"* ]]; then
    echo 'rtsp://operator:secret@192.168.1.67:554/stream2: Operation not permitted' >&2
    exit 1
fi

output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $profile = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, [
            'name' => 'MinorStream',
            'token' => 'profile_minor',
            'uri' => 'rtsp://192.168.1.67:554/stream2',
            'resolution' => '1280x720',
            'encoding' => 'H264',
        ], 0);

        $this->assertSame('Healthy', $profile['probe_status']);
        $this->assertSame('Direct RTSP playback was blocked by the camera, so stream verification used the active shared relay instead.', $profile['probe_message']);
        $this->assertSame('Preview captured successfully through the active shared relay because the camera rejected an additional direct session.', $profile['preview_message']);
        $this->assertNotNull($profile['preview_path']);
    }
}