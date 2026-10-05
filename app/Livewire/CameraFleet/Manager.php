<?php

namespace App\Livewire\CameraFleet;

use App\Models\Camera;
use App\Services\ApplicationSettingsService;
use App\Services\CameraFleet\OnvifCameraDraftService;
use App\Services\CameraStorageService;
use App\Services\Onvif\OnvifRtspStreamService;
use App\Services\Onvif\RtspStreamDiagnosticsService;
use App\Services\RecordingMotionMaskService;
use App\Services\Relay\MediaMtxProcessService;
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

    public bool $showCameraPassword = false;

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
        $this->showCameraPassword = false;
        $this->form['password'] = '';
        $this->resetErrorBag();
    }

    public function toggleCameraPasswordVisibility(): void
    {
        $this->showCameraPassword = ! $this->showCameraPassword;
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
        $this->showCameraPassword = false;
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
            'recording_rtsp_path' => $camera->recording_rtsp_path ?? $camera->rtsp_path ?? '',
            'rtsp_transport' => $camera->rtsp_transport,
            'username' => $camera->username ?? '',
            'password' => '',
            'supports_onvif' => $camera->supports_onvif,
            'supports_rtsp' => $camera->supports_rtsp,
            'is_enabled' => $camera->is_enabled,
            'recording_mode' => $camera->recording_mode,
            'recording_retention_days' => $camera->recording_retention_days,
            'motion_sensitivity' => $camera->motion_sensitivity,
            'recording_motion_trigger_pixels' => $camera->motionTriggerPixels(),
            'recording_motion_pre_roll_seconds' => $camera->motionPreRollSeconds(),
            'recording_motion_post_trigger_seconds' => $camera->motionPostTriggerSeconds(),
            'recording_motion_mask' => $camera->recordingMotionMask(),
            'recording_motion_x' => $camera->recordingMotionArea()['x'],
            'recording_motion_y' => $camera->recordingMotionArea()['y'],
            'recording_motion_width' => $camera->recordingMotionArea()['width'],
            'recording_motion_height' => $camera->recordingMotionArea()['height'],
            'live_transcode_quality' => $camera->liveTranscodeSettings()['quality'],
            'live_transcode_rate_control' => $camera->liveTranscodeSettings()['rate_control'],
            'live_transcode_bitrate_kbps' => $camera->liveTranscodeSettings()['bitrate_kbps'],
            'live_transcode_force_video' => $camera->liveTranscodeSettings()['force_video_transcode'],
        ];
        $this->probeEndpointUrl = $camera->onvifEndpoint() ?? '';
        $this->probeResponse = [];
        $this->draftMetadata = [];
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
            'form.rtsp_path' => [Rule::requiredIf(fn (): bool => (bool) ($this->form['supports_rtsp'] ?? false)), 'nullable', 'string', 'max:255'],
            'form.recording_rtsp_path' => ['nullable', 'string', 'max:255'],
            'form.rtsp_transport' => ['required', 'in:tcp,udp'],
            'form.username' => ['nullable', 'string', 'max:255'],
            'form.password' => ['nullable', 'string', 'max:255'],
            'form.supports_onvif' => ['boolean'],
            'form.supports_rtsp' => ['boolean'],
            'form.is_enabled' => ['boolean'],
            'form.recording_mode' => ['required', Rule::in(Camera::RECORDING_MODES)],
            'form.recording_retention_days' => ['required', 'integer', 'between:1,365'],
            'form.recording_motion_trigger_pixels' => ['required', 'integer', 'min:1'],
            'form.recording_motion_pre_roll_seconds' => ['required', 'integer', 'between:0,30'],
            'form.recording_motion_post_trigger_seconds' => ['required', 'integer', 'between:1,60'],
            'form.recording_motion_mask' => ['nullable', 'array'],
            'form.live_transcode_quality' => ['required', Rule::in(Camera::LIVE_TRANSCODE_QUALITY_OPTIONS)],
            'form.live_transcode_rate_control' => ['required', Rule::in(Camera::LIVE_TRANSCODE_RATE_CONTROL_OPTIONS)],
            'form.live_transcode_bitrate_kbps' => ['nullable', 'integer', 'between:250,20000'],
            'form.live_transcode_force_video' => ['boolean'],
        ]);

        $validator->after(function ($validator): void {
            $recordingMode = $this->form['recording_mode'] ?? Camera::RECORDING_MODE_OFF;
            $motionMask = app(RecordingMotionMaskService::class)->normalize(
                $this->form['recording_motion_mask'] ?? null,
                $this->recordingMotionAreaPayload($this->form),
            );

            if ($recordingMode !== Camera::RECORDING_MODE_OFF && ! (bool) ($this->form['supports_rtsp'] ?? false)) {
                $validator->errors()->add('form.recording_mode', 'Recording requires RTSP support to be enabled for this camera.');
            }

            if ($recordingMode === Camera::RECORDING_MODE_MOTION && ($motionMask['selected_pixels'] ?? 0) < 1) {
                $validator->errors()->add('form.recording_motion_mask', 'Select at least one motion zone pixel before enabling movement recording.');
            }

            if (($motionMask['selected_pixels'] ?? 0) > 0
                && (int) ($this->form['recording_motion_trigger_pixels'] ?? 0) > $this->maximumMotionTriggerPixels($motionMask)) {
                $validator->errors()->add('form.recording_motion_trigger_pixels', 'Trigger pixels cannot exceed the supported effective pixels for the current motion mask.');
            }

            if (
                $this->editingCameraId === null
                && (bool) ($this->form['supports_onvif'] ?? false)
                && $this->probeResponse === []
            ) {
                $validator->errors()->add('probeEndpointUrl', 'Probe a reachable ONVIF endpoint before creating a new camera.');
            }

            if (($this->form['live_transcode_rate_control'] ?? Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT) === Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR
                && ! is_numeric($this->form['live_transcode_bitrate_kbps'] ?? null)) {
                $validator->errors()->add('form.live_transcode_bitrate_kbps', 'Enter a target bitrate in kbps when constant bitrate mode is selected.');
            }
        });

        $validated = $validator->validate()['form'];
        $maskService = app(RecordingMotionMaskService::class);
        $motionMask = $maskService->normalize($validated['recording_motion_mask'] ?? null, $this->recordingMotionAreaPayload($validated));

        $camera = $this->editingCameraId !== null
            ? Camera::query()->findOrFail($this->editingCameraId)
            : new Camera;

        $password = $validated['password'];
        $onvifPort = $validated['onvif_port'] ?? $camera->onvif_port ?? 80;
        $onvifPath = $validated['onvif_path'] ?? $camera->onvif_path ?? '/onvif/device_service';

        $liveRtspPath = $this->normalizeRtspPath($validated['rtsp_path']);
        $recordingRtspPath = $this->normalizeRtspPath($validated['recording_rtsp_path']) ?? $liveRtspPath;

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
            'rtsp_path' => $liveRtspPath,
            'recording_rtsp_path' => $recordingRtspPath,
            'rtsp_transport' => $validated['rtsp_transport'],
            'username' => $this->nullableString($validated['username']),
            'supports_onvif' => (bool) $validated['supports_onvif'],
            'supports_rtsp' => (bool) $validated['supports_rtsp'],
            'is_enabled' => (bool) $validated['is_enabled'],
            'recording_mode' => $validated['recording_mode'],
            'recording_retention_days' => (int) $validated['recording_retention_days'],
            'motion_sensitivity' => (int) ($camera->motion_sensitivity ?? 35),
            'recording_motion_trigger_pixels' => (int) $validated['recording_motion_trigger_pixels'],
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

        $liveTranscodeSettings = $this->normalizedLiveTranscodeSettings($validated);

        if ($liveTranscodeSettings !== null) {
            $metadata['live_transcode'] = $liveTranscodeSettings;
        } else {
            unset($metadata['live_transcode']);
        }

        $camera->metadata = $metadata !== [] ? $metadata : null;

        if ($password !== null && $password !== '') {
            $camera->password = $password;
        }

        $camera->save();
        app(CameraStorageService::class)->ensureCameraDirectories($camera);
        $this->syncRelayConfig();

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
        $normalizedMask = app(RecordingMotionMaskService::class)->normalize($mask, $this->recordingMotionAreaPayload($this->form));

        $this->form['recording_motion_mask'] = $normalizedMask;

        $selectedPixels = max(0, (int) ($normalizedMask['selected_pixels'] ?? 0));
        $defaultTriggerPixels = $this->defaultMotionTriggerPixels($normalizedMask);
        $currentTriggerPixels = is_numeric($this->form['recording_motion_trigger_pixels'] ?? null)
            ? (int) $this->form['recording_motion_trigger_pixels']
            : $defaultTriggerPixels;

        $this->form['recording_motion_trigger_pixels'] = $selectedPixels > 0
            ? max(1, min($this->maximumMotionTriggerPixels($normalizedMask), $currentTriggerPixels))
            : 1;
    }

    public function syncMotionTriggerPixels(mixed $triggerPixels): void
    {
        $motionMask = is_array($this->form['recording_motion_mask'] ?? null)
            ? $this->form['recording_motion_mask']
            : app(RecordingMotionMaskService::class)->fullFrameMask();
        $selectedPixels = max(0, (int) ($motionMask['selected_pixels'] ?? 0));
        $fallback = $this->defaultMotionTriggerPixels($motionMask);
        $value = is_numeric($triggerPixels) ? (int) $triggerPixels : $fallback;

        $this->form['recording_motion_trigger_pixels'] = $selectedPixels > 0
            ? max(1, min($this->maximumMotionTriggerPixels($motionMask), $value))
            : 1;
    }

    public function syncMotionThreshold(mixed $threshold): void
    {
        $this->syncMotionTriggerPixels($threshold);
    }

    /**
     * @param  array<string, mixed>  $mask
     */
    public function saveCameraFromMotionEditor(array $mask, mixed $triggerPixels): void
    {
        $this->syncMotionMask($mask);
        $this->syncMotionTriggerPixels($triggerPixels);
        $this->saveCamera();
    }

    public function toggleEnabled(int $cameraId): void
    {
        $camera = Camera::query()->findOrFail($cameraId);
        $camera->forceFill(['is_enabled' => ! $camera->is_enabled])->save();
        $this->syncRelayConfig();

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
        $this->syncRelayConfig();

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
            if (! $camera->supports_onvif || $camera->onvifEndpoint() === null) {
                $camera = $this->syncSavedRtspEndpoint($camera);
                $this->syncRelayConfig();

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
                        $primaryPath = (($parts['path'] ?? '') !== '' || isset($parts['query']))
                            ? ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '')
                            : null;

                        $camera->rtsp_port = (int) ($parts['port'] ?? $camera->rtsp_port ?: 554);

                        if ($this->nullableString($camera->rtsp_path) === null && is_string($primaryPath) && $primaryPath !== '') {
                            $camera->rtsp_path = $primaryPath;
                        }

                        if ($this->nullableString($camera->recording_rtsp_path) === null && is_string($primaryPath) && $primaryPath !== '') {
                            $camera->recording_rtsp_path = $primaryPath;
                        }
                    }
                }
            }

            $camera->save();
            $this->syncRelayConfig();
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

        if (! is_array($profile)) {
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
            $this->syncRelayConfig();

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
            'liveTranscodeQualityOptions' => [
                Camera::LIVE_TRANSCODE_QUALITY_DEFAULT => 'Stack default: preset ultrafast, CRF 23 (CPU low)',
                Camera::LIVE_TRANSCODE_QUALITY_SPEED => 'Preset ultrafast, CRF 25 (CPU low)',
                Camera::LIVE_TRANSCODE_QUALITY_BALANCED => 'Preset veryfast, CRF 22 (CPU medium)',
                Camera::LIVE_TRANSCODE_QUALITY_QUALITY => 'Preset fast, CRF 20 (CPU high)',
            ],
            'liveTranscodeRateControlOptions' => [
                Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT => 'Use stack default',
                Camera::LIVE_TRANSCODE_RATE_CONTROL_CRF => 'Quality-first (CRF)',
                Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR => 'Constant bitrate (CBR)',
            ],
        ]);
    }

    private function resetEditorState(): void
    {
        $this->editingCameraId = null;
        $this->pendingDeleteCameraId = null;
        $this->showCameraPassword = false;
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
        $defaultMask = app(RecordingMotionMaskService::class)->fullFrameMask();

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
            'recording_rtsp_path' => '',
            'rtsp_transport' => 'tcp',
            'username' => '',
            'password' => '',
            'supports_onvif' => true,
            'supports_rtsp' => false,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_OFF,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 35,
            'recording_motion_trigger_pixels' => $this->defaultMotionTriggerPixels($defaultMask),
            'recording_motion_pre_roll_seconds' => max(0, min(30, (int) config('recording.motion.pre_roll_seconds', 8))),
            'recording_motion_post_trigger_seconds' => max(1, min(60, (int) config('recording.motion.post_trigger_seconds', 20))),
            'recording_motion_mask' => $defaultMask,
            'recording_motion_x' => 0,
            'recording_motion_y' => 0,
            'recording_motion_width' => 100,
            'recording_motion_height' => 100,
            'live_transcode_quality' => Camera::LIVE_TRANSCODE_QUALITY_DEFAULT,
            'live_transcode_rate_control' => Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT,
            'live_transcode_bitrate_kbps' => null,
            'live_transcode_force_video' => false,
        ];
    }

    /**
     * @param  array{selected_pixels?: int}|null  $motionMask
     */
    private function defaultMotionTriggerPixels(?array $motionMask = null): int
    {
        $selectedPixels = max(0, (int) ($motionMask['selected_pixels'] ?? 0));

        if ($selectedPixels < 1) {
            return 1;
        }

        return max(1, min($this->maximumMotionTriggerPixels($motionMask), (int) ceil($selectedPixels * 0.35)));
    }

    /**
     * @param  array{selected_pixels?: int}|null  $motionMask
     */
    private function maximumMotionTriggerPixels(?array $motionMask = null): int
    {
        $selectedPixels = max(0, (int) ($motionMask['selected_pixels'] ?? 0));
        $bonusMultiplier = max(0, (int) config('recording.motion.cluster_bonus_multiplier', 2));

        return $selectedPixels > 0
            ? max(1, $selectedPixels + ($selectedPixels * $bonusMultiplier))
            : 1;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
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
     * @return array{quality: string, rate_control: string, bitrate_kbps?: int, force_video_transcode?: bool}|null
     */
    private function normalizedLiveTranscodeSettings(array $values): ?array
    {
        $quality = strtolower(trim((string) ($values['live_transcode_quality'] ?? Camera::LIVE_TRANSCODE_QUALITY_DEFAULT)));

        if (! in_array($quality, Camera::LIVE_TRANSCODE_QUALITY_OPTIONS, true)) {
            $quality = Camera::LIVE_TRANSCODE_QUALITY_DEFAULT;
        }

        $rateControl = strtolower(trim((string) ($values['live_transcode_rate_control'] ?? Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT)));

        if (! in_array($rateControl, Camera::LIVE_TRANSCODE_RATE_CONTROL_OPTIONS, true)) {
            $rateControl = Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT;
        }

        $bitrateKbps = $this->nullableInteger($values['live_transcode_bitrate_kbps'] ?? null);
        $forceVideoTranscode = (bool) ($values['live_transcode_force_video'] ?? false);

        if ($bitrateKbps !== null) {
            $bitrateKbps = max(250, min(20000, $bitrateKbps));
        }

        if ($quality === Camera::LIVE_TRANSCODE_QUALITY_DEFAULT
            && $rateControl === Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT
            && $bitrateKbps === null
            && ! $forceVideoTranscode) {
            return null;
        }

        $settings = [
            'quality' => $quality,
            'rate_control' => $rateControl,
        ];

        if ($bitrateKbps !== null) {
            $settings['bitrate_kbps'] = $bitrateKbps;
        }

        if ($forceVideoTranscode) {
            $settings['force_video_transcode'] = true;
        }

        return $settings;
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

    private function syncRelayConfig(): void
    {
        try {
            app(MediaMtxProcessService::class)->syncConfig();
        } catch (Throwable $exception) {
            report($exception);

            $this->errorMessage = 'Saved the camera change, but the relay configuration could not be refreshed automatically.';
        }
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
            if (! is_array($savedProfile)) {
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

        if (! is_array($parts)) {
            return null;
        }

        return $this->nullableString(((string) Arr::get($parts, 'path', '')).(isset($parts['query']) ? '?'.$parts['query'] : ''));
    }
}
