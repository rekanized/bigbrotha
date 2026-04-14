<?php

namespace App\Models;

use App\Services\CameraStorageService;
use App\Services\RecordingMotionMaskService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'name',
    'local_ip',
    'hostname',
    'manufacturer',
    'model',
    'serial_number',
    'mac_address',
    'http_port',
    'onvif_port',
    'rtsp_port',
    'onvif_path',
    'rtsp_path',
    'recording_rtsp_path',
    'rtsp_transport',
    'username',
    'password',
    'supports_onvif',
    'supports_rtsp',
    'is_enabled',
    'last_seen_at',
    'metadata',
    'recording_mode',
    'recording_profile_index',
    'recording_retention_days',
    'motion_sensitivity',
    'recording_motion_pre_roll_seconds',
    'recording_motion_post_trigger_seconds',
    'recording_motion_area',
    'recording_motion_mask',
    'recording_last_motion_at',
    'recording_last_recorded_at',
])]
#[Hidden(['password'])]
class Camera extends Model
{
    use HasFactory, HasUuids;

    public const RECORDING_MODE_OFF = 'off';

    public const RECORDING_MODE_CONTINUOUS = 'continuous';

    public const RECORDING_MODE_MOTION = 'motion';

    public const RECORDING_MODES = [
        self::RECORDING_MODE_OFF,
        self::RECORDING_MODE_CONTINUOUS,
        self::RECORDING_MODE_MOTION,
    ];

    public function recordings(): HasMany
    {
        return $this->hasMany(CameraRecording::class);
    }

    public function liveWallTiles(): HasMany
    {
        return $this->hasMany(LiveWallTile::class);
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function onvifEndpoint(): ?string
    {
        if (!$this->supports_onvif) {
            return null;
        }

        $host = $this->hostname ?: $this->local_ip;

        if (!$host) {
            return null;
        }

        return sprintf('http://%s:%d%s', $host, $this->onvif_port, Str::start($this->onvif_path ?: '/onvif/device_service', '/'));
    }

    public function rtspEndpoint(?string $path = null): ?string
    {
        if (!$this->supports_rtsp) {
            return null;
        }

        $host = $this->hostname ?: $this->local_ip;

        if (!$host) {
            return null;
        }

        $resolvedPath = $this->normalizedRtspPath($path ?? $this->rtsp_path);

        if ($resolvedPath === null) {
            return null;
        }

        return sprintf('rtsp://%s:%d%s', $host, $this->rtsp_port, $resolvedPath);
    }

    public function recordingRtspPath(): ?string
    {
        return $this->normalizedRtspPath($this->recording_rtsp_path ?? $this->rtsp_path);
    }

    public function recordingRtspEndpoint(): ?string
    {
        $path = $this->recordingRtspPath();

        return $path !== null ? $this->rtspEndpoint($path) : null;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function rtspProfiles(): array
    {
        $profiles = $this->metadata['rtsp_profiles'] ?? null;

        return is_array($profiles) ? $profiles : [];
    }

    /**
     * @return array{index: int, profile: array<string, string|null>}|null
     */
    public function latestRtspPreview(): ?array
    {
        $latestPreview = null;
        $latestTimestamp = null;

        $storage = app(CameraStorageService::class);
        $skipRemotePreviewValidation = $storage->usingNetworkStorage();

        foreach ($this->rtspProfiles() as $index => $profile) {
            $previewPath = is_array($profile) ? ($profile['preview_path'] ?? null) : null;

            if (!is_array($profile) || !is_string($previewPath) || $previewPath === '') {
                continue;
            }

            if (!$skipRemotePreviewValidation && !$storage->hasUsablePreview($previewPath)) {
                continue;
            }

            $candidateTimestamp = strtotime((string) ($profile['preview_generated_at'] ?? $profile['probe_checked_at'] ?? '')) ?: 0;

            if ($latestPreview === null || $candidateTimestamp >= ($latestTimestamp ?? 0)) {
                $latestPreview = [
                    'index' => $index,
                    'profile' => $profile,
                ];
                $latestTimestamp = $candidateTimestamp;
            }
        }

        return $latestPreview;
    }

    /**
     * @return array{index: int, profile: array<string, string|null>}|null
     */
    public function rtspPreviewRefreshTarget(): ?array
    {
        $latestPreview = $this->latestRtspPreview();

        if ($latestPreview !== null) {
            return $latestPreview;
        }

        foreach ($this->rtspProfiles() as $index => $profile) {
            if (!is_array($profile) || !is_string($profile['uri'] ?? null) || trim((string) $profile['uri']) === '') {
                continue;
            }

            return [
                'index' => $index,
                'profile' => $profile,
            ];
        }

        return null;
    }

    public function hasRecordingEnabled(): bool
    {
        return $this->is_enabled
            && $this->supports_rtsp
            && in_array($this->recording_mode, [self::RECORDING_MODE_CONTINUOUS, self::RECORDING_MODE_MOTION], true);
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function recordingMotionArea(): array
    {
        $legacyArea = $this->legacyRecordingMotionArea();

        if ($legacyArea !== null) {
            return $legacyArea;
        }

        return app(RecordingMotionMaskService::class)->bounds($this->recordingMotionMask());
    }

    /**
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    public function recordingMotionMask(): array
    {
        return app(RecordingMotionMaskService::class)->normalize($this->recording_motion_mask, $this->legacyRecordingMotionArea());
    }

    public function motionTriggerThreshold(): int
    {
        return max(1, min(100, is_numeric($this->motion_sensitivity) ? (int) $this->motion_sensitivity : 35));
    }

    public function motionPreRollSeconds(): int
    {
        return max(0, min(30, is_numeric($this->recording_motion_pre_roll_seconds)
            ? (int) $this->recording_motion_pre_roll_seconds
            : (int) config('recording.motion.pre_roll_seconds', 8)));
    }

    public function motionPostTriggerSeconds(): int
    {
        return max(1, min(60, is_numeric($this->recording_motion_post_trigger_seconds)
            ? (int) $this->recording_motion_post_trigger_seconds
            : (int) config('recording.motion.post_trigger_seconds', 20)));
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function legacyRecordingMotionArea(): ?array
    {
        if (!is_array($this->recording_motion_area)) {
            return null;
        }

        $defaults = [
            'x' => 0,
            'y' => 0,
            'width' => 100,
            'height' => 100,
        ];
        $area = $this->recording_motion_area;
        $normalized = [];

        foreach ($defaults as $key => $defaultValue) {
            $value = $area[$key] ?? $defaultValue;
            $normalized[$key] = is_numeric($value) ? (int) $value : $defaultValue;
        }

        $normalized['x'] = max(0, min(95, $normalized['x']));
        $normalized['y'] = max(0, min(95, $normalized['y']));
        $normalized['width'] = max(5, min(100 - $normalized['x'], $normalized['width']));
        $normalized['height'] = max(5, min(100 - $normalized['y'], $normalized['height']));

        return $normalized;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_port' => 'integer',
            'onvif_port' => 'integer',
            'rtsp_port' => 'integer',
            'supports_onvif' => 'boolean',
            'supports_rtsp' => 'boolean',
            'is_enabled' => 'boolean',
            'recording_profile_index' => 'integer',
            'recording_retention_days' => 'integer',
            'motion_sensitivity' => 'integer',
            'recording_motion_pre_roll_seconds' => 'integer',
            'recording_motion_post_trigger_seconds' => 'integer',
            'recording_motion_area' => 'array',
            'recording_motion_mask' => 'array',
            'recording_last_motion_at' => 'datetime',
            'recording_last_recorded_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
            'password' => 'encrypted',
        ];
    }

    private function normalizedRtspPath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '?')) {
            return $trimmed;
        }

        return Str::start($trimmed, '/');
    }
}