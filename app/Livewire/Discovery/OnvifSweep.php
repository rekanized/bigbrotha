<?php

namespace App\Livewire\Discovery;

use App\Models\Camera;
use App\Services\Discovery\OnvifWsDiscoveryService;
use App\Services\Onvif\OnvifCameraProvisioningService;
use App\Services\Onvif\OnvifDeviceProbeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Throwable;

class OnvifSweep extends Component
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $devices = [];

    public ?string $error = null;

    public ?string $lastSweepAt = null;

    public int $timeoutMs = 3000;

    public string $manualServiceUrl = '';

    public string $manualUsername = '';

    public string $manualPassword = '';

    /**
     * @var array<string, int|string|null>
     */
    public array $manualProbeResponse = [];

    public ?string $manualProbeError = null;

    public ?string $manualProbeLastCheckedAt = null;

    public ?string $manualProbeSavedMessage = null;

    public function discover(): void
    {
        $this->error = null;

        try {
            $discoveryService = app(OnvifWsDiscoveryService::class);
            $this->devices = $discoveryService->discover($this->timeoutMs);
            $this->lastSweepAt = now()->utc()->format('Y-m-d H:i:s').' UTC';
        } catch (Throwable $exception) {
            report($exception);

            $this->devices = [];
            $this->error = 'The WS-Discovery sweep could not complete. Check multicast reachability, firewall rules, and socket access on this host.';
        }
    }

    public function probeDeviceService(): void
    {
        $this->manualProbeError = null;
        $this->manualProbeSavedMessage = null;
        $this->manualProbeResponse = [];
        $this->resetErrorBag();

        $validated = Validator::make([
            'manualServiceUrl' => $this->manualServiceUrl,
            'manualUsername' => $this->manualUsername,
            'manualPassword' => $this->manualPassword,
        ], [
            'manualServiceUrl' => ['required', 'url:http,https', 'max:2048'],
            'manualUsername' => ['nullable', 'string', 'max:255', 'required_with:manualPassword'],
            'manualPassword' => ['nullable', 'string', 'max:255', 'required_with:manualUsername'],
        ])->validate();

        try {
            $probeService = app(OnvifDeviceProbeService::class);
            $this->manualProbeResponse = $probeService->probe(
                $validated['manualServiceUrl'],
                $validated['manualUsername'] !== '' ? $validated['manualUsername'] : null,
                $validated['manualPassword'] !== '' ? $validated['manualPassword'] : null,
            );
            $this->manualProbeLastCheckedAt = now()->utc()->format('Y-m-d H:i:s').' UTC';
            $this->syncManualProbeIntoDiscoveryResults();
        } catch (Throwable $exception) {
            report($exception);

            $this->manualProbeError = $exception->getMessage();
        }
    }

    public function saveManualProbeToFleet(): void
    {
        $this->manualProbeError = null;
        $this->manualProbeSavedMessage = null;

        if ($this->manualProbeResponse === []) {
            $this->manualProbeError = 'Run a successful manual ONVIF probe before saving the camera into the fleet.';

            return;
        }

        try {
            $camera = app(OnvifCameraProvisioningService::class)->provisionFromProbe(
                $this->manualProbeResponse,
                $this->manualUsername !== '' ? $this->manualUsername : null,
                $this->manualPassword !== '' ? $this->manualPassword : null,
            );

            $this->manualProbeSavedMessage = $camera->wasRecentlyCreated
                ? 'Saved '.$camera->name.' into the camera fleet.'
                : 'Updated '.$camera->name.' in the camera fleet.';

            $this->syncManualProbeIntoDiscoveryResults($camera);
        } catch (Throwable $exception) {
            report($exception);

            $this->manualProbeError = $exception->getMessage();
        }
    }

    private function syncManualProbeIntoDiscoveryResults(?Camera $camera = null): void
    {
        if ($this->manualProbeResponse === []) {
            return;
        }

        $serviceUrl = (string) ($this->manualProbeResponse['service_url'] ?? '');
        $parts = parse_url($serviceUrl);

        if (!is_array($parts) || !isset($parts['host'])) {
            return;
        }

        $remoteIp = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $device = [
            'name' => $camera?->name
                ?? ($this->manualProbeResponse['model'] ?: $this->manualProbeResponse['manufacturer'] ?: $remoteIp),
            'hardware' => $this->manualProbeResponse['hardware_id']
                ?? trim((string) (($this->manualProbeResponse['manufacturer'] ?? '').' '.($this->manualProbeResponse['model'] ?? '')))
                ?: null,
            'location' => 'Verified through direct ONVIF request',
            'remote_ip' => $remoteIp,
            'remote_port' => $port,
            'endpoint_reference' => $this->manualProbeResponse['serial_number'] ?: $serviceUrl,
            'service_url' => $serviceUrl,
            'hostname' => filter_var($remoteIp, FILTER_VALIDATE_IP) ? null : $remoteIp,
            'scheme' => $parts['scheme'] ?? 'http',
            'port' => $port,
            'path' => $parts['path'] ?? '/onvif/device_service',
            'types' => ['ONVIF', 'Verified'],
            'scopes' => [],
            'xaddrs' => [$serviceUrl],
            'metadata_version' => null,
            'manufacturer' => $this->manualProbeResponse['manufacturer'] ?? null,
            'model' => $this->manualProbeResponse['model'] ?? null,
            'serial_number' => $this->manualProbeResponse['serial_number'] ?? null,
            'firmware_version' => $this->manualProbeResponse['firmware_version'] ?? null,
            'source' => 'manual-probe',
            'saved_to_fleet' => $camera !== null,
        ];

        $key = (string) $device['endpoint_reference'];
        $indexedDevices = [];

        foreach ($this->devices as $existingDevice) {
            $existingKey = (string) ($existingDevice['endpoint_reference'] ?? ($existingDevice['service_url'] ?? $existingDevice['remote_ip'] ?? Str::uuid()->toString()));
            $indexedDevices[$existingKey] = $existingDevice;
        }

        $indexedDevices[$key] = $device;
        $this->devices = array_values($indexedDevices);
    }

    public function render(): View
    {
        return view('livewire.discovery.onvif-sweep', [
            'deviceCount' => count($this->devices),
            'timeoutOptions' => [1500, 3000, 5000],
        ]);
    }
}