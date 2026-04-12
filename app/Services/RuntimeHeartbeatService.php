<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

class RuntimeHeartbeatService
{
    public function touchWorker(string $context = 'looping'): void
    {
        $this->writeHeartbeat($this->workerPath(), 'worker', $context);
    }

    public function touchScheduler(string $context = 'loop'): void
    {
        $this->writeHeartbeat($this->schedulerPath(), 'scheduler', $context);
    }

    public function touchRecordingTick(string $context = 'tick'): void
    {
        $this->writeHeartbeat($this->recordingTickPath(), 'recording-tick', $context);
    }

    /**
     * @return array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}
     */
    public function workerStatus(): array
    {
        return $this->status($this->workerPath());
    }

    /**
     * @return array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}
     */
    public function schedulerStatus(): array
    {
        return $this->status($this->schedulerPath());
    }

    /**
     * @return array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}
     */
    public function recordingTickStatus(): array
    {
        return $this->status($this->recordingTickPath());
    }

    public function workerPath(): string
    {
        if (!$this->workerContainerMode()) {
            return (string) config('recording.health.worker_heartbeat_path', storage_path('app/private/bootstrap/recordings-worker.heartbeat'));
        }

        return $this->workerHeartbeatDirectory().'/recordings-worker-'.$this->workerInstanceId().'.heartbeat';
    }

    public function schedulerPath(): string
    {
        return (string) config('recording.health.scheduler_heartbeat_path', storage_path('app/private/bootstrap/recordings-scheduler.heartbeat'));
    }

    public function recordingTickPath(): string
    {
        return (string) config('recording.health.scheduler_tick_heartbeat_path', storage_path('app/private/bootstrap/recordings-tick.heartbeat'));
    }

    /**
     * @return array<int, array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}>
     */
    public function workerStatuses(): array
    {
        if (!$this->workerContainerMode()) {
            return [$this->status($this->workerPath())];
        }

        $paths = glob($this->workerHeartbeatDirectory().'/recordings-worker-*.heartbeat');

        if ($paths === false || $paths === []) {
            return [$this->status($this->workerPath())];
        }

        sort($paths);

        return array_values(array_map(fn (string $path): array => $this->status($path), $paths));
    }

    /**
     * @return array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}
     */
    private function status(string $path): array
    {
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'updated_at' => null,
                'age_seconds' => null,
            ];
        }

        $timestamp = @filemtime($path);
        $updatedAt = is_int($timestamp) && $timestamp > 0
            ? Carbon::createFromTimestampUTC($timestamp)
            : $this->readHeartbeatTimestamp($path);

        return [
            'path' => $path,
            'exists' => true,
            'updated_at' => $updatedAt,
            'age_seconds' => $updatedAt instanceof Carbon
                ? (int) round($updatedAt->diffInSeconds(now()->utc()))
                : null,
        ];
    }

    private function writeHeartbeat(string $path, string $role, string $context): void
    {
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'role' => $role,
            'context' => $context,
            'pid' => getmypid(),
            'updated_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        @chmod($path, 0664);
    }

    private function readHeartbeatTimestamp(string $path): ?Carbon
    {
        $contents = @file_get_contents($path);

        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        $contents = trim($contents);
        $decoded = json_decode($contents, true);
        $value = is_array($decoded)
            ? ($decoded['updated_at'] ?? null)
            : $contents;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function workerContainerMode(): bool
    {
        return (bool) config('recording.worker.container_mode', false);
    }

    private function workerHeartbeatDirectory(): string
    {
        return dirname((string) config('recording.health.worker_heartbeat_path', storage_path('app/private/bootstrap/recordings-worker.heartbeat')));
    }

    private function workerInstanceId(): string
    {
        $candidate = trim((string) (env('HOSTNAME') ?: gethostname() ?: 'worker'));
        $candidate = preg_replace('/[^A-Za-z0-9._-]+/', '-', $candidate) ?: 'worker';

        return trim($candidate, '-.') !== '' ? trim($candidate, '-.') : 'worker';
    }
}