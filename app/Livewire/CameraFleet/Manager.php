<?php

namespace App\Livewire\CameraFleet;

use App\Models\Camera;
use App\Services\ApplicationSettingsService;
use App\Services\CameraFleet\OnvifCameraDraftService;
use App\Services\CameraStorageService;
use App\Services\Onvif\OnvifRtspStreamService;
use App\Services\Onvif\RtspStreamDiagnosticsService;
use App\Services\RecordingMotionMaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

class Manager extends Component
{
    public bool $isEditorModalOpen = false;

    public ?int $editingCameraId = null;

    public ?int $pendingDeleteCameraId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * @var array<int, array<string, string|null>>
     */
    public array $rtspProfiles = [];

    public string $probeEndpointUrl = '';

    /**
     * @var array<string, mixed>
     */
    public array $probeResponse = [];

    /**
     * @var array<string, mixed>
     */
    public array $draftMetadata = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public ?string $probeStatusMessage = null;

    public ?string $probeErrorMessage = null;

    public ?string $probeWarningMessage = null;

    public ?string $probeLastCheckedAt = null;

    public ?string $rtspStatusMessage = null;

    public ?string $rtspErrorMessage = null;

    public function mount(): void
    {
        $this->resetEditorState();
    }

    public function newCamera(): void
    {
        $this->resetEditorState();
        $this->isEditorModalOpen = true;
    }

    public function closeEditorModal(): void
    {
        $this->isEditorModalOpen = false;
        $this->resetErrorBag();
    }

    public function enableRtspOnlyMode(): void
    {
        if ($this->editingCameraId !== null) {
            return;
        }

        $this->form['supports_onvif'] = false;
        $this->form['supports_rtsp'] = true;
        $this->probeEndpointUrl = '';
        $this->probeResponse = [];
        $this->draftMetadata = [];
        $this->rtspProfiles = [];
        $this->probeLastCheckedAt = null;
        $this->probeErrorMessage = null;
        $this->probeWarningMessage = null;
        $this->probeStatusMessage = 'RTSP-only mode enabled. Configure the stream settings below and save the camera when ready.';
        $this->resetValidation('probeEndpointUrl');
    }

    public function enableOnvifProbeMode(): void
    {
        if ($this->editingCameraId !== null) {
            return;
        }

        $this->form['supports_onvif'] = true;
        $this->probeStatusMessage = null;
        $this->probeErrorMessage = null;
        $this->probeWarningMessage = null;
        $this->resetValidation('probeEndpointUrl');
    }

    public function probeEndpoint(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->probeStatusMessage = null;
        $this->probeErrorMessage = null;
        $this->probeWarningMessage = null;
        $this->rtspStatusMessage = null;
        $this->rtspErrorMessage = null;
        $this->probeResponse = [];
        $this->draftMetadata = [];
        $this->rtspProfiles = [];
        $this->resetErrorBag();

        $validated = Validator::make([
            'probeEndpointUrl' => $this->probeEndpointUrl,
            'form' => $this->form,
        ], [
            'probeEndpointUrl' => ['required', 'url:http,https', 'max:2048'],
            'form.username' => ['nullable', 'string', 'max:255', 'required_with:form.password'],
            'form.password' => ['nullable', 'string', 'max:255', 'required_with:form.username'],
        ])->validate();

        try {
            $draft = app(OnvifCameraDraftService::class)->probe(
                $validated['probeEndpointUrl'],
                $this->nullableString($validated['form']['username'] ?? null),
                $this->nullableString($validated['form']['password'] ?? null),
            );

            $this->form = array_merge($this->defaultForm(), $draft['form']);
            $this->probeResponse = $draft['probe_response'];
            $this->draftMetadata = $draft['metadata'];
            $this->rtspProfiles = $draft['rtsp_profiles'];
            $this->probeLastCheckedAt = app(ApplicationSettingsService::class)->formatDateTime(now()->utc()) ?? now()->format('Y-m-d H:i:s T');
            $this->probeStatusMessage = 'Endpoint verified. Review the hydrated draft and save the camera into the fleet when it looks correct.';
            $this->probeWarningMessage = $draft['warnings'] !== [] ? implode(' ', $draft['warnings']) : null;
        } catch (Throwable $exception) {
            report($exception);

            $this->probeResponse = [];
            $this->draftMetadata = [];
            $this->rtspProfiles = [];
            $this->probeLastCheckedAt = null;
            $this->probeErrorMessage = $exception->getMessage();
        }
    }

    public function editCamera(int $cameraId): void
    {
        $camera = Camera::query()->findOrFail($cameraId);

        $this->pendingDeleteCameraId = null;
        $this->editingCameraId = $camera->id;
        $this->form = [
            'name' => $camera->name,
            'local_ip' => $camera->local_ip,
            'hostname' => $camera->hostname ?? '',
            'manufacturer' => $camera->manufacturer ?? '',
            'model' => $camera->model ?? '',
            'serial_number' => $camera->serial_number ?? '',
            'mac_address' => $camera->mac_address ?? '',
            'http_port' => $camera->http_port,
            'onvif_port' => $camera->onvif_port,
            'onvif_path' => $camera->onvif_path,
            'rtsp_port' => $camera->rtsp_port,
            'rtsp_path' => $camera->rtsp_path ?? '',
            'rtsp_transport' => $camera->rtsp_transport,
            'username' => $camera->username ?? '',
            'password' => '',
            'supports_onvif' => $camera->supports_onvif,
            'supports_rtsp' => $camera->supports_rtsp,
            'is_enabled' => $camera->is_enabled,
            'recording_mode' => $camera->recording_mode,
            'recording_profile_index' => $camera->recording_profile_index,
            'recording_retention_days' => $camera->recording_retention_days,
            'motion_sensitivity' => $camera->motion_sensitivity,
            'recording_motion_pre_roll_seconds' => $camera->motionPreRollSeconds(),
            'recording_motion_post_trigger_seconds' => $camera->motionPostTriggerSeconds(),
            'recording_motion_mask' => $camera->recordingMotionMask(),
            'recording_motion_x' => $camera->recordingMotionArea()['x'],
            'recording_motion_y' => $camera->recordingMotionArea()['y'],
            'recording_motion_width' => $camera->recordingMotionArea()['width'],
            'recording_motion_height' => $camera->recordingMotionArea()['height'],
        ];
        $this->probeEndpointUrl = $camera->onvifEndpoint() ?? '';
        $this->probeResponse = [];
        $this->draftMetadata = is_array($camera->metadata) ? $camera->metadata : [];
        $this->rtspProfiles = $camera->rtspProfiles();
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->probeStatusMessage = null;
        $this->probeErrorMessage = null;
        $this->probeWarningMessage = null;
        $this->probeLastCheckedAt = null;
        $this->rtspStatusMessage = null;
        $this->rtspErrorMessage = null;
        $this->isEditorModalOpen = true;
        $this->resetErrorBag();
    }

    public function saveCamera(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->resetErrorBag();

        $validator = Validator::make([
            'form' => $this->form,
        ], [
            'form.name' => ['required', 'string', 'max:255'],
            'form.local_ip' => ['required', 'string', 'max:45'],
            'form.hostname' => ['nullable', 'string', 'max:255'],
            'form.manufacturer' => ['nullable', 'string', 'max:255'],
            'form.model' => ['nullable', 'string', 'max:255'],
            'form.serial_number' => ['nullable', 'string', 'max:255'],
            'form.mac_address' => ['nullable', 'mac_address'],
            'form.http_port' => ['required', 'integer', 'between:1,65535'],
            'form.onvif_port' => [Rule::requiredIf(fn (): bool => (bool) ($this->form['supports_onvif'] ?? false)), 'nullable', 'integer', 'between:1,65535'],
            'form.onvif_path' => [Rule::requiredIf(fn (): bool => (bool) ($this->form['supports_onvif'] ?? false)), 'nullable', 'string', 'max:255'],
            'form.rtsp_port' => ['required', 'integer', 'between:1,65535'],
            'form.rtsp_path' => ['nullable', 'string', 'max:255'],
            'form.rtsp_transport' => ['required', 'in:tcp,udp'],
            'form.username' => ['nullable', 'string', 'max:255'],
            'form.password' => ['nullable', 'string', 'max:255'],
            'form.supports_onvif' => ['boolean'],
            'form.supports_rtsp' => ['boolean'],
            'form.is_enabled' => ['boolean'],
            'form.recording_mode' => ['required', Rule::in(Camera::RECORDING_MODES)],
            'form.recording_profile_index' => ['nullable', 'integer', 'min:0'],
            'form.recording_retention_days' => ['required', 'integer', 'between:1,365'],
            'form.motion_sensitivity' => ['required', 'integer', 'between:1,100'],
            'form.recording_motion_pre_roll_seconds' => ['required', 'integer', 'between:0,30'],
            'form.recording_motion_post_trigger_seconds' => ['required', 'integer', 'between:1,60'],
            'form.recording_motion_mask' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator): void {
            $recordingMode = $this->form['recording_mode'] ?? Camera::RECORDING_MODE_OFF;
            $profileIndex = $this->nullableInteger($this->form['recording_profile_index'] ?? null);
            $motionMask = app(RecordingMotionMaskService::class)->normalize(
                $this->form['recording_motion_mask'] ?? null,
                $this->recordingMotionAreaPayload($this->form),
            );

            if ($recordingMode !== Camera::RECORDING_MODE_OFF && !(bool) ($this->form['supports_rtsp'] ?? false)) {
                $validator->errors()->add('form.recording_mode', 'Recording requires RTSP support to be enabled for this camera.');
            }

            if ($profileIndex !== null && !array_key_exists($profileIndex, $this->rtspProfiles)) {
                $validator->errors()->add('form.recording_profile_index', 'Choose a saved RTSP profile or leave the recording source on automatic selection.');
            }

            if ($recordingMode === Camera::RECORDING_MODE_MOTION && ($motionMask['selected_pixels'] ?? 0) < 1) {
                $validator->errors()->add('form.recording_motion_mask', 'Select at least one motion zone pixel before enabling movement recording.');
            }

            if (
                $this->editingCameraId === null
                && (bool) ($this->form['supports_onvif'] ?? false)
                && $this->probeResponse === []
            ) {
                $validator->errors()->add('probeEndpointUrl', 'Probe a reachable ONVIF endpoint before creating a new camera.');
            }
        });

        $validated = $validator->validate()['form'];
        $maskService = app(RecordingMotionMaskService::class);
        $motionMask = $maskService->normalize($validated['recording_motion_mask'] ?? null, $this->recordingMotionAreaPayload($validated));

        $camera = $this->editingCameraId !== null
            ? Camera::query()->findOrFail($this->editingCameraId)
            : new Camera();

        $password = $validated['password'];
        $onvifPort = $validated['onvif_port'] ?? $camera->onvif_port ?? 80;
        $onvifPath = $validated['onvif_path'] ?? $camera->onvif_path ?? '/onvif/device_service';

        $camera->fill([
            'name' => trim($validated['name']),
            'local_ip' => trim($validated['local_ip']),
            'hostname' => $this->nullableString($validated['hostname']),
            'manufacturer' => $this->nullableString($validated['manufacturer']),
            'model' => $this->nullableString($validated['model']),
            'serial_number' => $this->nullableString($validated['serial_number']),
            'mac_address' => $this->nullableString($validated['mac_address']),
            'http_port' => (int) $validated['http_port'],
            'onvif_port' => (int) $onvifPort,
            'onvif_path' => Str::start(trim((string) $onvifPath), '/'),
            'rtsp_port' => (int) $validated['rtsp_port'],
            'rtsp_path' => $this->normalizeRtspPath($validated['rtsp_path']),
            'rtsp_transport' => $validated['rtsp_transport'],
            'username' => $this->nullableString($validated['username']),
            'supports_onvif' => (bool) $validated['supports_onvif'],
            'supports_rtsp' => (bool) $validated['supports_rtsp'],
            'is_enabled' => (bool) $validated['is_enabled'],
            'recording_mode' => $validated['recording_mode'],
            'recording_profile_index' => $this->nullableInteger($validated['recording_profile_index']),
            'recording_retention_days' => (int) $validated['recording_retention_days'],
            'motion_sensitivity' => (int) $validated['motion_sensitivity'],
            'recording_motion_pre_roll_seconds' => (int) $validated['recording_motion_pre_roll_seconds'],
            'recording_motion_post_trigger_seconds' => (int) $validated['recording_motion_post_trigger_seconds'],
            'recording_motion_area' => $maskService->bounds($motionMask),
            'recording_motion_mask' => $motionMask,
        ]);

        $metadata = is_array($camera->metadata) ? $camera->metadata : [];

        if ($this->draftMetadata !== []) {
            $metadata = array_merge($metadata, $this->draftMetadata);
        }

        if ($this->rtspProfiles !== []) {
            $metadata['rtsp_profiles'] = $this->rtspProfiles;
        }

        if ($metadata !== []) {
            $camera->metadata = $metadata;
        }

        if ($camera->exists) {
            if ($password !== '') {
                $camera->password = $password;
            }
        } else {
            $camera->password = $password !== '' ? $password : null;
        }

        $camera->save();
        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        $this->pendingDeleteCameraId = null;
        $this->editingCameraId = $camera->id;
        $this->editCamera($camera->id);
        $this->statusMessage = $camera->wasRecentlyCreated
            ? 'Created '.$camera->name.' in the camera fleet.'
            : 'Updated '.$camera->name.' in the camera fleet.';
    }

    /**
     * @param  array<string, mixed>  $mask
     */
    public function syncMotionMask(array $mask): void
    {
        $this->form['recording_motion_mask'] = app(RecordingMotionMaskService::class)->normalize($mask, $this->recordingMotionAreaPayload($this->form));
    }

    public function syncMotionThreshold(mixed $threshold): void
    {
        $this->form['motion_sensitivity'] = max(1, min(100, is_numeric($threshold) ? (int) $threshold : 35));
    }

    public function toggleEnabled(int $cameraId): void
    {
        $camera = Camera::query()->findOrFail($cameraId);
        $camera->forceFill(['is_enabled' => !$camera->is_enabled])->save();

        $this->pendingDeleteCameraId = null;

        if ($this->editingCameraId === $camera->id) {
            $this->form['is_enabled'] = $camera->is_enabled;
        }

        $this->statusMessage = $camera->is_enabled
            ? 'Enabled '.$camera->name.'.'
            : 'Disabled '.$camera->name.'.';
        $this->errorMessage = null;
    }

    public function requestDeleteCamera(int $cameraId): void
    {
        $camera = Camera::query()->findOrFail($cameraId);

        $this->pendingDeleteCameraId = $camera->id;
        $this->statusMessage = null;
        $this->errorMessage = 'Confirm deletion to remove '.$camera->name.' and its stored previews and recordings.';
    }

    public function cancelDeleteCamera(): void
    {
        $this->pendingDeleteCameraId = null;
        $this->errorMessage = null;
    }

    public function deleteCamera(int $cameraId): void
    {
        if ($this->pendingDeleteCameraId !== $cameraId) {
            $this->requestDeleteCamera($cameraId);

            return;
        }

        $camera = Camera::query()->findOrFail($cameraId);
        $name = $camera->name;
        app(CameraStorageService::class)->deleteCameraDirectories($camera);
        $camera->delete();

        $this->pendingDeleteCameraId = null;

        if ($this->editingCameraId === $cameraId) {
            $this->resetEditorState();
            $this->isEditorModalOpen = false;
        }

        $this->statusMessage = 'Deleted '.$name.' from the camera fleet.';
        $this->errorMessage = null;
    }

    public function fetchRtspProfiles(?int $cameraId = null): void
    {
        $this->rtspStatusMessage = null;
        $this->rtspErrorMessage = null;

        $camera = Camera::query()->findOrFail($cameraId ?? $this->editingCameraId);

        try {
            if (!$camera->supports_onvif || $camera->onvifEndpoint() === null) {
                $camera = $this->syncSavedRtspEndpoint($camera);

                if ($this->editingCameraId === $camera->id) {
                    $this->editCamera($camera->id);
                }

                $this->rtspProfiles = $camera->rtspProfiles();
                $this->rtspStatusMessage = 'Saved the configured RTSP endpoint for '.$camera->name.' without requiring ONVIF.';

                return;
            }

            $discovery = app(OnvifRtspStreamService::class)->discover($camera);
            $profiles = $this->mergeDiscoveredProfilesWithSavedState($discovery['profiles'], $camera->rtspProfiles());
            $metadata = is_array($camera->metadata) ? $camera->metadata : [];
            $onvifMetadata = is_array($metadata['onvif'] ?? null) ? $metadata['onvif'] : [];

            $metadata['onvif'] = array_merge($onvifMetadata, [
                'media_service_url' => $discovery['media_service_url'],
                'last_rtsp_sync_at' => now()->utc()->toIso8601String(),
            ]);
            $metadata['rtsp_profiles'] = $profiles;

            $camera->metadata = $metadata;
            $camera->supports_rtsp = $profiles !== [];
            $camera->last_seen_at = now();

            if ($profiles !== []) {
                $primaryUri = $profiles[0]['uri'] ?? null;

                if (is_string($primaryUri) && $primaryUri !== '') {
                    $parts = parse_url($primaryUri);

                    if (is_array($parts)) {
                        $camera->rtsp_port = (int) ($parts['port'] ?? $camera->rtsp_port ?: 554);
                        $camera->rtsp_path = (($parts['path'] ?? '') !== '' || isset($parts['query']))
                            ? ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '')
                            : $camera->rtsp_path;
                    }
                }
            }

            $camera->save();
            $camera = $camera->refresh();

            if ($this->editingCameraId === $camera->id) {
                $this->editCamera($camera->id);
            }

            $this->rtspProfiles = $camera->rtspProfiles();
            $this->rtspStatusMessage = $profiles === []
                ? 'No RTSP profiles were returned for '.$camera->name.'.'
                : 'Retrieved '.count($profiles).' RTSP stream URL'.(count($profiles) === 1 ? '' : 's').' for '.$camera->name.'.';
        } catch (Throwable $exception) {
            report($exception);

            $this->rtspErrorMessage = $exception->getMessage();
        }
    }

    public function testRtspProfile(int $profileIndex): void
    {
        $this->rtspStatusMessage = null;
        $this->rtspErrorMessage = null;

        if ($this->editingCameraId === null) {
            $this->rtspErrorMessage = 'Select a camera before testing a saved RTSP profile.';

            return;
        }

        $camera = Camera::query()->findOrFail($this->editingCameraId);
        $profiles = $camera->rtspProfiles();
        $profile = $profiles[$profileIndex] ?? null;

        if (!is_array($profile)) {
            $this->rtspErrorMessage = 'That RTSP profile is no longer available. Refresh the profile list and try again.';

            return;
        }

        try {
            $profiles[$profileIndex] = app(RtspStreamDiagnosticsService::class)->testAndPreview($camera, $profile, $profileIndex);
            $workingTransport = strtolower((string) ($profiles[$profileIndex]['transport'] ?? ''));

            if (($profiles[$profileIndex]['transport_persistable'] ?? false) === true && in_array($workingTransport, ['tcp', 'udp'], true)) {
                $camera->rtsp_transport = $workingTransport;
            }

            $metadata = is_array($camera->metadata) ? $camera->metadata : [];
            $metadata['rtsp_profiles'] = array_values($profiles);
            $camera->metadata = $metadata;
            $camera->last_seen_at = now();
            $camera->save();

            $this->rtspProfiles = $camera->refresh()->rtspProfiles();
            $this->rtspStatusMessage = ($profiles[$profileIndex]['probe_status'] ?? null) === 'Healthy'
                ? 'RTSP connection verified and preview updated for '.($profiles[$profileIndex]['name'] ?? 'the selected profile').'.'
                : 'RTSP test completed for '.($profiles[$profileIndex]['name'] ?? 'the selected profile').'.';
        } catch (Throwable $exception) {
            report($exception);

            $this->rtspErrorMessage = $exception->getMessage();
        }
    }

    public function render(): View
    {
        $cameras = Camera::query()
            ->orderByDesc('is_enabled')
            ->orderByRaw('last_seen_at is null')
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->get();
        $selectedCamera = $this->editingCameraId !== null
            ? $cameras->firstWhere('id', $this->editingCameraId)
            : null;

        return view('livewire.camera-fleet.manager', [
            'cameras' => $cameras,
            'summary' => [
                'total' => $cameras->count(),
                'enabled' => $cameras->where('is_enabled', true)->count(),
                'onvif' => $cameras->where('supports_onvif', true)->count(),
                'rtsp' => $cameras->where('supports_rtsp', true)->count(),
            ],
            'selectedCamera' => $selectedCamera,
            'selectedCameraRecentRecordings' => $selectedCamera?->recordings()->latest('scheduled_for')->limit(5)->get() ?? collect(),
            'hasStoredPassword' => $selectedCamera?->getRawOriginal('password') !== null,
            'selectedCameraId' => $selectedCamera?->id,
            'transportOptions' => ['tcp', 'udp'],
            'recordingModes' => [
                Camera::RECORDING_MODE_OFF => 'Off',
                Camera::RECORDING_MODE_CONTINUOUS => 'Constantly recording',
                Camera::RECORDING_MODE_MOTION => 'Record on movement',
            ],
        ]);
    }

    private function resetEditorState(): void
    {
        $this->editingCameraId = null;
        $this->pendingDeleteCameraId = null;
        $this->form = $this->defaultForm();
        $this->rtspProfiles = [];
        $this->probeEndpointUrl = '';
        $this->probeResponse = [];
        $this->draftMetadata = [];
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->probeStatusMessage = null;
        $this->probeErrorMessage = null;
        $this->probeWarningMessage = null;
        $this->probeLastCheckedAt = null;
        $this->rtspStatusMessage = null;
        $this->rtspErrorMessage = null;
        $this->resetErrorBag();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultForm(): array
    {
        return [
            'name' => '',
            'local_ip' => '',
            'hostname' => '',
            'manufacturer' => '',
            'model' => '',
            'serial_number' => '',
            'mac_address' => '',
            'http_port' => 80,
            'onvif_port' => 80,
            'onvif_path' => '/onvif/device_service',
            'rtsp_port' => 554,
            'rtsp_path' => '',
            'rtsp_transport' => 'tcp',
            'username' => '',
            'password' => '',
            'supports_onvif' => true,
            'supports_rtsp' => false,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_OFF,
            'recording_profile_index' => null,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 35,
            'recording_motion_pre_roll_seconds' => max(0, min(30, (int) config('recording.motion.pre_roll_seconds', 8))),
            'recording_motion_post_trigger_seconds' => max(1, min(60, (int) config('recording.motion.post_trigger_seconds', 20))),
            'recording_motion_mask' => app(RecordingMotionMaskService::class)->fullFrameMask(),
            'recording_motion_x' => 0,
            'recording_motion_y' => 0,
            'recording_motion_width' => 100,
            'recording_motion_height' => 100,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRtspPath(mixed $value): ?string
    {
        $path = $this->nullableString($value);

        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, '?')) {
            return $path;
        }

        return Str::start($path, '/');
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function recordingMotionAreaPayload(array $values): array
    {
        return [
            'x' => (int) ($values['recording_motion_x'] ?? 0),
            'y' => (int) ($values['recording_motion_y'] ?? 0),
            'width' => (int) ($values['recording_motion_width'] ?? 100),
            'height' => (int) ($values['recording_motion_height'] ?? 100),
        ];
    }

    private function syncSavedRtspEndpoint(Camera $camera): Camera
    {
        $endpoint = $camera->rtspEndpoint();

        if ($endpoint === null) {
            throw new \RuntimeException('This camera needs a saved RTSP port and path before it can use RTSP without ONVIF.');
        }

        $existingProfiles = array_values(array_filter(
            $camera->rtspProfiles(),
            static fn (mixed $profile): bool => is_array($profile),
        ));

        $existingManualProfile = collect($existingProfiles)->first(function (array $profile) use ($endpoint): bool {
            return ($profile['source'] ?? null) === 'manual'
                || ($profile['name'] ?? null) === 'Saved endpoint'
                || ($profile['uri'] ?? null) === $endpoint;
        });

        $manualProfile = array_merge(is_array($existingManualProfile) ? $existingManualProfile : [], [
            'name' => 'Saved endpoint',
            'token' => 'manual',
            'uri' => $endpoint,
            'path' => $this->parseRtspPathFromUri($endpoint),
            'transport' => strtoupper($camera->rtsp_transport),
            'source' => 'manual',
        ]);

        $metadata = is_array($camera->metadata) ? $camera->metadata : [];
        $metadata['rtsp_profiles'] = array_values(array_merge([
            $manualProfile,
        ], array_filter($existingProfiles, function (array $profile) use ($endpoint): bool {
            return ($profile['source'] ?? null) !== 'manual'
                && ($profile['name'] ?? null) !== 'Saved endpoint'
                && ($profile['uri'] ?? null) !== $endpoint;
        })));

        $camera->metadata = $metadata;
        $camera->supports_rtsp = true;
        $camera->last_seen_at = now();
        $camera->save();

        return $camera->refresh();
    }

    /**
     * @param  array<int, array<string, string|null>>  $profiles
     * @param  array<int, array<string, string|null>>  $savedProfiles
     * @return array<int, array<string, string|null>>
     */
    private function mergeDiscoveredProfilesWithSavedState(array $profiles, array $savedProfiles): array
    {
        return array_values(array_map(function (array $profile) use ($savedProfiles): array {
            $savedProfile = $this->matchSavedProfile($profile, $savedProfiles);

            if ($savedProfile === null) {
                return $profile;
            }

            return array_merge($profile, Arr::only($savedProfile, [
                'probe_status',
                'probe_checked_at',
                'probe_message',
                'video_codec',
                'video_resolution',
                'preview_path',
                'preview_generated_at',
                'preview_message',
                'transport',
            ]));
        }, $profiles));
    }

    /**
     * @param  array<string, string|null>  $profile
     * @param  array<int, array<string, string|null>>  $savedProfiles
     * @return array<string, string|null>|null
     */
    private function matchSavedProfile(array $profile, array $savedProfiles): ?array
    {
        $token = $this->nullableString($profile['token'] ?? null);
        $uri = $this->nullableString($profile['uri'] ?? null);
        $path = $this->nullableString($profile['path'] ?? null);
        $name = $this->nullableString($profile['name'] ?? null);

        foreach ($savedProfiles as $savedProfile) {
            if (!is_array($savedProfile)) {
                continue;
            }

            $savedToken = $this->nullableString($savedProfile['token'] ?? null);
            $savedUri = $this->nullableString($savedProfile['uri'] ?? null);
            $savedPath = $this->nullableString($savedProfile['path'] ?? null);
            $savedName = $this->nullableString($savedProfile['name'] ?? null);

            if ($token !== null && $savedToken !== null && $token === $savedToken) {
                return $savedProfile;
            }

            if ($uri !== null && $savedUri !== null && $uri === $savedUri) {
                return $savedProfile;
            }

            if ($path !== null && $savedPath !== null && $path === $savedPath && $name !== null && $savedName !== null && $name === $savedName) {
                return $savedProfile;
            }
        }

        return null;
    }

    private function parseRtspPathFromUri(string $uri): ?string
    {
        $parts = parse_url($uri);

        if (!is_array($parts)) {
            return null;
        }

        return $this->nullableString(((string) Arr::get($parts, 'path', '')).(isset($parts['query']) ? '?'.$parts['query'] : ''));
    }
}