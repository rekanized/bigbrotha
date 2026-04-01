<?php

namespace Tests\Unit;

use App\Models\Camera;
use App\Services\Onvif\OnvifCameraProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnvifCameraProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_camera_from_a_verified_onvif_probe(): void
    {
        $camera = app(OnvifCameraProvisioningService::class)->provisionFromProbe([
            'service_url' => 'http://192.168.1.67:2020/onvif/device_service',
            'manufacturer' => 'tp-link',
            'model' => 'Tapo C200',
            'serial_number' => '213ba426',
            'hardware_id' => '5.0',
            'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
            'response_time_ms' => 21,
            'http_status' => 200,
            'authenticated' => true,
            'response_excerpt' => 'GetDeviceInformationResponse',
        ], 'rekanized', 'secret');

        $this->assertSame('Tapo C200', $camera->name);
        $this->assertSame('192.168.1.67', $camera->local_ip);
        $this->assertSame(2020, $camera->onvif_port);
        $this->assertSame('/onvif/device_service', $camera->onvif_path);
        $this->assertSame('tp-link', $camera->manufacturer);
        $this->assertSame('Tapo C200', $camera->model);
        $this->assertSame('213ba426', $camera->serial_number);
        $this->assertSame('rekanized', $camera->username);
        $this->assertSame('secret', $camera->password);
        $this->assertFalse($camera->supports_rtsp);
        $this->assertTrue($camera->supports_onvif);
        $this->assertTrue($camera->is_enabled);
        $this->assertNotNull($camera->last_seen_at);
        $this->assertSame('http://192.168.1.67:2020/onvif/device_service', $camera->metadata['onvif']['service_url']);
        $this->assertSame('5.0', $camera->metadata['onvif']['hardware_id']);
        $this->assertDirectoryExists(storage_path('app/private/cameras/'.$camera->id));
    }

    public function test_it_updates_an_existing_camera_matched_by_serial_number(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Front room',
            'local_ip' => '192.168.1.67',
            'manufacturer' => 'Unknown',
            'model' => null,
            'serial_number' => '213ba426',
            'http_port' => 80,
            'onvif_port' => 80,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'supports_onvif' => true,
            'supports_rtsp' => true,
            'is_enabled' => true,
        ]);

        $updated = app(OnvifCameraProvisioningService::class)->provisionFromProbe([
            'service_url' => 'http://192.168.1.67:2020/onvif/device_service',
            'manufacturer' => 'tp-link',
            'model' => 'Tapo C200',
            'serial_number' => '213ba426',
            'hardware_id' => '5.0',
            'firmware_version' => '1.0.17',
            'response_time_ms' => 18,
            'http_status' => 200,
            'authenticated' => true,
            'response_excerpt' => 'GetDeviceInformationResponse',
        ], 'operator', 'secret');

        $this->assertTrue($camera->is($updated));
        $this->assertSame(1, Camera::query()->count());
        $this->assertSame('Front room', $updated->name);
        $this->assertSame(2020, $updated->onvif_port);
        $this->assertSame('tp-link', $updated->manufacturer);
        $this->assertSame('Tapo C200', $updated->model);
        $this->assertTrue($updated->supports_rtsp);
    }
}