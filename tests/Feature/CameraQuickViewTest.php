<?php

namespace Tests\Feature;

use App\Livewire\Auth\UnifiedLoginScreen;
use App\Models\Camera;
use App\Models\User;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class CameraQuickViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_signs_in_and_returns_to_the_requested_camera(): void
    {
        $camera = $this->camera();
        $url = route('cameras.live', $camera);
        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
        User::factory()->localOnly()->create([
            'email' => 'viewer@example.com', 'password' => 'password', 'local_auth_enabled' => true,
        ]);

        Livewire::test(UnifiedLoginScreen::class)
            ->set('email', 'viewer@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect($url);
    }

    public function test_quick_view_only_loads_the_selected_camera_without_a_wall_assignment(): void
    {
        $this->mockRelay();
        $camera = $this->camera();
        $other = $this->camera(['name' => 'Other camera', 'local_ip' => '192.0.2.45']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('cameras.live', $camera))
            ->assertOk()
            ->assertViewIs('live-wall.quick')
            ->assertSee('data-session-url="'.route('live-wall.session', $camera).'"', false)
            ->assertSee('autoplay muted playsinline controls', false)
            ->assertDontSee($other->name)
            ->assertDontSee(route('live-wall.session', $other), false)
            ->assertDontSee('Player session')
            ->assertDontSee($camera->local_ip)
            ->assertDontSee('fixture-secret')
            ->assertDontSee('data-access-token=');

        $this->assertSame(1, substr_count($response->getContent(), 'data-webrtc-player'));
    }

    public function test_unavailable_cameras_cannot_be_opened(): void
    {
        $this->mock(MediaMtxProcessService::class)->shouldNotReceive('ensureRunning');
        $this->actingAs(User::factory()->create());
        foreach ([['is_enabled' => false], ['supports_rtsp' => false]] as $attributes) {
            $this->get(route('cameras.live', $this->camera($attributes)))->assertNotFound();
        }
        $camera = $this->camera();
        $url = route('cameras.live', $camera);
        $camera->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_guest_cannot_request_the_underlying_media_session(): void
    {
        $this->getJson(route('live-wall.session', $this->camera()))->assertUnauthorized();
    }

    public function test_fleet_offers_a_stable_link_for_each_camera(): void
    {
        $camera = $this->camera();
        $other = $this->camera(['name' => 'Other camera', 'local_ip' => '192.0.2.45']);

        $this->actingAs(User::factory()->create())->get(route('camera-fleet.index'))
            ->assertOk()
            ->assertSee('data-copy-camera-link="'.route('cameras.live', $camera).'"', false)
            ->assertSee('data-copy-camera-link="'.route('cameras.live', $other).'"', false)
            ->assertSee('Copy camera link')
            ->assertSee('js/camera-links.js');
    }

    public function test_camera_page_uses_only_its_own_authenticated_manifest(): void
    {
        $this->mockRelay();
        $camera = $this->camera(['name' => 'Front "Door" & driveway']);
        $response = $this->actingAs(User::factory()->create())->get(route('cameras.live', $camera))
            ->assertOk()
            ->assertSee('href="'.route('cameras.manifest', $camera).'" crossorigin="use-credentials"', false)
            ->assertSee('name="apple-mobile-web-app-title" content="'.e($camera->name).'"', false)
            ->assertSee('Add to Home Screen')
            ->assertDontSee('site.webmanifest');

        $this->assertSame(1, substr_count($response->getContent(), 'rel="manifest"'));
        $this->get(route('camera-fleet.index'))->assertOk()->assertSee('site.webmanifest');
    }

    public function test_each_camera_shortcut_has_its_own_identity_and_authenticated_launch_url(): void
    {
        $this->actingAs(User::factory()->create());
        $identities = [];
        foreach (['Front entrance', 'Back garden'] as $name) {
            $camera = $this->camera(['name' => $name]);
            $path = route('cameras.live', $camera, false);
            $manifest = $this->get(route('cameras.manifest', $camera))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/manifest+json')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('name', $name)
                ->assertJsonPath('short_name', $name)
                ->assertJsonPath('id', $path)
                ->assertJsonPath('start_url', $path)
                ->assertJsonPath('scope', '/cameras/'.$camera->id.'/')
                ->assertJsonPath('display', 'standalone')
                ->assertDontSee('fixture-secret')
                ->assertDontSee($camera->local_ip)
                ->assertDontSee('access_token')
                ->json();

            foreach ($manifest['icons'] as $icon) {
                $this->assertFileExists(public_path(parse_url($icon['src'], PHP_URL_PATH)));
            }
            $identities[] = $manifest['id'];
            $camera->update(['name' => $name.' renamed']);
            $this->get(route('cameras.manifest', $camera))
                ->assertJsonPath('id', $path)->assertJsonPath('name', $name.' renamed');
        }
        $this->assertCount(2, array_unique($identities));
    }

    public function test_manifest_does_not_expose_camera_metadata_to_guests_or_replace_the_login_destination(): void
    {
        $camera = $this->camera();
        $this->get(route('cameras.live', $camera))->assertRedirect(route('login'));
        $this->get(route('cameras.manifest', $camera), ['Accept' => 'application/manifest+json'])
            ->assertUnauthorized()
            ->assertDontSee($camera->name)
            ->assertSessionHas('url.intended', route('cameras.live', $camera));
    }

    public function test_unavailable_cameras_do_not_offer_installation_metadata(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([['is_enabled' => false], ['supports_rtsp' => false]] as $attributes) {
            $this->get(route('cameras.manifest', $this->camera($attributes)))->assertNotFound();
        }
        $camera = $this->camera();
        $url = route('cameras.manifest', $camera);
        $camera->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_manifest_urls_preserve_the_application_base_path(): void
    {
        config()->set('app.url', 'https://camera.example.test/monitor');
        $camera = $this->camera();
        $this->actingAs(User::factory()->create());
        URL::forceRootUrl('https://camera.example.test/monitor');
        URL::forceScheme('https');
        $this->get('https://camera.example.test/cameras/'.$camera->id.'/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('id', '/monitor/cameras/'.$camera->id.'/live')
            ->assertJsonPath('start_url', '/monitor/cameras/'.$camera->id.'/live')
            ->assertJsonPath('scope', '/monitor/cameras/'.$camera->id.'/')
            ->assertJsonPath('icons.0.src', 'https://camera.example.test/monitor/img/bigbrotha-icon-192.png');
    }

    public function test_missing_stream_and_missing_relay_have_clear_empty_states(): void
    {
        $this->mockRelay();
        $camera = $this->camera(['rtsp_path' => null]);
        $this->actingAs(User::factory()->create())->get(route('cameras.live', $camera))
            ->assertOk()->assertSee('No live RTSP stream is ready.')->assertDontSee('data-webrtc-player');

        $this->mockRelay(installed: false);
        $this->get(route('cameras.live', $camera))
            ->assertOk()->assertSee('Media relay binary is missing')->assertDontSee('data-webrtc-player');
    }

    private function mockRelay(bool $installed = true): void
    {
        $this->mock(MediaMtxProcessService::class)
            ->shouldReceive('ensureRunning')->andReturn(['installed' => $installed, 'running' => false]);
    }

    private function camera(array $attributes = []): Camera
    {
        return Camera::query()->create(array_merge([
            'name' => 'Front entrance', 'local_ip' => '192.0.2.44', 'rtsp_port' => 554,
            'rtsp_path' => '/live', 'supports_rtsp' => true, 'is_enabled' => true,
            'username' => 'operator', 'password' => 'fixture-secret',
        ], $attributes));
    }
}
