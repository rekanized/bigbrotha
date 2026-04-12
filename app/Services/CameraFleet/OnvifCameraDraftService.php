<?php

namespace App\Services\CameraFleet;

use App\Models\Camera;
use App\Services\Onvif\OnvifDeviceProbeService;
use App\Services\Onvif\OnvifRtspStreamService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OnvifCameraDraftService
{
    public function __construct(
        private OnvifDeviceProbeService $deviceProbeService,
        private OnvifRtspStreamService $rtspStreamService,
    ) {
    }

    /**
     * @return array{
     *     form: array<string, mixed>,
     *     metadata: array<string, mixed>,
     *     probe_response: array<string, int|string|bool|null>,
     *     rtsp_profiles: array<int, array<string, string|null>>,
     *     warnings: array<int, string>
     * }
     */
    public function probe(string $serviceUrl, ?string $username = null, ?string $password = null, int $timeoutSeconds = 5): array
    {
        $probeResponse = $this->deviceProbeService->probe($serviceUrl, $username, $password, $timeoutSeconds);
        $serviceParts = parse_url($serviceUrl);

        if (!is_array($serviceParts) || !isset($serviceParts['host'])) {
            throw new RuntimeException('The ONVIF service URL could not be parsed into a reusable camera draft.');
        }

        $host = (string) $serviceParts['host'];
        $scheme = (string) ($serviceParts['scheme'] ?? 'http');
        $onvifPort = (int) ($serviceParts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $onvifPath = Str::start((string) ($serviceParts['path'] ?? '/onvif/device_service'), '/');
        $warnings = [];
        $networkDetails = [
            'ipv4_address' => null,
            'mac_address' => null,
        ];

        try {
            $networkDetails = $this->deviceProbeService->fetchPrimaryNetworkDetails($serviceUrl, $username, $password, $timeoutSeconds);
        } catch (Throwable $exception) {
            report($exception);

            $warnings[] = 'The camera answered the ONVIF probe, but its network interface details were not returned: '.$exception->getMessage();
        }

        $resolvedIp = $this->resolveLocalIp($host, $networkDetails['ipv4_address'] ?? null);
        $hostname = filter_var($host, FILTER_VALIDATE_IP) === false ? $host : null;
        $rtspProfiles = [];
        $mediaServiceUrl = null;

        try {
            $streamDiscovery = $this->rtspStreamService->discover($this->buildTemporaryCamera(
                $resolvedIp,
                $hostname,
                $onvifPort,
                $onvifPath,
                $username,
                $password,
            ), $timeoutSeconds);

            $mediaServiceUrl = $this->stringOrNull($streamDiscovery['media_service_url'] ?? null);
            $rtspProfiles = array_values(array_filter(
                $streamDiscovery['profiles'] ?? [],
                static fn (mixed $profile): bool => is_array($profile),
            ));
        } catch (Throwable $exception) {
            report($exception);

            $warnings[] = 'The camera answered the ONVIF probe, but RTSP stream URLs could not be loaded yet: '.$exception->getMessage();
        }

        $primaryStreamUri = $this->stringOrNull(Arr::get($rtspProfiles, '0.uri'));

        return [
            'form' => [
                'name' => $this->resolveCameraName($probeResponse, $resolvedIp),
                'local_ip' => $resolvedIp,
                'hostname' => $hostname ?? '',
                'manufacturer' => $this->stringOrNull(Arr::get($probeResponse, 'manufacturer')) ?? '',
                'model' => $this->stringOrNull(Arr::get($probeResponse, 'model')) ?? '',
                'serial_number' => $this->stringOrNull(Arr::get($probeResponse, 'serial_number')) ?? '',
                'mac_address' => $networkDetails['mac_address'] ?? '',
                'http_port' => $onvifPort,
                'onvif_port' => $onvifPort,
                'onvif_path' => $onvifPath,
                'rtsp_port' => $this->parseRtspPort($primaryStreamUri),
                'rtsp_path' => $this->parseRtspPath($primaryStreamUri) ?? '',
                'rtsp_transport' => 'tcp',
                'username' => $username ?? '',
                'password' => $password ?? '',
                'supports_onvif' => true,
                'supports_rtsp' => $rtspProfiles !== [],
                'is_enabled' => true,
            ],
            'metadata' => [
                'onvif' => array_filter([
                    'service_url' => $serviceUrl,
                    'media_service_url' => $mediaServiceUrl,
                    'response_time_ms' => Arr::get($probeResponse, 'response_time_ms'),
                    'firmware_version' => $this->stringOrNull(Arr::get($probeResponse, 'firmware_version')),
                    'hardware_id' => $this->stringOrNull(Arr::get($probeResponse, 'hardware_id')),
                    'http_status' => Arr::get($probeResponse, 'http_status'),
                    'authenticated' => (bool) Arr::get($probeResponse, 'authenticated', false),
                    'response_excerpt' => $this->stringOrNull(Arr::get($probeResponse, 'response_excerpt')),
                    'ipv4_address' => $networkDetails['ipv4_address'] ?? null,
                    'mac_address' => $networkDetails['mac_address'] ?? null,
                    'last_verified_at' => now()->utc()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                'rtsp_profiles' => $rtspProfiles,
            ],
            'probe_response' => array_merge($probeResponse, [
                'ipv4_address' => $networkDetails['ipv4_address'] ?? null,
                'mac_address' => $networkDetails['mac_address'] ?? null,
                'media_service_url' => $mediaServiceUrl,
                'primary_rtsp_uri' => $primaryStreamUri,
                'rtsp_profile_count' => count($rtspProfiles),
            ]),
            'rtsp_profiles' => $rtspProfiles,
            'warnings' => $warnings,
        ];
    }

    private function buildTemporaryCamera(
        string $localIp,
        ?string $hostname,
        int $onvifPort,
        string $onvifPath,
        ?string $username,
        ?string $password,
    ): Camera {
        $camera = new Camera();
        $camera->forceFill([
            'name' => 'Probe draft',
            'local_ip' => $localIp,
            'hostname' => $hostname,
            'http_port' => $onvifPort,
            'onvif_port' => $onvifPort,
            'onvif_path' => $onvifPath,
            'rtsp_port' => 554,
            'rtsp_transport' => 'tcp',
            'username' => $username,
            'password' => $password,
            'supports_onvif' => true,
            'supports_rtsp' => false,
            'is_enabled' => true,
        ]);

        return $camera;
    }

    /**
     * @param  array<string, int|string|null>  $probeResponse
     */
    private function resolveCameraName(array $probeResponse, string $resolvedIp): string
    {
        $model = $this->stringOrNull(Arr::get($probeResponse, 'model'));
        $manufacturer = $this->stringOrNull(Arr::get($probeResponse, 'manufacturer'));

        if ($model !== null) {
            return $model;
        }

        if ($manufacturer !== null) {
            return $manufacturer.' '.$resolvedIp;
        }

        return 'Camera '.$resolvedIp;
    }

    private function resolveLocalIp(string $host, ?string $networkIpv4): string
    {
        if (is_string($networkIpv4) && filter_var($networkIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $networkIpv4;
        }

        return $host;
    }

    private function parseRtspPort(?string $uri): int
    {
        if (!is_string($uri) || $uri === '') {
            return 554;
        }

        $parts = parse_url($uri);

        if (!is_array($parts)) {
            return 554;
        }

        return (int) ($parts['port'] ?? 554);
    }

    private function parseRtspPath(?string $uri): ?string
    {
        if (!is_string($uri) || $uri === '') {
            return null;
        }

        $parts = parse_url($uri);

        if (!is_array($parts)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path === '' && $query === '' ? null : $path.$query;
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