<?php

namespace Database\Seeders;

use App\Models\Camera;
use Illuminate\Database\Seeder;

class CameraFleetSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->shouldSeedFleet()) {
            return;
        }

        foreach ($this->cameraSeedData() as $camera) {
            Camera::query()->firstOrCreate(
                ['local_ip' => $camera['local_ip']],
                $camera,
            );
        }
    }

    private function shouldSeedFleet(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return filter_var((string) env('SEED_CAMERA_FLEET', 'false'), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cameraSeedData(): array
    {
        return [
            [
                'name' => 'Front Gate',
                'local_ip' => '192.168.1.210',
                'hostname' => 'front-gate.local',
                'manufacturer' => 'Axis',
                'model' => 'P3245-LVE',
                'serial_number' => 'BG-AXIS-210',
                'mac_address' => '00:11:22:33:44:10',
                'http_port' => 80,
                'onvif_port' => 80,
                'onvif_path' => '/onvif/device_service',
                'rtsp_port' => 554,
                'rtsp_path' => '/axis-media/media.amp',
                'recording_rtsp_path' => '/axis-media/media.amp',
                'rtsp_transport' => 'tcp',
                'username' => 'operator',
                'password' => 'secret',
                'supports_onvif' => true,
                'supports_rtsp' => true,
                'is_enabled' => true,
                'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'recording_retention_days' => 14,
                'motion_sensitivity' => 35,
                'recording_motion_trigger_pixels' => 18,
                'metadata' => [
                    'rtsp_profiles' => [
                        [
                            'name' => 'Primary stream',
                            'path' => '/axis-media/media.amp',
                            'uri' => 'rtsp://front-gate.local:554/axis-media/media.amp',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Warehouse Bay',
                'local_ip' => '192.168.1.211',
                'hostname' => 'warehouse-bay.local',
                'manufacturer' => 'Reolink',
                'model' => 'RLC-810A',
                'serial_number' => 'BG-REOLINK-211',
                'mac_address' => '00:11:22:33:44:11',
                'http_port' => 80,
                'onvif_port' => 8000,
                'onvif_path' => '/onvif/device_service',
                'rtsp_port' => 554,
                'rtsp_path' => '/h264Preview_01_main',
                'recording_rtsp_path' => '/h264Preview_01_main',
                'rtsp_transport' => 'tcp',
                'username' => 'operator',
                'password' => 'secret',
                'supports_onvif' => false,
                'supports_rtsp' => true,
                'is_enabled' => true,
                'recording_mode' => Camera::RECORDING_MODE_MOTION,
                'recording_retention_days' => 7,
                'motion_sensitivity' => 40,
                'recording_motion_trigger_pixels' => 24,
                'metadata' => [
                    'rtsp_profiles' => [
                        [
                            'name' => 'Main stream',
                            'path' => '/h264Preview_01_main',
                            'uri' => 'rtsp://warehouse-bay.local:554/h264Preview_01_main',
                        ],
                    ],
                ],
            ],
        ];
    }
}