<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use App\Services\CameraStorageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class OnvifCameraProvisioningService
{
    /**
     * @param  array<string, int|string|null>  $probeResponse
     */
    public function provisionFromProbe(array $probeResponse, ?string $username = null, ?string $password = null): Camera
    {
        $serviceUrl = Arr::get($probeResponse, 'service_url');

        if (!is_string($serviceUrl) || $serviceUrl === '') {
            throw new RuntimeException('A successful ONVIF probe with a service URL is required before saving a camera.');
        }

        $parts = parse_url($serviceUrl);

        if (!is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException('The ONVIF service URL could not be parsed for camera provisioning.');
        }

        $host = (string) $parts['host'];
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $onvifPort = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $onvifPath = Str::start((string) ($parts['path'] ?? '/onvif/device_service'), '/');
        $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $serialNumber = $this->stringOrNull(Arr::get($probeResponse, 'serial_number'));

        $camera = $this->findExistingCamera($serialNumber, $host, $onvifPort, $onvifPath) ?? new Camera();
        $metadata = is_array($camera->metadata) ? $camera->metadata : [];
        $manualProbeMetadata = [
            'service_url' => $serviceUrl,
            'response_time_ms' => Arr::get($probeResponse, 'response_time_ms'),
            'firmware_version' => $this->stringOrNull(Arr::get($probeResponse, 'firmware_version')),
            'hardware_id' => $this->stringOrNull(Arr::get($probeResponse, 'hardware_id')),
            'http_status' => Arr::get($probeResponse, 'http_status'),
            'authenticated' => (bool) Arr::get($probeResponse, 'authenticated', false),
            'response_excerpt' => $this->stringOrNull(Arr::get($probeResponse, 'response_excerpt')),
            'last_verified_at' => now()->utc()->toIso8601String(),
        ];

        $camera->fill([
            'name' => $camera->name ?: $this->resolveCameraName($probeResponse, $host),
            'local_ip' => $isIpAddress ? $host : ($camera->local_ip ?: $host),
            'hostname' => $isIpAddress ? ($camera->hostname ?: null) : $host,
            'manufacturer' => $this->stringOrNull(Arr::get($probeResponse, 'manufacturer')),
            'model' => $this->stringOrNull(Arr::get($probeResponse, 'model')),
            'serial_number' => $serialNumber,
            'http_port' => $onvifPort,
            'onvif_port' => $onvifPort,
            'onvif_path' => $onvifPath,
            'username' => $username !== '' ? $username : null,
            'password' => $password !== '' ? $password : null,
            'supports_onvif' => true,
            'supports_rtsp' => $camera->exists ? $camera->supports_rtsp : false,
            'is_enabled' => true,
            'last_seen_at' => now(),
            'metadata' => array_merge($metadata, [
                'onvif' => array_filter($manualProbeMetadata, static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]),
        ]);

        $camera->save();
        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        return $camera->refresh();
    }

    private function findExistingCamera(?string $serialNumber, string $host, int $onvifPort, string $onvifPath): ?Camera
    {
        if ($serialNumber !== null) {
            $camera = Camera::query()->where('serial_number', $serialNumber)->first();

            if ($camera !== null) {
                return $camera;
            }
        }

        return Camera::query()
            ->where('local_ip', $host)
            ->where('onvif_port', $onvifPort)
            ->where('onvif_path', $onvifPath)
            ->first();
    }

    /**
     * @param  array<string, int|string|null>  $probeResponse
     */
    private function resolveCameraName(array $probeResponse, string $host): string
    {
        $model = $this->stringOrNull(Arr::get($probeResponse, 'model'));
        $manufacturer = $this->stringOrNull(Arr::get($probeResponse, 'manufacturer'));

        if ($model !== null) {
            return $model;
        }

        if ($manufacturer !== null) {
            return $manufacturer.' '.$host;
        }

        return 'Camera '.$host;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}