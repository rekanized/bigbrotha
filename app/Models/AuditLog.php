<?php

namespace App\Models;

use App\Services\ApplicationSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use MassPrunable;

    public const ACTOR_TYPE_USER = 'user';

    public const ACTOR_TYPE_SYSTEM = 'system';

    public const SOURCE_HTTP = 'http';

    public const SOURCE_QUEUE = 'queue';

    public const SOURCE_CONSOLE = 'console';

    public const SOURCE_SYSTEM = 'system';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<=', now()->utc()->subDays(app(ApplicationSettingsService::class)->auditRetentionDays()));
    }

    /**
     * @return array<string, string>
     */
    public static function actorTypeLabels(): array
    {
        return [
            self::ACTOR_TYPE_USER => 'User',
            self::ACTOR_TYPE_SYSTEM => 'System',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_HTTP => 'HTTP',
            self::SOURCE_QUEUE => 'Queue',
            self::SOURCE_CONSOLE => 'Console',
            self::SOURCE_SYSTEM => 'System',
        ];
    }

    public static function eventLabelFor(string $event): string
    {
        return match ($event) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'recording.state_transition' => 'Recording state transition',
            'recording.transient_discarded' => 'Transient motion row discarded',
            default => Str::of($event)->replace(['.', '_'], ' ')->headline()->toString(),
        };
    }

    public static function subjectTypeLabelFor(?string $type): string
    {
        return match ($type) {
            CameraRecording::class => 'Recording',
            User::class => 'User',
            AllowedLoginEmail::class => 'Allowed sign-in email',
            AppSetting::class => 'Application setting',
            Camera::class => 'Camera',
            LiveWall::class => 'Live wall',
            LiveWallTile::class => 'Live wall tile',
            null, '' => 'Unknown subject',
            default => Str::headline(class_basename($type)),
        };
    }

    public static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unserializable]',
        };
    }

    public function actorTypeLabel(): string
    {
        return self::actorTypeLabels()[$this->actor_type] ?? Str::headline($this->actor_type);
    }

    public function sourceLabel(): string
    {
        return self::sourceLabels()[$this->source] ?? Str::headline($this->source);
    }

    public function eventLabel(): string
    {
        return self::eventLabelFor($this->event);
    }

    public function subjectTypeLabel(): string
    {
        return self::subjectTypeLabelFor($this->auditable_type);
    }

    public function subjectReference(): string
    {
        $auditable = $this->auditable;

        return match (true) {
            $auditable instanceof AllowedLoginEmail => $auditable->email,
            $auditable instanceof AppSetting => (string) $auditable->key,
            $auditable instanceof Camera => $auditable->name ?: 'Camera #'.$this->auditable_id,
            $auditable instanceof CameraRecording => 'Recording #'.$this->auditable_id,
            $auditable instanceof LiveWall => $auditable->name ?: 'Wall #'.$this->auditable_id,
            $auditable instanceof LiveWallTile => 'Tile #'.$this->auditable_id,
            $auditable instanceof User => trim((string) ($auditable->name ?: $auditable->email)) ?: 'User #'.$this->auditable_id,
            default => $this->subjectFallbackReference(),
        };
    }

    /**
     * @return array<int, string>
     */
    public function changeKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->old_values ?? []),
            array_keys($this->new_values ?? []),
        )));
    }

    protected function subjectFallbackReference(): string
    {
        foreach (['name', 'email', 'key'] as $key) {
            $value = $this->new_values[$key] ?? $this->old_values[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->subjectTypeLabel().' #'.$this->auditable_id;
    }
}
