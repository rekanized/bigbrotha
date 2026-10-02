<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\LiveWall;
use App\Models\User;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveWallPtzTest extends TestCase
{
    use RefreshDatabase;

    private function camera(array $attributes = []): Camera
    {
        return Camera::query()->create(array_merge([
            'name' => 'Movable camera', 'local_ip' => '192.0.2.10',
            'onvif_port' => 2020, 'onvif_path' => '/onvif/device_service',
            'rtsp_port' => 554, 'rtsp_path' => '/main',
            'username' => 'operator', 'password' => 'private-camera-secret',
            'supports_onvif' => true, 'supports_rtsp' => true, 'is_enabled' => true,
            'metadata' => ['rtsp_profiles' => [['token' => 'main', 'uri' => 'rtsp://192.0.2.10:554/main', 'path' => '/main', 'encoding' => 'H264']]],
        ], $attributes));
    }

    private function envelope(string $body): string
    {
        return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema"><s:Body>'.$body.'</s:Body></s:Envelope>';
    }

    private function space(string $name): string
    {
        $y = $name === 'ContinuousPanTiltVelocitySpace' ? '<tt:YRange><tt:Min>-2</tt:Min><tt:Max>2</tt:Max></tt:YRange>' : '';

        return '<tt:'.$name.'><tt:URI>urn:camera:'.$name.'</tt:URI><tt:XRange><tt:Min>-1</tt:Min><tt:Max>1</tt:Max></tt:XRange>'.$y.'</tt:'.$name.'>';
    }

    private function fakePtz(bool $panTilt = true, bool $zoom = true, string $minTimeout = 'PT0.1S'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($panTilt, $zoom, $minTimeout) {
            $body = $request->body();
            $reply = match (true) {
                str_contains($body, '<tds:GetCapabilities>') => '<tds:GetCapabilitiesResponse><tds:Capabilities><tt:Media><tt:XAddr>http://192.0.2.10:2020/onvif/media</tt:XAddr></tt:Media><tt:PTZ><tt:XAddr>http://192.0.2.10:2020/onvif/ptz</tt:XAddr></tt:PTZ></tds:Capabilities></tds:GetCapabilitiesResponse>',
                str_contains($body, '<trt:GetProfiles') => '<trt:GetProfilesResponse><trt:Profiles token="other"><tt:PTZConfiguration token="other-config" /></trt:Profiles><trt:Profiles token="main"><tt:PTZConfiguration token="main-config" /></trt:Profiles></trt:GetProfilesResponse>',
                str_contains($body, '<tptz:GetConfigurationOptions>') => '<tptz:GetConfigurationOptionsResponse><tptz:PTZConfigurationOptions><tt:Spaces>'.($panTilt ? $this->space('ContinuousPanTiltVelocitySpace') : '').($zoom ? $this->space('ContinuousZoomVelocitySpace') : '').'</tt:Spaces><tt:PTZTimeout><tt:Min>'.$minTimeout.'</tt:Min><tt:Max>PT60S</tt:Max></tt:PTZTimeout></tptz:PTZConfigurationOptions></tptz:GetConfigurationOptionsResponse>',
                str_contains($body, '<tptz:ContinuousMove>') => '<tptz:ContinuousMoveResponse />',
                str_contains($body, '<tptz:Stop>') => '<tptz:StopResponse />',
                default => throw new \RuntimeException('Unexpected SOAP operation'),
            };

            return Http::response($this->envelope($reply));
        });
    }

    public function test_ptz_requires_an_authenticated_operator(): void
    {
        $camera = $this->camera();
        Http::fake();
        $this->getJson(route('live-wall.ptz.show', $camera))->assertUnauthorized();
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'left'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_disabled_and_rtsp_only_cameras_cannot_be_moved(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());
        $disabled = $this->camera(['is_enabled' => false]);
        $this->getJson(route('live-wall.ptz.show', $disabled))->assertNotFound();
        $this->postJson(route('live-wall.ptz.store', $disabled), ['command' => 'left'])->assertNotFound();
        $rtsp = $this->camera(['supports_onvif' => false]);
        $this->getJson(route('live-wall.ptz.show', $rtsp))->assertExactJson(['supported' => false, 'pan_tilt' => false, 'zoom' => false]);
        $this->postJson(route('live-wall.ptz.store', $rtsp), ['command' => 'left'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_capabilities_are_discovered_cached_and_do_not_expose_connection_details(): void
    {
        $this->fakePtz();
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 2; $i++) {
            $this->getJson(route('live-wall.ptz.show', $camera))
                ->assertOk()->assertExactJson(['supported' => true, 'pan_tilt' => true, 'zoom' => true])
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<tptz:ConfigurationToken>main-config</tptz:ConfigurationToken>'));
        $camera->update(['password' => 'changed-secret']);
        $this->getJson(route('live-wall.ptz.show', $camera))->assertOk();
        Http::assertSentCount(6);
    }

    public function test_direction_zoom_and_stop_use_the_discovered_profile_with_bounded_velocity(): void
    {
        $this->fakePtz();
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'up-left'])->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'http://192.0.2.10:2020/onvif/ptz'
            && str_contains($request->body(), '<tptz:ProfileToken>main</tptz:ProfileToken>')
            && str_contains($request->body(), '<tt:PanTilt x="-0.35" y="0.7"')
            && str_contains($request->body(), '<tptz:Timeout>PT1S</tptz:Timeout>')
            && str_contains($request->body(), 'PasswordDigest')
            && ! str_contains($request->body(), 'private-camera-secret'));
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'zoom-out'])->assertOk();
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<tt:Zoom x="-0.35"'));
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'stop'])->assertOk()->assertJson(['message' => 'Camera stopped.']);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<tptz:Stop>')
            && str_contains($request->body(), '<tptz:PanTilt>true</tptz:PanTilt><tptz:Zoom>true</tptz:Zoom>'));
        Http::assertSentCount(6);
    }

    public function test_zoom_only_cameras_do_not_offer_pan_tilt_and_reject_direction_commands(): void
    {
        $this->fakePtz(panTilt: false);
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        $this->getJson(route('live-wall.ptz.show', $camera))->assertExactJson(['supported' => true, 'pan_tilt' => false, 'zoom' => true]);
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'up'])->assertUnprocessable();
        Http::assertSentCount(3);
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'zoom-in'])->assertOk();
    }

    public function test_pan_tilt_only_cameras_reject_zoom_commands(): void
    {
        $this->fakePtz(zoom: false);
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        $this->getJson(route('live-wall.ptz.show', $camera))->assertExactJson(['supported' => true, 'pan_tilt' => true, 'zoom' => false]);
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'zoom-in'])->assertUnprocessable();
        Http::assertSentCount(3);
    }

    public function test_missing_ptz_service_and_unsupported_movement_do_not_advertise_controls(): void
    {
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response($this->envelope('<tds:GetCapabilitiesResponse><tds:Capabilities /></tds:GetCapabilitiesResponse>'))]);
        $this->getJson(route('live-wall.ptz.show', $camera))->assertJson(['supported' => false]);
        Http::assertSentCount(1);
        Cache::flush();
        $this->fakePtz(panTilt: false, zoom: false);
        $this->getJson(route('live-wall.ptz.show', $camera))->assertJson(['supported' => false]);
        Cache::flush();
        $this->fakePtz(minTimeout: 'PT10S');
        $this->getJson(route('live-wall.ptz.show', $camera))->assertJson(['supported' => false]);
    }

    public function test_invalid_commands_do_not_contact_the_camera(): void
    {
        Http::fake();
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        $this->postJson(route('live-wall.ptz.store', $camera), ['command' => 'arbitrary-soap'])->assertUnprocessable()->assertJsonValidationErrors('command');
        Http::assertNothingSent();
    }

    public function test_camera_errors_are_sanitized_and_transient_failures_are_retried(): void
    {
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response($this->envelope('<s:Fault><s:Reason><s:Text>private-camera-secret http://192.0.2.10</s:Text></s:Reason></s:Fault>'), 500)]);
        $this->getJson(route('live-wall.ptz.show', $camera))->assertServiceUnavailable()->assertDontSee('private-camera-secret')->assertDontSee('192.0.2.10');
        $this->getJson(route('live-wall.ptz.show', $camera))->assertServiceUnavailable();
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->fakePtz();
        $this->getJson(route('live-wall.ptz.show', $camera))->assertOk()->assertJson(['supported' => true]);
    }

    public function test_wall_defers_ptz_checks_and_includes_controls_only_for_onvif_candidates(): void
    {
        $this->mock(MediaMtxProcessService::class, function ($mock) {
            $mock->shouldReceive('ensureRunning')->andReturn(['installed' => true, 'running' => false, 'api_reachable' => false]);
        });
        Http::fake();
        $onvif = $this->camera();
        $rtsp = $this->camera(['supports_onvif' => false, 'name' => 'Fixed camera']);
        foreach ([$onvif, $rtsp] as $position => $camera) {
            LiveWall::query()->firstOrFail()->tiles()->create([
                'camera_id' => $camera->id, 'position' => $position + 1, 'orientation' => 'landscape',
                'column_span' => 1, 'row_span' => 1, 'is_enabled' => true,
            ]);
        }
        $this->actingAs(User::factory()->create())->get(route('live-wall.index'))->assertOk()
            ->assertSee('<meta name="csrf-token" content="', false)
            ->assertSee('data-ptz-toggle hidden', false)
            ->assertSee(route('live-wall.ptz.show', $onvif), false)
            ->assertDontSee(route('live-wall.ptz.show', $rtsp), false)
            ->assertSee('js/live-wall-ptz.js', false);
        Http::assertNothingSent();
    }
}
