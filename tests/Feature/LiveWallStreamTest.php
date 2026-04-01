<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\Relay\MediaMtxProcessService;
use Mockery;
use Tests\TestCase;

class LiveWallStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_wall_prefers_an_efficient_substream_for_wall_tiles(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

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

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.index'));

        $response
            ->assertOk()
            ->assertSee(route('live-wall.player', ['camera' => $camera]), false)
            ->assertSee(route('live-wall.session', ['camera' => $camera]), false)
            ->assertSee(route('live-wall.relay', ['camera' => $camera, 'profileIndex' => 1]), false)
            ->assertSee('minorStream');
    }

    public function test_guest_users_are_redirected_to_login_for_the_live_wall(): void
    {
        $response = $this->get(route('live-wall.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_operator_can_request_a_short_lived_live_wall_session(): void
    {
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Tapo C200',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
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

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('live-wall.session', ['camera' => $camera]));

        $response
            ->assertOk()
            ->assertJsonPath('camera.path', 'camera-'.$camera->id.'-live')
            ->assertJsonPath('whep_url', 'https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep');

        $payload = $response->json();

        $this->assertNotEmpty($payload['access_token'] ?? null);
        $this->assertNotNull(app(MediaMtxAccessTokenService::class)->validate(
            $payload['access_token'],
            'camera-'.$camera->id.'-live',
            'read',
            'webrtc',
        ));
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

        $response = $this->actingAs(User::factory()->create())
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

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.relay', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');

        $this->assertStringContainsString('ftypisomrelay-data', $response->streamedContent());
    }

    private function mockRelayProcess(bool $running): void
    {
        $status = [
            'installed' => true,
            'running' => $running,
            'api_reachable' => $running,
            'config_changed' => false,
            'binary_path' => '/tmp/mediamtx',
            'config_path' => '/tmp/mediamtx.yml',
            'log_path' => '/tmp/mediamtx.log',
            'pid' => $running ? 1234 : null,
        ];

        $mock = Mockery::mock(MediaMtxProcessService::class);
        $mock->shouldReceive('ensureRunning')->andReturn($status);
        $mock->shouldReceive('status')->andReturn($status);

        $this->app->instance(MediaMtxProcessService::class, $mock);
    }
}