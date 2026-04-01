<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    'rtsp_transport',
    'username',
    'password',
    'supports_onvif',
    'supports_rtsp',
    'is_enabled',
    'last_seen_at',
    'metadata',
])]
#[Hidden(['password'])]
class Camera extends Model
{
    use HasFactory, HasUuids;

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

    public function rtspEndpoint(): ?string
    {
        if (!$this->supports_rtsp) {
            return null;
        }

        $host = $this->hostname ?: $this->local_ip;

        if (!$host) {
            return null;
        }

        return sprintf('rtsp://%s:%d%s', $host, $this->rtsp_port, Str::start($this->rtsp_path ?: '', '/'));
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

        foreach ($this->rtspProfiles() as $index => $profile) {
            if (!is_array($profile) || !is_string($profile['preview_path'] ?? null) || ($profile['preview_path'] ?? '') === '') {
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
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
            'password' => 'encrypted',
        ];
    }
}