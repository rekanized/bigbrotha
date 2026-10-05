<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Audit\AuditContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

trait Auditable
{
    /**
     * @var array{old: array<string, mixed>, new: array<string, mixed>}|array{}
     */
    protected array $pendingAuditUpdate = [];

    /**
     * @var array<string, bool>
     */
    protected array $suppressedAuditEvents = [];

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAutoAuditLog('created', [], $model->auditCurrentValues());
        });

        static::updating(function (Model $model): void {
            $model->cacheAuditUpdate();
        });

        static::updated(function (Model $model): void {
            $model->flushAuditUpdate();
        });

        static::deleting(function (Model $model): void {
            $model->writeAutoAuditLog('deleted', $model->auditCurrentValues(), []);
        });
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function suppressNextAuditEvent(string $event): static
    {
        $this->suppressedAuditEvents[$event] = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function auditDeltaFor(array $attributes): array
    {
        $keys = $this->auditFilterKeys(array_keys($attributes));

        if ($keys === []) {
            return ['old' => [], 'new' => []];
        }

        $currentValues = Arr::only($this->auditCurrentValues(), $keys);
        $pendingValues = Arr::only($this->auditSnapshotWith($attributes)->auditCurrentValues(), $keys);

        $oldValues = [];
        $newValues = [];

        foreach ($keys as $key) {
            $before = $currentValues[$key] ?? null;
            $after = $pendingValues[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $oldValues[$key] = $before;
            $newValues[$key] = $after;
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function writeAuditLog(string $event, array $oldValues = [], array $newValues = [], array $metadata = []): ?AuditLog
    {
        $oldValues = $this->auditFilterPayload($oldValues);
        $newValues = $this->auditFilterPayload($newValues);
        $metadata = $this->auditFilterEmptyValues($metadata);

        if ($oldValues === [] && $newValues === [] && $metadata === []) {
            return null;
        }

        $context = app(AuditContextResolver::class)->resolve();

        return AuditLog::query()->create(array_merge($context, [
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now()->utc(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    protected function writeAutoAuditLog(string $event, array $oldValues, array $newValues): ?AuditLog
    {
        if ($this->consumeSuppressedAuditEvent($event)) {
            return null;
        }

        return $this->writeAuditLog($event, $oldValues, $newValues);
    }

    protected function cacheAuditUpdate(): void
    {
        $keys = $this->auditFilterKeys(array_keys($this->getDirty()));

        if ($keys === []) {
            $this->pendingAuditUpdate = [];

            return;
        }

        $oldValues = Arr::only($this->auditSnapshotFromRaw($this->getOriginal())->auditCurrentValues(), $keys);
        $newValues = Arr::only($this->auditCurrentValues(), $keys);

        $delta = [
            'old' => $oldValues,
            'new' => $newValues,
        ];

        $this->pendingAuditUpdate = ($delta['old'] === [] && $delta['new'] === [])
            ? []
            : $delta;
    }

    protected function flushAuditUpdate(): void
    {
        if ($this->pendingAuditUpdate === []) {
            return;
        }

        $this->writeAutoAuditLog('updated', $this->pendingAuditUpdate['old'], $this->pendingAuditUpdate['new']);

        $this->pendingAuditUpdate = [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditCurrentValues(): array
    {
        return $this->auditFilterPayload($this->attributesToArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return $this->auditCurrentValues();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function auditSnapshotWith(array $attributes): Model
    {
        $snapshot = clone $this;
        $snapshot->forceFill($attributes);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function auditSnapshotFromRaw(array $attributes): Model
    {
        $snapshot = $this->newInstance([], $this->exists);
        $snapshot->setRawAttributes($attributes, true);

        return $snapshot;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    protected function auditFilterKeys(array $keys): array
    {
        return array_values(array_diff($keys, $this->auditExcludedAttributes()));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function auditFilterPayload(array $payload): array
    {
        return $this->auditFilterEmptyValues(Arr::except($payload, $this->auditExcludedAttributes()));
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcludedAttributes(): array
    {
        $excluded = [
            'created_at',
            'updated_at',
            'deleted_at',
            'password',
            'remember_token',
        ];

        if (property_exists($this, 'auditExclude') && is_array($this->auditExclude)) {
            $excluded = array_merge($excluded, $this->auditExclude);
        }

        $hidden = method_exists($this, 'getHidden') ? $this->getHidden() : [];

        return array_values(array_unique(array_merge($excluded, $hidden)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function auditFilterEmptyValues(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== []);
    }

    protected function consumeSuppressedAuditEvent(string $event): bool
    {
        if (! ($this->suppressedAuditEvents[$event] ?? false)) {
            return false;
        }

        unset($this->suppressedAuditEvents[$event]);

        return true;
    }
}
