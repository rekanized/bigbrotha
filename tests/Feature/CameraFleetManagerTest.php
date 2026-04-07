<?php

namespace Tests\Feature;

use App\Livewire\CameraFleet\Manager;
use App\Models\Camera;
use App\Models\User;
use App\Services\CameraStorageService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CameraFleetManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_update_toggle_and_delete_cameras_from_the_gui(): void
    {
        $component = Livewire::test(Manager::class)
            ->call('newCamera')
            ->assertSet('isEditorModalOpen', true)
            ->set('form.name', 'Front Door')
            ->set('form.local_ip', '192.168.1.67')
            ->set('form.manufacturer', 'tp-link')
            ->set('form.model', 'Tapo C200')
            ->set('form.serial_number', '213ba426')
            ->set('form.onvif_port', 2020)
            ->set('form.http_port', 2020)
            ->set('form.username', 'operator')
            ->set('form.password', 'secret')
            ->call('saveCamera');

        $camera = Camera::query()->firstOrFail();

        $this->assertSame('Front Door', $camera->name);
        $this->assertSame('tp-link', $camera->manufacturer);
        $this->assertSame(2020, $camera->onvif_port);
        $this->assertSame('operator', $camera->username);
        $this->assertDirectoryExists(storage_path('app/private/cameras/'.$camera->id));
        $this->assertDirectoryExists(storage_path('app/private/cameras/'.$camera->id.'/previews'));

        $component
            ->call('editCamera', $camera->id)
            ->assertSet('isEditorModalOpen', true)
            ->set('form.name', 'Front Gate')
            ->set('form.password', '')
            ->call('saveCamera')
            ->call('toggleEnabled', $camera->id);

        $camera->refresh();

        $this->assertSame('Front Gate', $camera->name);
        $this->assertFalse($camera->is_enabled);
        $this->assertSame('secret', $camera->password);

        $component->call('deleteCamera', $camera->id);

        $this->assertDatabaseCount('cameras', 0);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/cameras/'.$camera->id));
    }

    public function test_it_fetches_rtsp_profiles_and_saves_them_to_the_camera_record(): void
    {
        $operator = User::factory()->create();

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
            'supports_rtsp' => false,
            'is_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            $body = $request->body();

            return match (true) {
                str_contains($body, '<tds:GetCapabilities>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
    <s:Body>
        <tds:GetCapabilitiesResponse>
            <tds:Capabilities>
                <tds:Media>
                    <tds:XAddr>http://192.168.1.67:2020/onvif/media_service</tds:XAddr>
                </tds:Media>
            </tds:Capabilities>
        </tds:GetCapabilitiesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:GetProfiles />') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">
    <s:Body>
        <trt:GetProfilesResponse>
            <trt:Profiles token="profile_main">
                <tt:Name>MainStream</tt:Name>
                <tt:VideoEncoderConfiguration>
                    <tt:Encoding>H264</tt:Encoding>
                    <tt:Resolution>
                        <tt:Width>1920</tt:Width>
                        <tt:Height>1080</tt:Height>
                    </tt:Resolution>
                </tt:VideoEncoderConfiguration>
            </trt:Profiles>
        </trt:GetProfilesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:ProfileToken>profile_main</trt:ProfileToken>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">
    <s:Body>
        <trt:GetStreamUriResponse>
            <trt:MediaUri>
                <trt:Uri>rtsp://192.168.1.67:554/stream1</trt:Uri>
            </trt:MediaUri>
        </trt:GetStreamUriResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                default => Http::response('', 500),
            };
        });

        Livewire::test(Manager::class)
            ->call('editCamera', $camera->id)
            ->call('fetchRtspProfiles');

        $camera->refresh();

        $this->assertTrue($camera->supports_rtsp);
        $this->assertSame('/stream1', $camera->rtsp_path);
        $this->assertCount(1, $camera->rtspProfiles());
        $this->assertSame('rtsp://192.168.1.67:554/stream1', $camera->rtspProfiles()[0]['uri']);
        $this->assertSame('http://192.168.1.67:2020/onvif/media_service', $camera->metadata['onvif']['media_service_url']);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-manager.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
printf '%s' '{"streams":[{"codec_name":"h264","width":1920,"height":1080}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-manager.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
output="${!#}"
    printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        Livewire::test(Manager::class)
            ->call('editCamera', $camera->id)
            ->call('testRtspProfile', 0);

        $camera->refresh();

        $this->assertSame('Healthy', $camera->rtspProfiles()[0]['probe_status']);
        $this->assertNotNull($camera->rtspProfiles()[0]['preview_path']);
        $this->assertStringStartsWith('cameras/'.$camera->id.'/previews/', $camera->rtspProfiles()[0]['preview_path']);
        $this->assertSame(0, $camera->latestRtspPreview()['index']);

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('camera-fleet.preview', ['camera' => $camera->id, 'profileIndex' => 0]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('camera-fleet.index'))
            ->assertOk()
            ->assertSee(route('camera-fleet.preview', ['camera' => $camera->id, 'profileIndex' => 0]), false);
    }

    public function test_it_can_save_and_test_a_rtsp_only_camera_without_onvif(): void
    {
        $component = Livewire::test(Manager::class)
            ->call('newCamera')
            ->set('form.name', 'RTSP Only Camera')
            ->set('form.local_ip', '192.168.1.88')
            ->set('form.supports_onvif', false)
            ->set('form.onvif_port', null)
            ->set('form.onvif_path', '')
            ->set('form.supports_rtsp', true)
            ->set('form.rtsp_port', 554)
            ->set('form.rtsp_path', '/manual-stream')
            ->set('form.username', 'operator')
            ->set('form.password', 'secret')
            ->call('saveCamera')
            ->assertHasNoErrors();

        $camera = Camera::query()->firstOrFail();

        $this->assertFalse($camera->supports_onvif);
        $this->assertTrue($camera->supports_rtsp);
        $this->assertSame('rtsp://192.168.1.88:554/manual-stream', $camera->rtspEndpoint());

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffprobeBinary = $binaryDirectory.'/ffprobe-rtsp-only.sh';
        File::put($ffprobeBinary, <<<'BASH'
#!/usr/bin/env bash
printf '%s' '{"streams":[{"codec_name":"h264","width":1280,"height":720}]}'
BASH);
        chmod($ffprobeBinary, 0755);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-rtsp-only.sh';
        File::put($ffmpegBinary, <<<'BASH'
#!/usr/bin/env bash
output="${!#}"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Xc6kAAAAASUVORK5CYII=' | base64 -d > "$output"
BASH);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffprobe.binaries', [$ffprobeBinary]);
        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $component
            ->call('editCamera', $camera->id)
            ->call('fetchRtspProfiles')
            ->assertSet('rtspErrorMessage', null)
            ->assertSet('rtspStatusMessage', 'Saved the configured RTSP endpoint for RTSP Only Camera without requiring ONVIF.')
            ->call('testRtspProfile', 0)
            ->assertSet('rtspErrorMessage', null);

        $camera->refresh();

        $this->assertCount(1, $camera->rtspProfiles());
        $this->assertSame('Saved endpoint', $camera->rtspProfiles()[0]['name']);
        $this->assertSame('rtsp://192.168.1.88:554/manual-stream', $camera->rtspProfiles()[0]['uri']);
        $this->assertSame('/manual-stream', $camera->rtspProfiles()[0]['path']);
        $this->assertSame('Healthy', $camera->rtspProfiles()[0]['probe_status']);
    }

    public function test_it_displays_the_camera_id_in_the_fleet_and_editor(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Warehouse Entrance',
            'local_ip' => '192.168.1.90',
            'http_port' => 80,
            'onvif_port' => 80,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'rtsp_transport' => 'tcp',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_OFF,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 35,
            'recording_motion_pre_roll_seconds' => 8,
            'recording_motion_post_trigger_seconds' => 20,
        ]);

        Livewire::test(Manager::class)
            ->assertSee('Camera ID')
            ->assertSee('#'.$camera->id)
            ->call('editCamera', $camera->id)
            ->assertSee('#'.$camera->id);
    }

    public function test_it_can_save_recording_policy_settings_from_the_gui(): void
    {
        $mask = [
            'version' => 1,
            'grid_width' => 8,
            'grid_height' => 6,
            'selected_pixels' => 24,
            'runs' => [
                [0, 11],
                [16, 27],
            ],
        ];

        Livewire::test(Manager::class)
            ->call('newCamera')
            ->set('form.name', 'Warehouse Entrance')
            ->set('form.local_ip', '192.168.1.90')
            ->set('form.supports_onvif', false)
            ->set('form.onvif_port', null)
            ->set('form.onvif_path', '')
            ->set('form.supports_rtsp', true)
            ->set('form.rtsp_port', 554)
            ->set('form.rtsp_path', '/record-stream')
            ->set('form.recording_mode', Camera::RECORDING_MODE_MOTION)
            ->set('form.recording_retention_days', 1)
            ->set('form.motion_sensitivity', 14)
            ->set('form.recording_motion_pre_roll_seconds', 12)
            ->set('form.recording_motion_post_trigger_seconds', 26)
            ->call('syncMotionMask', $mask)
            ->call('saveCamera')
            ->assertHasNoErrors();

        $camera = Camera::query()->firstOrFail();

        $this->assertSame(Camera::RECORDING_MODE_MOTION, $camera->recording_mode);
        $this->assertSame(1, $camera->recording_retention_days);
        $this->assertSame(14, $camera->motionTriggerThreshold());
        $this->assertSame(12, $camera->motionPreRollSeconds());
        $this->assertSame(26, $camera->motionPostTriggerSeconds());
        $this->assertSame($mask, $camera->recordingMotionMask());
    }

    public function test_it_bootstraps_a_profile_specific_motion_editor_session(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Warehouse Entrance',
            'local_ip' => '192.168.1.90',
            'rtsp_port' => 554,
            'rtsp_path' => '/record-stream',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => false,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'Main stream',
                        'uri' => 'rtsp://192.168.1.90:554/record-stream',
                    ],
                ],
            ],
        ]);

        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example.test/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-secret');

        $this->mock(MediaMtxProcessService::class, function ($mock): void {
            $mock->shouldReceive('ensureRunning')->once()->andReturn([
                'installed' => true,
                'running' => true,
                'api_reachable' => true,
                'config_changed' => false,
                'binary_path' => '/tmp/mediamtx',
                'config_path' => '/tmp/mediamtx.yml',
                'log_path' => '/tmp/mediamtx.log',
                'pid' => 321,
            ]);
        });

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->getJson(route('camera-fleet.motion-editor-session', ['camera' => $camera->id, 'profileIndex' => 0]))
            ->assertOk()
            ->assertJsonPath('camera.id', $camera->id)
            ->assertJsonPath('camera.path', 'camera-'.$camera->id.'-recording-profile-0')
            ->assertJsonPath('profile_index', 0)
            ->assertJsonPath('whep_url', 'https://relay.example.test/__webrtc/camera-'.$camera->id.'-recording-profile-0/whep');
    }

    public function test_it_falls_back_to_a_placeholder_image_when_a_saved_preview_is_invalid(): void
    {
        $operator = User::factory()->create();

        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'preview_path' => 'cameras/%d/previews/invalid-preview.jpg',
                        'preview_generated_at' => '2026-04-01 19:21:29 UTC',
                    ],
                ],
            ],
        ]);

        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        $previewPath = storage_path('app/private/'.sprintf('cameras/%d/previews/invalid-preview.jpg', $camera->id));
        File::put($previewPath, 'preview');

        $this->actingAs($operator)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->get(route('camera-fleet.preview', ['camera' => $camera->id, 'profileIndex' => 0]))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('Preview unavailable', false);
    }

    public function test_it_preserves_saved_preview_metadata_when_rtsp_profiles_are_refreshed(): void
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
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MainStream',
                        'token' => 'profile_main',
                        'uri' => 'rtsp://192.168.1.67:554/stream1',
                        'path' => '/stream1',
                        'preview_path' => null,
                        'preview_generated_at' => '2026-04-03 08:20:00 UTC',
                    ],
                ],
            ],
        ]);

        $metadata = $camera->metadata;
        $metadata['rtsp_profiles'][0]['preview_path'] = 'cameras/'.$camera->id.'/previews/mainstream-1.jpg';
        $camera->metadata = $metadata;
        $camera->save();

        Http::fake(function (Request $request) {
            $body = $request->body();

            return match (true) {
                str_contains($body, '<tds:GetCapabilities>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
    <s:Body>
        <tds:GetCapabilitiesResponse>
            <tds:Capabilities>
                <tds:Media>
                    <tds:XAddr>http://192.168.1.67:2020/onvif/media_service</tds:XAddr>
                </tds:Media>
            </tds:Capabilities>
        </tds:GetCapabilitiesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:GetProfiles />') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">
    <s:Body>
        <trt:GetProfilesResponse>
            <trt:Profiles token="profile_main">
                <tt:Name>MainStream</tt:Name>
                <tt:VideoEncoderConfiguration>
                    <tt:Encoding>H264</tt:Encoding>
                    <tt:Resolution>
                        <tt:Width>1920</tt:Width>
                        <tt:Height>1080</tt:Height>
                    </tt:Resolution>
                </tt:VideoEncoderConfiguration>
            </trt:Profiles>
        </trt:GetProfilesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:ProfileToken>profile_main</trt:ProfileToken>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">
    <s:Body>
        <trt:GetStreamUriResponse>
            <trt:MediaUri>
                <trt:Uri>rtsp://192.168.1.67:554/stream1</trt:Uri>
            </trt:MediaUri>
        </trt:GetStreamUriResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                default => Http::response('', 500),
            };
        });

        Livewire::test(Manager::class)
            ->call('editCamera', $camera->id)
            ->call('fetchRtspProfiles')
            ->assertSet('rtspErrorMessage', null);

        $camera->refresh();

        $this->assertSame('cameras/'.$camera->id.'/previews/mainstream-1.jpg', $camera->rtspProfiles()[0]['preview_path']);
        $this->assertSame('2026-04-03 08:20:00 UTC', $camera->rtspProfiles()[0]['preview_generated_at']);
    }
}