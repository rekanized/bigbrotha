<?php

namespace App\Support\Audit;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AuditLogPresenter
{
    public const SETTING_LABELS = [
        'app_timezone' => 'Display timezone',
        'audit_retention_days' => 'Audit log retention',
        '__network_storage__' => 'Network storage',
        'auth_manual_enabled' => 'Local sign-in',
        'auth_google_enabled' => 'Google sign-in',
        'auth_setup_complete' => 'Initial setup',
    ];

    public function __construct(public readonly AuditLog $audit, private readonly array $cameraNames = []) {}

    public static function fieldLabel(string $key): string
    {
        return match ($key) {
            'value' => 'Setting value',
            'is_admin' => 'Administrator access',
            'local_ip' => 'Camera IP address',
            'camera_id' => 'Camera',
            'capture_mode' => 'Recording mode',
            'scheduled_for' => 'Scheduled time',
            'relative_path' => 'Recording file',
            'file_size_bytes' => 'File size (bytes)',
            'source_profile_index' => 'Stream profile',
            'from' => 'Previous status',
            'to' => 'New status',
            'message', 'reason' => 'Reason',
            default => Str::headline($key),
        };
    }

    public function subject(): string
    {
        if ($this->audit->auditable_type === AppSetting::class) {
            $key = $this->audit->new_values['key'] ?? $this->audit->old_values['key'] ?? $this->audit->subjectReference();

            return self::SETTING_LABELS[$key] ?? Str::headline(preg_replace('/^auth_/', '', $key));
        }

        return $this->audit->subjectReference();
    }

    public function cameraName(): ?string
    {
        if ($this->audit->auditable_type !== CameraRecording::class) {
            return null;
        }

        $id = $this->audit->metadata['camera_id'] ?? $this->audit->new_values['camera_id'] ?? $this->audit->old_values['camera_id'] ?? $this->audit->auditable?->camera_id;

        return $id ? ($this->cameraNames[$id] ?? 'Camera #'.$id) : null;
    }

    public function status(): ?string
    {
        return $this->audit->new_values['status'] ?? $this->audit->metadata['to'] ?? null;
    }

    public function headline(): string
    {
        if ($this->audit->event === 'recording.transient_discarded') {
            return 'Temporary motion recording removed';
        }

        if ($this->audit->auditable_type === CameraRecording::class && $this->status()) {
            return match ($this->status()) {
                'queued' => 'Recording queued',
                'processing' => 'Recording started',
                'recorded' => 'Recording saved',
                'skipped' => 'Recording skipped',
                'failed' => 'Recording failed',
                default => 'Recording status changed',
            };
        }

        return $this->audit->subjectTypeLabel().' '.match ($this->audit->event) {
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            default => Str::lower($this->audit->eventLabel()),
        };
    }

    public function tone(): string
    {
        return match (true) {
            $this->status() === 'failed' => 'alert',
            $this->audit->event === 'deleted', $this->audit->event === 'recording.transient_discarded', $this->status() === 'skipped' => 'warn',
            $this->status() === 'recorded' => 'good',
            default => 'neutral',
        };
    }

    public function source(): string
    {
        return match ($this->audit->source) {
            AuditLog::SOURCE_HTTP => 'Web app',
            AuditLog::SOURCE_QUEUE => 'Background worker',
            AuditLog::SOURCE_CONSOLE => 'Scheduled task or command',
            AuditLog::SOURCE_SYSTEM => 'System process',
            default => $this->audit->sourceLabel(),
        };
    }

    public function explanation(): string
    {
        if ($this->audit->event === 'recording.transient_discarded') {
            return 'A temporary motion entry was removed. This event does not mean a saved video was deleted.';
        }

        if ($this->audit->auditable_type === CameraRecording::class) {
            return match ($this->status()) {
                'queued' => 'Waiting for a recording worker.',
                'processing' => 'The worker began capturing this segment.',
                'recorded' => 'The worker saved this segment. Its current availability depends on retention and storage.',
                'skipped' => 'The worker did not save a video for this segment.',
                'failed' => 'The recording could not be completed. Review the reason below.',
                default => 'Recording details changed.',
            };
        }

        $fields = array_filter($this->audit->changeKeys(), static fn (string $key): bool => $key !== 'id');

        return match ($this->audit->event) {
            'created' => 'A new '.Str::lower($this->audit->subjectTypeLabel()).' was added.',
            'deleted' => 'This '.Str::lower($this->audit->subjectTypeLabel()).' was removed.',
            default => 'Changed: '.implode(', ', array_map(self::fieldLabel(...), array_slice($fields, 0, 3))).(count($fields) > 3 ? ' and '.(count($fields) - 3).' more.' : '.'),
        };
    }

    public function reason(): ?string
    {
        if ($this->audit->auditable_type !== CameraRecording::class) {
            return null;
        }

        return $this->audit->metadata['reason'] ?? $this->audit->new_values['message'] ?? $this->audit->old_values['message'] ?? null;
    }

    public function value(string $key, mixed $value): string
    {
        $settingKey = $this->audit->new_values['key'] ?? $this->audit->old_values['key'] ?? $this->audit->auditable?->key ?? '';

        if (preg_match('/password|secret|token|credential/i', $key)
            || ($this->audit->auditable_type === AppSetting::class && $key === 'value' && preg_match('/secret|token|fingerprint/i', $settingKey))) {
            return 'Hidden for security';
        }

        if ($value === null || $value === '') {
            return 'Not set';
        }

        if ($key === 'camera_id') {
            return $this->cameraNames[$value] ?? 'Camera #'.$value;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($key === 'value' && in_array($settingKey, ['auth_manual_enabled', 'auth_google_enabled', 'auth_setup_complete'], true)) {
            return (string) $value === '1' ? 'Enabled' : 'Disabled';
        }

        if (in_array($key, ['status', 'from', 'to', 'capture_mode'], true) && is_string($value)) {
            return Str::headline($value);
        }

        if (is_string($value) && (str_ends_with($key, '_at') || $key === 'scheduled_for') && preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $value)) {
            try {
                return app(ApplicationSettingsService::class)->formatDateTime(Carbon::parse($value, 'UTC'), 'j M Y, H:i:s') ?? $value;
            } catch (\Exception) {
                return $value;
            }
        }

        return AuditLog::displayValue($value);
    }
}
