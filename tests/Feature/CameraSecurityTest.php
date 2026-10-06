<?php

namespace Tests\Feature;

use App\Livewire\CameraFleet\Manager;
use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use App\Services\CameraRecordingService;
use App\Services\Onvif\OnvifDeviceProbeService;
use App\Services\Onvif\OnvifPtzSoapClient;
use App\Services\Onvif\OnvifRtspStreamService;
use App\Support\CameraUrl;
use App\Support\OnvifXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CameraSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function serverProperties(): array
    {
        return [['rtspProfiles'], ['draftMetadata'], ['probeResponse']];
    }

    #[DataProvider('serverProperties')]
    public function test_browser_cannot_replace_server_discovered_metadata(string $property): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Manager::class)->set($property, ['uri' => 'file:///etc/passwd']);
    }

    public function test_browser_cannot_replace_nested_stream_urls(): void
    {
        $camera = $this->camera(['metadata' => ['rtsp_profiles' => [['uri' => 'rtsp://192.0.2.44/live']]]]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Manager::class)->call('editCamera', $camera->id)->set('rtspProfiles.0.uri', 'file:///etc/passwd');
    }

    public static function unsafeStreams(): array
    {
        return array_map(fn ($url) => [$url], [
            'file:///etc/passwd', 'http://169.254.169.254/latest/meta-data/',
            'concat:/etc/passwd', 'pipe:0', '/etc/passwd', 'rtsp://',
            "rtsp://192.0.2.44/live\n", 'rtsp://camera\\@attacker.invalid/live',
        ]);
    }

    #[DataProvider('unsafeStreams')]
    public function test_non_rtsp_inputs_cannot_reach_media_processes(string $url): void
    {
        $this->expectException(RuntimeException::class);
        CameraUrl::assertRtsp($url);
    }

    public function test_saved_unsafe_profile_is_rejected_by_live_and_recording_services(): void
    {
        $camera = $this->camera(['metadata' => ['rtsp_profiles' => [['uri' => 'file:///etc/passwd']]]]);
        foreach ([CameraLiveStreamService::class, CameraRecordingService::class] as $service) {
            try {
                $service === CameraLiveStreamService::class
                    ? app($service)->selectedWebRtcSource($camera, 0)
                    : app($service)->resolveRecordingSource($camera, 0);
                $this->fail('Unsafe stored stream was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('invalid protocol', $e->getMessage());
            }
        }
    }

    public function test_media_discovery_cannot_send_credentials_to_another_host(): void
    {
        Http::fake(['*' => Http::response('<Envelope><Capabilities><Media><XAddr>http://attacker.invalid/collect</XAddr></Media></Capabilities></Envelope>')]);
        try {
            app(OnvifRtspStreamService::class)->discover($this->camera());
            $this->fail('Cross-host media address was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside its configured host', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_ptz_cannot_send_credentials_to_another_host(): void
    {
        Http::fake();
        try {
            app(OnvifPtzSoapClient::class)->request($this->camera(), 'http://attacker.invalid/collect', 'urn:test', 'GetProfiles', '');
            $this->fail('Cross-host PTZ address was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside its configured host', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_discovered_stream_cannot_redirect_credentials_to_another_host(): void
    {
        $this->expectException(RuntimeException::class);
        CameraUrl::assertRtspFromDevice('rtsp://attacker.invalid/live', 'http://192.0.2.44/device');
    }

    public function test_https_discovery_cannot_downgrade_to_http(): void
    {
        $this->expectException(RuntimeException::class);
        CameraUrl::assertSameHost('http://camera.example/media', 'https://camera.example/device');
    }

    public function test_camera_may_advertise_another_service_port_on_its_own_host(): void
    {
        CameraUrl::assertSameHost('http://192.0.2.44:2020/media', 'http://192.0.2.44/device');
        CameraUrl::assertRtsp('rtsps://[2001:db8::1]:322/live');
        $this->addToAssertionCount(1);
    }

    public function test_saved_https_onvif_endpoint_keeps_its_secure_scheme(): void
    {
        $camera = $this->camera([
            'onvif_port' => 443,
            'metadata' => ['onvif' => ['service_url' => 'https://192.0.2.44/onvif/device_service']],
        ]);
        $this->assertSame('https://192.0.2.44:443/onvif/device_service', $camera->onvifEndpoint());
    }

    public static function unsafeXml(): array
    {
        $dtd = '<!DOCTYPE Envelope [<!ENTITY secret SYSTEM "file:///etc/passwd">]><Envelope>&secret;</Envelope>';

        return [[$dtd], [mb_convert_encoding($dtd, 'UTF-16', 'UTF-8')], [str_repeat('x', 1024 * 1024 + 1)]];
    }

    #[DataProvider('unsafeXml')]
    public function test_unsafe_xml_is_rejected_before_parsing(string $payload): void
    {
        $this->expectException(RuntimeException::class);
        OnvifXml::assertSafe($payload);
    }

    public function test_device_probe_rejects_dtd_responses(): void
    {
        Http::fake(['*' => Http::response('<!DOCTYPE Envelope [<!ENTITY value "camera">]><Envelope>&value;</Envelope>')]);
        $this->expectException(RuntimeException::class);
        app(OnvifDeviceProbeService::class)->probe('http://192.0.2.44/device');
    }

    public function test_onvif_probe_verifies_certificates_and_disables_redirects(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response('<Envelope><Manufacturer>Camera</Manufacturer></Envelope>');
        });
        app(OnvifDeviceProbeService::class)->probe('https://camera.example/device');
        Http::assertSentCount(1);
    }

    public function test_media_discovery_verifies_certificates_and_disables_redirects(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response('<Envelope/>');
        });
        app(OnvifRtspStreamService::class)->discover($this->camera());
        Http::assertSentCount(2);
    }

    private function camera(array $attributes = []): Camera
    {
        return Camera::query()->create(array_merge([
            'name' => 'Security camera', 'local_ip' => '192.0.2.44',
            'supports_onvif' => true, 'supports_rtsp' => true, 'is_enabled' => true,
            'username' => 'operator', 'password' => 'fixture-secret',
        ], $attributes));
    }
}
