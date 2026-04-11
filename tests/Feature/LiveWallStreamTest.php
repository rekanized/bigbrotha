<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\LiveWall;
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

        LiveWall::query()->firstOrFail()->tiles()->create([
            'camera_id' => $camera->id,
            'position' => 1,
            'orientation' => 'landscape',
            'column_span' => 1,
            'row_span' => 1,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.index'));

        $response
            ->assertOk()
            ->assertSee(route('live-wall.session', ['camera' => $camera]), false)
            ->assertSee('data-profile-index="1"', false)
            ->assertSee('data-camera-name="Tapo C200"', false)
            ->assertSee('data-reader-url="http://relay.example:8889/camera-'.$camera->id.'-live/reader.js"', false)
            ->assertSee('data-whep-url="http://relay.example:8889/camera-'.$camera->id.'-live/whep"', false)
            ->assertSee('data-access-token="', false)
            ->assertSee('data-access-token-expires-in="180"', false)
            ->assertSee('data-access-token-issued-at="', false)
            ->assertSee('data-role="audio-toggle"', false)
            ->assertSee('aria-label="Listen to Tapo C200"', false)
            ->assertSee('data-role="audio-indicator"', false)
            ->assertSee('data-role="master-volume-slider"', false)
            ->assertSee('data-role="master-volume-value"', false)
            ->assertDontSee('data-role="volume-slider"', false)
            ->assertSee('data-live-wall-grid', false)
            ->assertSee('data-navigate-once', false)
            ->assertSee(route('wall-tiles.index'), false);

        $this->assertMatchesRegularExpression(
            '/<div\s+class="webrtc-player"[^>]*data-webrtc-player[^>]*data-session-url="'.preg_quote(route('live-wall.session', ['camera' => $camera]), '/').'"[^>]*data-reader-url="'.preg_quote('http://relay.example:8889/camera-'.$camera->id.'-live/reader.js', '/').'"[^>]*data-whep-url="'.preg_quote('http://relay.example:8889/camera-'.$camera->id.'-live/whep', '/').'"[^>]*data-access-token="[^"]+"/s',
            $response->getContent(),
        );
    }

    public function test_live_wall_only_renders_cameras_assigned_to_the_selected_wall(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $frontDoor = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.70',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'FrontDoorMinor',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.70:554/front-door-sub',
                    ],
                ],
            ],
        ]);

        $warehouse = Camera::query()->create([
            'name' => 'Warehouse',
            'local_ip' => '192.168.1.71',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'WarehousePortrait',
                        'encoding' => 'H264',
                        'resolution' => '720x1280',
                        'uri' => 'rtsp://192.168.1.71:554/warehouse-portrait',
                    ],
                ],
            ],
        ]);

        $defaultWall = LiveWall::query()->firstOrFail();
        $defaultWall->update([
            'name' => 'Primary Wall',
            'slug' => 'primary-wall',
        ]);
        $defaultWall->tiles()->create([
            'camera_id' => $frontDoor->id,
            'position' => 1,
            'orientation' => 'landscape',
            'column_span' => 1,
            'row_span' => 1,
            'is_enabled' => true,
        ]);

        $portraitWall = LiveWall::query()->create([
            'name' => 'Portrait Wall',
            'slug' => 'portrait-wall',
            'grid_columns' => 2,
            'default_tile_orientation' => 'portrait',
            'is_default' => false,
            'is_active' => true,
        ]);
        $portraitWall->tiles()->create([
            'camera_id' => $warehouse->id,
            'position' => 1,
            'orientation' => 'portrait',
            'column_span' => 1,
            'row_span' => 1,
            'is_enabled' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('live-wall.index'))
            ->assertOk()
            ->assertSee('data-camera-name="Front Door"', false)
            ->assertDontSee('data-camera-name="Warehouse"', false);

        $this->actingAs($user)
            ->get(route('live-wall.index', ['wall' => $portraitWall->slug]))
            ->assertOk()
            ->assertSee('data-camera-name="Warehouse"', false)
            ->assertSee(route('live-wall.session', ['camera' => $warehouse]), false)
            ->assertDontSee('data-camera-name="Front Door"', false);
    }

    public function test_live_wall_bottom_navigation_loops_between_available_walls(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Loop Camera',
            'local_ip' => '192.168.1.80',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'LoopMinor',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.80:554/loop-minor',
                    ],
                ],
            ],
        ]);

        $wallOne = LiveWall::query()->firstOrFail();
        $wallOne->update(['name' => 'Wall One', 'slug' => 'wall-one']);
        $wallOne->tiles()->create([
            'camera_id' => $camera->id,
            'position' => 1,
            'orientation' => 'landscape',
            'column_span' => 1,
            'row_span' => 1,
            'is_enabled' => true,
        ]);

        $wallTwo = LiveWall::query()->create([
            'name' => 'Wall Two',
            'slug' => 'wall-two',
            'grid_columns' => 2,
            'default_tile_orientation' => 'landscape',
            'is_default' => false,
            'is_active' => true,
        ]);

        $wallThree = LiveWall::query()->create([
            'name' => 'Wall Three',
            'slug' => 'wall-three',
            'grid_columns' => 2,
            'default_tile_orientation' => 'landscape',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.index', ['wall' => $wallThree->slug]));

        $response
            ->assertOk()
            ->assertSee(route('live-wall.index', ['wall' => $wallTwo->slug]), false)
            ->assertSee(route('live-wall.index', ['wall' => $wallOne->slug]), false)
            ->assertSee('Wall 2 of 3');
    }

    public function test_single_camera_player_marks_the_live_wall_script_to_load_once_with_wire_navigate(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Loading Dock',
            'local_ip' => '192.168.1.68',
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
                        'uri' => 'rtsp://192.168.1.68:554/stream2',
                        'path' => '/stream2',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.player', ['camera' => $camera]));

        $response
            ->assertOk()
            ->assertSee('data-navigate-once', false)
            ->assertSee(route('live-wall.session', ['camera' => $camera]), false);
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

    public function test_live_wall_session_returns_service_unavailable_when_the_relay_api_is_unhealthy(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        $this->mockRelayProcess(running: true, apiReachable: false);

        $camera = Camera::query()->create([
            'name' => 'Back Entrance',
            'local_ip' => '192.168.1.90',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.168.1.90:554/stream2',
                        'path' => '/stream2',
                    ],
                ],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertStatus(503)
            ->assertSeeText('Media relay is not available.');
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
    File::put($ffmpegBinary, '#!/usr/bin/env bash'.PHP_EOL."printf '%s' '--bigbrotha-live\\r\\nContent-Type: image/jpeg\\r\\n\\r\\nframe-one\\r\\n--bigbrotha-live--\\r\\n'".PHP_EOL);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.stream', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'multipart/x-mixed-replace;boundary=bigbrotha-live');

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

    private function mockRelayProcess(bool $running, ?bool $apiReachable = null): void
    {
        $apiReachable ??= $running;

        $status = [
            'installed' => true,
            'running' => $running,
            'api_reachable' => $apiReachable,
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