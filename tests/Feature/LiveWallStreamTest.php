<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\LiveWall;
use App\Models\User;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\Relay\MediaMtxPathStatusService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LiveWallStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_tiles_keep_their_identity_without_promising_a_retry(): void
    {
        $this->mockRelayProcess(running: true);
        $camera = Camera::query()->create([
            'name' => 'Unconfigured entrance', 'local_ip' => '192.0.2.91',
            'supports_rtsp' => false, 'is_enabled' => true,
        ]);
        LiveWall::query()->firstOrFail()->tiles()->create([
            'camera_id' => $camera->id, 'position' => 1, 'orientation' => 'portrait',
            'column_span' => 1, 'row_span' => 1, 'is_enabled' => true,
        ]);
        $this->actingAs(User::factory()->create())->get(route('live-wall.index'))
            ->assertOk()
            ->assertSee('Unconfigured entrance')
            ->assertSee('data-orientation="portrait"', false)
            ->assertSee('No live stream is configured.')
            ->assertDontSee('every 15 seconds')
            ->assertDontSee('Open previous wall')
            ->assertDontSee('Open next wall');
    }

    public function test_wall_keeps_retrying_players_when_the_relay_is_down_during_page_load(): void
    {
        $this->mockRelayProcess(running: false, apiReachable: false);
        $camera = Camera::query()->create([
            'name' => 'Recovering camera', 'local_ip' => '192.0.2.90',
            'rtsp_port' => 554, 'rtsp_path' => '/main', 'supports_rtsp' => true, 'is_enabled' => true,
            'metadata' => ['rtsp_profiles' => [[
                'name' => 'Main', 'encoding' => 'H264', 'uri' => 'rtsp://192.0.2.90:554/main',
            ]]],
        ]);
        LiveWall::query()->firstOrFail()->tiles()->create([
            'camera_id' => $camera->id, 'position' => 1, 'orientation' => 'landscape',
            'column_span' => 1, 'row_span' => 1, 'is_enabled' => true,
        ]);
        $this->actingAs(User::factory()->create())->get(route('live-wall.index'))
            ->assertOk()
            ->assertSee('data-webrtc-player', false)
            ->assertSee('data-session-url="'.route('live-wall.session', ['camera' => $camera]).'"', false)
            ->assertViewHas('tiles', fn ($tiles) => $tiles->first()['sessionBootstrap'] === null);

        $this->get(route('live-wall.player', ['camera' => $camera]))
            ->assertOk()
            ->assertSee('data-webrtc-player', false)
            ->assertSee('data-session-url="'.route('live-wall.session', ['camera' => $camera]).'"', false);
    }

    public function test_live_wall_uses_the_configured_live_feed_path_for_wall_tiles(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Tapo C200',
            'local_ip' => '192.0.2.67',
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
                        'uri' => 'rtsp://192.0.2.67:554/stream1',
                        'path' => '/stream1',
                        'probe_status' => 'Healthy',
                        'transport_persistable' => true,
                    ],
                    [
                        'token' => 'profile_2',
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.67:554/stream2',
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
            ->assertSee('data-profile-index="0"', false)
            ->assertSee('data-camera-name="Tapo C200"', false)
            ->assertSee('data-reader-url="http://relay.example:8889/camera-'.$camera->id.'-live/reader.js"', false)
            ->assertSee('data-whep-url="http://relay.example:8889/camera-'.$camera->id.'-live/whep"', false)
            ->assertSee('data-access-token="', false)
            ->assertSee('data-access-token-expires-in="180"', false)
            ->assertSee('data-access-token-issued-at="', false)
            ->assertSee('data-expected-video-codec="h264"', false)
            ->assertSee('data-expected-audio-codec="opus"', false)
            ->assertSee('data-expected-audio-channels="2"', false)
            ->assertSee('data-expected-audio-sample-rate="48000"', false)
            ->assertSee('data-role="audio-toggle"', false)
            ->assertSee('aria-label="Listen to Tapo C200"', false)
            ->assertSee('data-role="audio-indicator"', false)
            ->assertSee('data-role="master-volume-slider"', false)
            ->assertSee('data-role="master-volume-value"', false)
            ->assertDontSee('data-role="volume-slider"', false)
            ->assertSee('data-live-wall-grid', false)
            ->assertSee('data-player-lifecycle="viewport"', false)
            ->assertSee('preload="none"', false)
            ->assertSee('disablepictureinpicture', false)
            ->assertSee('data-navigate-once', false)
            ->assertSee(route('wall-tiles.index'), false);

        $this->assertMatchesRegularExpression(
            '/<div\s+class="webrtc-player"[^>]*data-webrtc-player[^>]*data-session-url="'.preg_quote(route('live-wall.session', ['camera' => $camera]), '/').'"[^>]*data-reader-url="'.preg_quote('http://relay.example:8889/camera-'.$camera->id.'-live/reader.js', '/').'"[^>]*data-whep-url="'.preg_quote('http://relay.example:8889/camera-'.$camera->id.'-live/whep', '/').'"[^>]*data-access-token="[^"]+"/s',
            $response->getContent(),
        );
    }

    public function test_live_wall_does_not_fall_back_when_the_configured_live_path_failed(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Back Lot',
            'local_ip' => '192.0.2.75',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.75:554/stream1',
                        'path' => '/stream1',
                        'probe_status' => 'Healthy',
                        'transport_persistable' => true,
                    ],
                    [
                        'token' => 'profile_2',
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.75:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Failed',
                        'transport_persistable' => false,
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
            ->assertDontSee('The wall retries configured live feeds automatically every 15 seconds.', false)
            ->assertSee('data-webrtc-player', false);
    }

    public function test_live_wall_does_not_render_saved_preview_images_when_live_stream_bootstrap_is_unavailable(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        File::ensureDirectoryExists(storage_path('app/private/cameras/1/previews'));
        File::put(
            storage_path('app/private/cameras/1/previews/minorstream-2.jpg'),
            base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEA8QDw8QDw8PDw8PDw8QFREWFhURExUYHSggGBolGxUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGhAQGi0fHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAXAAADAQAAAAAAAAAAAAAAAAAAAQMC/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEAMQAAAB6A//xAAXEAEBAQEAAAAAAAAAAAAAAAABEQAh/9oACAEBAAEFAk1//8QAFBEBAAAAAAAAAAAAAAAAAAAAEP/aAAgBAwEBPwEf/8QAFBEBAAAAAAAAAAAAAAAAAAAAEP/aAAgBAgEBPwEf/8QAFxABAQEBAAAAAAAAAAAAAAAAAREAITFh/9oACAEBAAY/AkqP/8QAGhABAQEAAwEAAAAAAAAAAAAAAREAITFBUf/aAAgBAQABPyG1GEl4soR2f//aAAwDAQACAAMAAAAQ/wD/xAAVEQEBAAAAAAAAAAAAAAAAAAABEP/aAAgBAwEBPxBf/8QAFBEBAAAAAAAAAAAAAAAAAAAAEP/aAAgBAgEBPxAf/8QAGhABAQADAQEAAAAAAAAAAAAAAREAITFBYf/aAAgBAQABPxBMa9Y2U0R0uL0T6n//2Q==', true)
        );

        $camera = Camera::query()->create([
            'name' => 'Back Lot',
            'local_ip' => '192.0.2.75',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.75:554/stream1',
                        'path' => '/stream1',
                        'probe_status' => 'Healthy',
                        'transport_persistable' => true,
                    ],
                    [
                        'token' => 'profile_2',
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.75:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Failed',
                        'transport_persistable' => false,
                        'preview_path' => 'cameras/1/previews/minorstream-2.jpg',
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
            ->assertDontSee(route('camera-fleet.preview', ['camera' => $camera, 'profileIndex' => 1]), false)
            ->assertDontSee('Latest saved preview for Back Lot', false)
            ->assertSee('data-webrtc-player', false);
    }

    public function test_live_wall_only_renders_cameras_assigned_to_the_selected_wall(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $frontDoor = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.0.2.70',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/front-door-sub',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'FrontDoorMinor',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.70:554/front-door-sub',
                    ],
                ],
            ],
        ]);

        $warehouse = Camera::query()->create([
            'name' => 'Warehouse',
            'local_ip' => '192.0.2.71',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/warehouse-portrait',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'WarehousePortrait',
                        'encoding' => 'H264',
                        'resolution' => '720x1280',
                        'uri' => 'rtsp://192.0.2.71:554/warehouse-portrait',
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
            'local_ip' => '192.0.2.80',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/loop-minor',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'LoopMinor',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.80:554/loop-minor',
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
            ->assertSee(route('recordings.timeline'), false)
            ->assertSee('Sign out')
            ->assertSee('Wall 2 of 3');
    }

    public function test_single_camera_player_marks_the_live_wall_script_to_load_once_with_wire_navigate(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'http://relay.example:8889');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Loading Dock',
            'local_ip' => '192.0.2.68',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.68:554/stream2',
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

    public function test_authenticated_non_google_users_can_access_media_routes(): void
    {
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Restricted Camera',
            'local_ip' => '192.0.2.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'MinorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.67:554/stream2',
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'google_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('live-wall.player', ['camera' => $camera]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertOk();
    }

    public function test_authenticated_operator_can_request_a_short_lived_live_wall_session(): void
    {
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Tapo C200',
            'local_ip' => '192.0.2.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.67:554/stream2',
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
            ->assertJsonPath('whep_url', 'https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep')
            ->assertJsonPath('stream.video_codec', 'h264')
            ->assertJsonPath('stream.audio_codec', 'opus')
            ->assertJsonPath('stream.audio_channels', 2)
            ->assertJsonPath('stream.audio_sample_rate', 48000);

        $payload = $response->json();

        $this->assertNotEmpty($payload['access_token'] ?? null);
        $this->assertNotNull(app(MediaMtxAccessTokenService::class)->validate(
            $payload['access_token'],
            'camera-'.$camera->id.'-live',
            'read',
            'webrtc',
        ));

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_live_wall_session_checks_relay_health_without_resyncing_its_configuration(): void
    {
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');

        $status = [
            'installed' => true,
            'running' => true,
            'api_reachable' => true,
            'config_changed' => false,
            'binary_path' => '/tmp/mediamtx',
            'config_path' => '/tmp/mediamtx.yml',
            'log_path' => '/tmp/mediamtx.log',
            'pid' => 1234,
        ];

        $relayProcess = Mockery::mock(MediaMtxProcessService::class);
        $relayProcess->shouldReceive('status')->once()->andReturn($status);
        $relayProcess->shouldNotReceive('ensureRunning');
        $this->app->instance(MediaMtxProcessService::class, $relayProcess);

        $camera = Camera::query()->create([
            'name' => 'Session Health Camera',
            'local_ip' => '192.0.2.99',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [[
                    'name' => 'Sub stream',
                    'encoding' => 'H264',
                    'resolution' => '1280x720',
                    'uri' => 'rtsp://192.0.2.99:554/stream2',
                ]],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertOk();
    }

    public function test_live_wall_session_returns_not_found_when_the_selected_profile_is_only_verified_via_the_motion_buffer(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.0.2.66',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.66:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Healthy',
                        'probe_source' => 'motion-buffer',
                        'transport_persistable' => false,
                        'preview_path' => 'cameras/1/previews/minorstream-2.jpg',
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

        $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertNotFound();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.index'));

        $response
            ->assertOk()
            ->assertDontSee('data-session-url="'.route('live-wall.session', ['camera' => $camera]).'"', false)
            ->assertDontSee('data-reader-url="https://relay.example/__webrtc/camera-'.$camera->id.'-live/reader.js"', false)
            ->assertDontSee('data-whep-url="https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep"', false);
    }

    public function test_live_wall_session_allows_retry_bootstrap_for_a_failed_configured_profile(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        $this->mockRelayProcess(running: true);

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.0.2.66',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.66:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Failed',
                        'transport_persistable' => false,
                    ],
                ],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertOk()
            ->assertJsonPath('camera.path', 'camera-'.$camera->id.'-live')
            ->assertJsonPath('whep_url', 'https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep');
    }

    public function test_live_wall_session_can_use_an_already_active_live_path_even_when_the_profile_is_not_cold_startable(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        config()->set('mediamtx.api.base_url', 'http://relay-api.example');
        $this->mockRelayProcess(running: true);

        Http::fake([
            'http://relay-api.example/v3/paths/list' => Http::response([
                'items' => [
                    [
                        'name' => 'camera-1-live',
                        'ready' => true,
                        'online' => true,
                    ],
                ],
            ], 200),
        ]);

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.0.2.66',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.66:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Healthy',
                        'probe_source' => 'motion-buffer',
                        'transport_persistable' => false,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]));

        $response
            ->assertOk()
            ->assertJsonPath('camera.path', 'camera-'.$camera->id.'-live')
            ->assertJsonPath('whep_url', 'https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep');
    }

    public function test_live_wall_session_cold_starts_for_a_relay_verified_healthy_profile(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        config()->set('mediamtx.auth.token_secret', 'test-stream-secret');
        $this->mockRelayProcess(running: true);

        Http::fake([
            'http://relay:9997/v3/paths/list' => Http::response([
                'items' => [],
            ], 200),
        ]);

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.0.2.66',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
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
                        'uri' => 'rtsp://192.0.2.66:554/stream2',
                        'path' => '/stream2',
                        'probe_status' => 'Healthy',
                        'probe_source' => 'relay',
                        'transport_persistable' => false,
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

        $this->actingAs(User::factory()->create())
            ->getJson(route('live-wall.session', ['camera' => $camera]))
            ->assertOk()
            ->assertJsonPath('camera.path', 'camera-'.$camera->id.'-live')
            ->assertJsonPath('whep_url', 'https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.index'));

        $response
            ->assertOk()
            ->assertSee('data-session-url="'.route('live-wall.session', ['camera' => $camera]).'"', false)
            ->assertSee('data-reader-url="https://relay.example/__webrtc/camera-'.$camera->id.'-live/reader.js"', false)
            ->assertSee('data-whep-url="https://relay.example/__webrtc/camera-'.$camera->id.'-live/whep"', false);
    }

    public function test_live_wall_session_returns_service_unavailable_when_the_relay_api_is_unhealthy(): void
    {
        config()->set('mediamtx.auto_start', false);
        config()->set('mediamtx.webrtc.public_base_url', 'https://relay.example/__webrtc');
        $this->mockRelayProcess(running: true, apiReachable: false);

        $camera = Camera::query()->create([
            'name' => 'Back Entrance',
            'local_ip' => '192.0.2.90',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'minorStream',
                        'encoding' => 'H264',
                        'resolution' => '1280x720',
                        'uri' => 'rtsp://192.0.2.90:554/stream2',
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
            'local_ip' => '192.0.2.67',
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
                        'uri' => 'rtsp://192.0.2.67:554/stream2',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-live-mjpeg.sh';
        $argumentsPath = $binaryDirectory.'/ffmpeg-live-mjpeg-arguments.txt';
        File::put($ffmpegBinary, '#!/usr/bin/env bash'.PHP_EOL
            .'printf \'%s\n\' "$@" > '.escapeshellarg($argumentsPath).PHP_EOL
            ."printf '%s' '--bigbrotha-live\\r\\nContent-Type: image/jpeg\\r\\n\\r\\nframe-one\\r\\n--bigbrotha-live--\\r\\n'".PHP_EOL);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        $pathStatus = Mockery::mock(MediaMtxPathStatusService::class);
        $pathStatus->shouldReceive('activePaths')->once()->andReturn([]);
        $this->app->instance(MediaMtxPathStatusService::class, $pathStatus);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.stream', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'multipart/x-mixed-replace;boundary=bigbrotha-live');

        $this->assertStringContainsString('frame-one', $response->streamedContent());
        $this->assertStringContainsString('rtsp://operator:secret@192.0.2.67:554/stream2', File::get($argumentsPath));
    }

    public function test_it_streams_a_copy_relay_without_reencoding_video(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.0.2.67',
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
                        'uri' => 'rtsp://192.0.2.67:554/stream1',
                    ],
                ],
            ],
        ]);

        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $ffmpegBinary = $binaryDirectory.'/ffmpeg-live-relay.sh';
        $argumentsPath = $binaryDirectory.'/ffmpeg-live-relay-arguments.txt';
        File::put($ffmpegBinary, '#!/usr/bin/env bash'.PHP_EOL
            .'printf \'%s\n\' "$@" > '.escapeshellarg($argumentsPath).PHP_EOL
            ."printf '....ftypisomrelay-data'".PHP_EOL);
        chmod($ffmpegBinary, 0755);

        config()->set('ffmpeg.ffmpeg.binaries', [$ffmpegBinary]);
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://relay:8554');
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        $sourcePath = 'camera-'.$camera->id.'-source-profile-0';
        $pathStatus = Mockery::mock(MediaMtxPathStatusService::class);
        $pathStatus->shouldReceive('activePaths')->once()->andReturn([$sourcePath => true]);
        $this->app->instance(MediaMtxPathStatusService::class, $pathStatus);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('live-wall.relay', ['camera' => $camera, 'profileIndex' => 0]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');

        $this->assertStringContainsString('ftypisomrelay-data', $response->streamedContent());
        $this->assertStringContainsString('rtsp://internal-reader:relay-pass@relay:8554/'.$sourcePath, File::get($argumentsPath));
        $this->assertStringNotContainsString('192.0.2.67', File::get($argumentsPath));
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
