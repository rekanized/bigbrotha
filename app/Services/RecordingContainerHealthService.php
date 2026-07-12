<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class RecordingContainerHealthService
{
    public function __construct(
        private readonly RecordingWorkerService $workers,
        private readonly RuntimeHeartbeatService $heartbeats,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     checks: array<int, array{name: string, ok: bool, message: string}>
     * }
     */
    public function check(string $role): array
    {
        $role = strtolower(trim($role));

        if (!in_array($role, ['app', 'worker', 'scheduler'], true)) {
            throw new InvalidArgumentException('Role must be one of: app, worker, scheduler.');
        }

        $checks = [
            $this->checkBootstrapMarker(),
            $this->checkDatabaseConnection(),
            ...$this->checkWritablePaths($role),
        ];

        if ($role === 'worker') {
            $checks[] = $this->checkWorkerProcess();
            $checks[] = $this->checkWorkerHeartbeat();
            $checks[] = $this->checkQueuedRecordingAge();
        }

        if ($role === 'scheduler') {
            $checks[] = $this->checkSchedulerHeartbeat();
            $checks[] = $this->checkRecordingTickHeartbeat();
        }

        $failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));

        return [
            'ok' => $failed === [],
            'summary' => $failed === []
                ? ucfirst($role).' container health checks passed.'
                : $failed[0]['message'],
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkBootstrapMarker(): array
    {
        $path = $this->bootstrapMarkerPath();

        return is_file($path)
            ? $this->ok('bootstrap_marker', 'Bootstrap marker is present at '.$path.'.')
            : $this->fail('bootstrap_marker', 'Bootstrap marker is missing at '.$path.'.');
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkDatabaseConnection(): array
    {
        try {
            DB::connection()->select('select 1');

            return $this->ok('database', 'Database connection is reachable.');
        } catch (Throwable $exception) {
            return $this->fail('database', 'Database connection check failed: '.$exception->getMessage());
        }
    }

    /**
     * @return array<int, array{name: string, ok: bool, message: string}>
     */
    private function checkWritablePaths(string $role): array
    {
        $paths = [
            'storage_logs' => storage_path('logs'),
            'bootstrap_runtime' => storage_path('app/private/bootstrap'),
            'ffmpeg_temp' => trim((string) config('ffmpeg.temporary_directory', storage_path('app/private/ffmpeg-temp'))),
            'motion_runtime' => trim((string) config('recording.motion.runtime_dir', storage_path('app/private/motion-recorders'))),
            'continuous_runtime' => trim((string) config('recording.continuous.runtime_dir', storage_path('app/private/continuous-recorders'))),
        ];

        if ($role === 'app') {
            $paths['mediamtx_runtime'] = storage_path('app/private/mediamtx');
        }

        $checks = [];

        foreach ($paths as $name => $path) {
            if ($path === '') {
                continue;
            }

            $checks[] = $this->checkWritableDirectory($name, $path);
        }

        return $checks;
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkWorkerProcess(): array
    {
        $snapshot = $this->workers->snapshot();

        if ($snapshot['running_workers'] >= $snapshot['desired_workers']) {
            return $this->ok(
                'worker_process',
                'Detected '.$snapshot['running_workers'].' of '.$snapshot['desired_workers'].' required recordings queue worker process'.($snapshot['desired_workers'] === 1 ? '' : 'es').'.'
            );
        }

        return $this->fail(
            'worker_process',
            'Detected '.$snapshot['running_workers'].' of '.$snapshot['desired_workers'].' required recordings queue worker process'.($snapshot['desired_workers'] === 1 ? '' : 'es').'.'
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkWorkerHeartbeat(): array
    {
        if ((bool) config('recording.worker.container_mode', false)) {
            return $this->checkWorkerHeartbeats();
        }

        return $this->checkHeartbeat(
            name: 'worker_heartbeat',
            label: 'Worker heartbeat',
            status: $this->heartbeats->workerStatus(),
            maxAgeSeconds: max(60, (int) config('recording.health.worker_max_age_seconds', 360)),
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkWorkerHeartbeats(): array
    {
        $statuses = $this->heartbeats->workerStatuses();
        $maxAgeSeconds = max(60, (int) config('recording.health.worker_max_age_seconds', 360));
        $requiredCount = max(1, (int) config('recording.worker.processes', 1));
        $freshCount = count(array_filter(
            $statuses,
            static fn (array $status): bool => ($status['exists'] ?? false)
                && is_int($status['age_seconds'] ?? null)
                && $status['age_seconds'] <= $maxAgeSeconds,
        ));

        if ($freshCount >= $requiredCount) {
            return $this->ok(
                'worker_heartbeat',
                'Detected '.$freshCount.' of '.$requiredCount.' required fresh worker heartbeat file'.($requiredCount === 1 ? '' : 's').'.'
            );
        }

        $firstStatus = $statuses[0] ?? [
            'path' => (string) config('recording.health.worker_heartbeat_path', storage_path('app/private/bootstrap/recordings-worker.heartbeat')),
            'exists' => false,
            'updated_at' => null,
            'age_seconds' => null,
        ];

        if (!($firstStatus['exists'] ?? false)) {
            return $this->fail('worker_heartbeat', 'No shared worker heartbeat files were found under '.dirname((string) $firstStatus['path']).'.');
        }

        return $this->fail(
            'worker_heartbeat',
            'Detected '.$freshCount.' of '.$requiredCount.' required fresh worker heartbeat file'.($requiredCount === 1 ? '' : 's').' within the configured worker health window.'
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkSchedulerHeartbeat(): array
    {
        return $this->checkHeartbeat(
            name: 'scheduler_heartbeat',
            label: 'Scheduler heartbeat',
            status: $this->heartbeats->schedulerStatus(),
            maxAgeSeconds: max(60, (int) config('recording.health.scheduler_max_age_seconds', 180)),
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkRecordingTickHeartbeat(): array
    {
        return $this->checkHeartbeat(
            name: 'recording_tick_heartbeat',
            label: 'Recording tick heartbeat',
            status: $this->heartbeats->recordingTickStatus(),
            maxAgeSeconds: max(90, (int) config('recording.health.scheduler_tick_max_age_seconds', 240)),
        );
    }

    /**
     * @param  array{path: string, exists: bool, updated_at: Carbon|null, age_seconds: int|null}  $status
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkHeartbeat(string $name, string $label, array $status, int $maxAgeSeconds): array
    {
        if (!$status['exists']) {
            return $this->fail($name, $label.' file is missing at '.$status['path'].'.');
        }

        if (!is_int($status['age_seconds'])) {
            return $this->fail($name, $label.' at '.$status['path'].' does not have a readable modification time.');
        }

        if ($status['age_seconds'] > $maxAgeSeconds) {
            $updatedAt = $status['updated_at'] instanceof Carbon
                ? $status['updated_at']->toIso8601String()
                : 'unknown';

            return $this->fail(
                $name,
                $label.' is stale: last update was '.$updatedAt.' ('.$status['age_seconds'].' seconds ago, max '.$maxAgeSeconds.').'
            );
        }

        return $this->ok(
            $name,
            $label.' is fresh ('.$status['age_seconds'].' seconds old, max '.$maxAgeSeconds.').'
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkQueuedRecordingAge(): array
    {
        if (!Schema::hasTable('jobs')) {
            return $this->ok('recordings_queue_age', 'Jobs table is not available yet; skipping queued recordings age check.');
        }

        $recordingsQueue = (string) config('recording.queue', 'recordings');
        $row = DB::table('jobs')
            ->where('queue', $recordingsQueue)
            ->whereNull('reserved_at')
            ->selectRaw('COUNT(*) as queued_count, MIN(COALESCE(available_at, created_at)) as oldest_epoch')
            ->first();

        $queuedCount = (int) ($row->queued_count ?? 0);

        if ($queuedCount < 1) {
            return $this->ok('recordings_queue_age', 'No queued recordings jobs are waiting in the database queue.');
        }

        $oldestEpoch = (int) ($row->oldest_epoch ?? 0);

        if ($oldestEpoch < 1) {
            return $this->fail('recordings_queue_age', 'Queued recordings jobs exist, but their oldest timestamp could not be determined.');
        }

        $oldestAt = Carbon::createFromTimestampUTC($oldestEpoch);
        $ageSeconds = $oldestAt->diffInSeconds(now()->utc());
        $maxAgeSeconds = max(120, (int) config('recording.health.worker_max_queued_age_seconds', 600));

        if ($ageSeconds > $maxAgeSeconds) {
            return $this->fail(
                'recordings_queue_age',
                'Oldest queued recordings job is '.$ageSeconds.' seconds old across '.$queuedCount.' queued job'.($queuedCount === 1 ? '' : 's').'; max allowed age is '.$maxAgeSeconds.'.'
            );
        }

        return $this->ok(
            'recordings_queue_age',
            'Oldest queued recordings job is '.$ageSeconds.' seconds old across '.$queuedCount.' queued job'.($queuedCount === 1 ? '' : 's').'.'
        );
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function checkWritableDirectory(string $name, string $path): array
    {
        try {
            File::ensureDirectoryExists($path);

            $probePath = rtrim($path, '/').'/.healthcheck-'.str_replace('_', '-', $name).'-'.bin2hex(random_bytes(4));

            if (@file_put_contents($probePath, 'ok') === false) {
                return $this->fail($name, 'Directory is not writable: '.$path.'.');
            }

            @unlink($probePath);

            return $this->ok($name, 'Directory is writable: '.$path.'.');
        } catch (Throwable $exception) {
            return $this->fail($name, 'Unable to validate writable directory '.$path.': '.$exception->getMessage());
        }
    }

    private function bootstrapMarkerPath(): string
    {
        return (string) env('APP_BOOTSTRAP_MARKER', storage_path('app/private/bootstrap/app.ready'));
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function ok(string $name, string $message): array
    {
        return [
            'name' => $name,
            'ok' => true,
            'message' => $message,
        ];
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function fail(string $name, string $message): array
    {
        return [
            'name' => $name,
            'ok' => false,
            'message' => $message,
        ];
    }
}
