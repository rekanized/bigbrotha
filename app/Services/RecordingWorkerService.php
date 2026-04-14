<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class RecordingWorkerService
{
    public function __construct(
        private readonly RuntimeHeartbeatService $heartbeats,
    ) {
    }

    /**
     * @return array{
     *     running: bool,
     *     running_workers: int,
     *     desired_workers: int,
     *     running_pids: array<int>,
     *     queue_names: array<int, string>,
     *     ensure_running: bool,
     *     dynamic_enabled: bool,
     *     minimum_workers: int,
     *     maximum_workers: int,
     *     cameras_per_process: int,
     *     jobs_per_process: int,
     *     enabled_recording_cameras: int,
     *     queued_worker_jobs: int
     * }
     */
    public function snapshot(): array
    {
        $runningPids = $this->runningPids();
        $runningWorkers = count($runningPids);

        if ($runningWorkers === 0) {
            $runningWorkers = $this->externalRunningWorkerCount();
        }

        $desiredWorkers = $this->desiredWorkerCount();
        $minimumWorkers = max(1, (int) config('recording.worker.processes', 1));
        $dynamicEnabled = (bool) config('recording.worker.dynamic_enabled', false);
        $maximumWorkers = $dynamicEnabled
            ? max($minimumWorkers, (int) config('recording.worker.max_processes', $minimumWorkers))
            : $minimumWorkers;

        return [
            'running' => $runningWorkers > 0,
            'running_workers' => $runningWorkers,
            'desired_workers' => $desiredWorkers,
            'running_pids' => $runningPids,
            'queue_names' => $this->workerQueueList(),
            'ensure_running' => (bool) config('recording.worker.ensure_running', true),
            'dynamic_enabled' => $dynamicEnabled,
            'minimum_workers' => $minimumWorkers,
            'maximum_workers' => $maximumWorkers,
            'cameras_per_process' => max(1, (int) config('recording.worker.cameras_per_process', 4)),
            'jobs_per_process' => max(1, (int) config('recording.worker.jobs_per_process', 200)),
            'enabled_recording_cameras' => $this->enabledRecordingCameraCount(),
            'queued_worker_jobs' => $this->queuedWorkerJobsCount(),
        ];
    }

    public function isRunning(): bool
    {
        return $this->runningPid() !== null;
    }

    public function runningPid(): ?int
    {
        return $this->runningPids()[0] ?? null;
    }

    /**
     * @return array<int>
     */
    public function runningPids(): array
    {
        $pids = [];
        $process = new Process($this->processSnapshotCommand());
        $process->setTimeout(2);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            [$pid, $args] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
            $resolvedPid = (int) ($pid ?? 0);

            if ($resolvedPid < 1 || !is_string($args)) {
                continue;
            }

            if (!$this->argsMatchWorker($args)) {
                continue;
            }

            if (!$this->cwdMatchesBasePath($resolvedPid)) {
                continue;
            }

            if ($this->processIsRunning($resolvedPid)) {
                $pids[] = $resolvedPid;
            }
        }

        sort($pids);

        return array_values(array_unique($pids));
    }

    private function argsMatchWorker(string $args): bool
    {
        if (!str_contains($args, 'artisan queue:work')) {
            return false;
        }

        return str_contains($args, '--queue='.$this->workerQueues())
            || str_contains($args, '--queue '.$this->workerQueues());
    }

    private function cwdMatchesBasePath(int $pid): bool
    {
        $cwdPath = '/proc/'.$pid.'/cwd';

        if (!is_link($cwdPath)) {
            return true;
        }

        $cwd = @readlink($cwdPath);

        if (!is_string($cwd) || $cwd === '') {
            return true;
        }

        return realpath($cwd) === realpath(base_path());
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            return posix_get_last_error() === 1;
        }

        return is_dir('/proc/'.$pid);
    }

    private function desiredWorkerCount(): int
    {
        $minimumWorkers = max(1, (int) config('recording.worker.processes', 1));

        if (!(bool) config('recording.worker.dynamic_enabled', false)) {
            return $minimumWorkers;
        }

        $maximumWorkers = max($minimumWorkers, (int) config('recording.worker.max_processes', $minimumWorkers));
        $cameraDemand = (int) ceil($this->enabledRecordingCameraCount() / max(1, (int) config('recording.worker.cameras_per_process', 4)));
        $jobDemand = (int) ceil($this->queuedWorkerJobsCount() / max(1, (int) config('recording.worker.jobs_per_process', 200)));

        return min($maximumWorkers, max($minimumWorkers, $cameraDemand, $jobDemand));
    }

    private function enabledRecordingCameraCount(): int
    {
        if (!Schema::hasTable('cameras')) {
            return 0;
        }

        return Camera::query()
            ->where('is_enabled', true)
            ->where('supports_rtsp', true)
            ->whereIn('recording_mode', [Camera::RECORDING_MODE_CONTINUOUS, Camera::RECORDING_MODE_MOTION])
            ->count();
    }

    private function queuedWorkerJobsCount(): int
    {
        if (!Schema::hasTable('jobs')) {
            return 0;
        }

        return DB::table('jobs')
            ->whereIn('queue', $this->workerQueueList())
            ->count();
    }

    private function workerQueues(): string
    {
        return (string) config('recording.worker.queue', config('recording.queue', 'recordings').',default');
    }

    /**
     * @return array<int, string>
     */
    private function workerQueueList(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $queue): string => trim($queue),
            explode(',', $this->workerQueues())
        ), static fn (string $queue): bool => $queue !== ''));
    }

    private function externalRunningWorkerCount(): int
    {
        $maxAgeSeconds = max(60, (int) config('recording.health.worker_max_age_seconds', 360));

        return count(array_filter(
            $this->heartbeats->workerStatuses(),
            static fn (array $status): bool => ($status['exists'] ?? false)
                && is_int($status['age_seconds'] ?? null)
                && $status['age_seconds'] <= $maxAgeSeconds,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function processSnapshotCommand(): array
    {
        $binary = trim((string) config('recording.worker.ps_binary', 'ps'));

        if ($binary === '') {
            $binary = 'ps';
        }

        return basename($binary) === 'ps'
            ? [$binary, '-eo', 'pid=,args=']
            : [$binary];
    }
}