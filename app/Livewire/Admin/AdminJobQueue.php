<?php

namespace App\Livewire\Admin;

use App\Services\RecordingWorkerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class AdminJobQueue extends Component
{
    public int $jobLimit = 10;

    public int $failedJobLimit = 6;

    public string $queueConnection = '';

    public string $queueDriver = '';

    public bool $usesDatabaseQueue = false;

    public bool $jobsTableAvailable = false;

    public bool $failedJobsTableAvailable = false;

    public int $pendingJobTotal = 0;

    public int $failedJobTotal = 0;

    /**
     * @var array<int, array{queue: string, pending_count: int, failed_count: int, next_job_label: string|null, next_available_label: string|null, status_label: string, status_tone: string}>
     */
    public array $queueSummary = [];

    /**
     * @var array<int, array{id: int, queue: string, job_label: string, job_class: string, attempts: int, available_at_label: string, status_label: string, status_tone: string}>
     */
    public array $upcomingJobs = [];

    /**
     * @var array<int, array{id: int, uuid: string, queue: string, job_label: string, job_class: string, failed_at_label: string}>
     */
    public array $failedJobs = [];

    /**
     * @var array<string, mixed>
     */
    public array $worker = [];

    public ?string $statusMessage = null;

    public string $statusTone = 'neutral';

    public ?string $workerPressureMessage = null;

    public string $workerPressureTone = 'neutral';

    public function mount(RecordingWorkerService $workerService): void
    {
        $this->loadSnapshot($workerService);
    }

    public function refreshQueueSnapshot(RecordingWorkerService $workerService): void
    {
        $this->loadSnapshot($workerService);
    }

    public function retryFailedJob(int $failedJobId): void
    {
        $this->statusMessage = null;

        if (!$this->failedJobsTableAvailable) {
            $this->statusTone = 'warn';
            $this->statusMessage = 'The failed jobs table is not available on this environment.';

            return;
        }

        $failedJob = DB::table('failed_jobs')->where('id', $failedJobId)->first();

        if ($failedJob === null) {
            $this->statusTone = 'warn';
            $this->statusMessage = 'That failed job no longer exists. The panel has been refreshed.';
            $this->loadSnapshot(app(RecordingWorkerService::class));

            return;
        }

        try {
            $connection = trim((string) $failedJob->connection) !== ''
                ? (string) $failedJob->connection
                : (string) config('queue.default');

            Queue::connection($connection)->pushRaw((string) $failedJob->payload, (string) $failedJob->queue);

            DB::table('failed_jobs')->where('id', $failedJobId)->delete();

            $this->statusTone = 'good';
            $this->statusMessage = 'Queued the failed job for another attempt.';
        } catch (Throwable $exception) {
            $this->statusTone = 'alert';
            $this->statusMessage = 'Unable to retry the selected failed job: '.$exception->getMessage();
        }

        $this->loadSnapshot(app(RecordingWorkerService::class));
    }

    public function render(): View
    {
        return view('livewire.admin.admin-job-queue');
    }

    private function loadSnapshot(RecordingWorkerService $workerService): void
    {
        $this->queueConnection = (string) config('queue.default', '');
        $this->queueDriver = (string) config('queue.connections.'.$this->queueConnection.'.driver', '');
        $this->usesDatabaseQueue = $this->queueDriver === 'database';
        $this->jobsTableAvailable = Schema::hasTable('jobs');
        $this->failedJobsTableAvailable = Schema::hasTable('failed_jobs');

        $workerSnapshot = $workerService->snapshot();
        $this->worker = $workerSnapshot + [
            'status_tone' => $this->workerStatusTone($workerSnapshot),
            'status_label' => $this->workerStatusLabel($workerSnapshot),
        ];
        [$this->workerPressureTone, $this->workerPressureMessage] = $this->workerPressureState($workerSnapshot);

        if (!$this->usesDatabaseQueue || !$this->jobsTableAvailable) {
            $this->pendingJobTotal = 0;
            $this->failedJobTotal = $this->failedJobsTableAvailable ? (int) DB::table('failed_jobs')->count() : 0;
            $this->queueSummary = [];
            $this->upcomingJobs = [];
            $this->failedJobs = $this->failedJobsTableAvailable ? $this->loadFailedJobs() : [];

            return;
        }

        $pendingCounts = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as pending_count'))
            ->groupBy('queue')
            ->pluck('pending_count', 'queue')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $failedCounts = $this->failedJobsTableAvailable
            ? DB::table('failed_jobs')
                ->select('queue', DB::raw('COUNT(*) as failed_count'))
                ->groupBy('queue')
                ->pluck('failed_count', 'queue')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all()
            : [];

        $this->pendingJobTotal = array_sum($pendingCounts);
        $this->failedJobTotal = array_sum($failedCounts);
        $this->queueSummary = $this->buildQueueSummary($pendingCounts, $failedCounts, $this->worker['queue_names'] ?? []);
        $this->upcomingJobs = $this->loadUpcomingJobs();
        $this->failedJobs = $this->loadFailedJobs();
    }

    /**
     * @param  array<string, int>  $pendingCounts
     * @param  array<string, int>  $failedCounts
     * @param  array<int, string>  $workerQueues
     * @return array<int, array{queue: string, pending_count: int, failed_count: int, next_job_label: string|null, next_available_label: string|null, status_label: string, status_tone: string}>
     */
    private function buildQueueSummary(array $pendingCounts, array $failedCounts, array $workerQueues): array
    {
        $queues = array_values(array_unique(array_merge(array_keys($pendingCounts), array_keys($failedCounts), $workerQueues)));
        sort($queues);

        $nextJobs = DB::table('jobs')
            ->select('queue', 'payload', 'available_at')
            ->orderBy('available_at')
            ->orderBy('id')
            ->get()
            ->groupBy('queue')
            ->map(function ($jobs): array {
                $nextJob = $jobs->first();

                return [
                    'label' => $this->jobLabelFromPayload((string) $nextJob->payload),
                    'available_at_label' => $this->formatUnixTimestamp((int) $nextJob->available_at),
                ];
            })
            ->all();

        $rows = [];

        foreach ($queues as $queue) {
            $pendingCount = $pendingCounts[$queue] ?? 0;
            $failedCount = $failedCounts[$queue] ?? 0;
            $statusTone = 'neutral';
            $statusLabel = 'Idle';

            if ($failedCount > 0) {
                $statusTone = 'alert';
                $statusLabel = 'Failed';
            } elseif ($pendingCount > 0) {
                $statusTone = $pendingCount > ($this->worker['jobs_per_process'] ?? 200) ? 'warn' : 'neutral';
                $statusLabel = $pendingCount > ($this->worker['jobs_per_process'] ?? 200) ? 'Backlog' : 'Pending';
            }

            $rows[] = [
                'queue' => $queue,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'next_job_label' => $nextJobs[$queue]['label'] ?? null,
                'next_available_label' => $nextJobs[$queue]['available_at_label'] ?? null,
                'status_label' => $statusLabel,
                'status_tone' => $statusTone,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{id: int, queue: string, job_label: string, job_class: string, attempts: int, available_at_label: string, status_label: string, status_tone: string}>
     */
    private function loadUpcomingJobs(): array
    {
        return DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at'])
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($this->jobLimit)
            ->get()
            ->map(function (object $job): array {
                $availableAt = (int) $job->available_at;
                $reservedAt = $job->reserved_at === null ? null : (int) $job->reserved_at;
                $statusTone = 'neutral';
                $statusLabel = 'Pending';

                if ($reservedAt !== null) {
                    $statusTone = 'good';
                    $statusLabel = 'Running';
                } elseif ($availableAt > now()->timestamp) {
                    $statusTone = 'warn';
                    $statusLabel = 'Scheduled';
                }

                $jobClass = $this->jobClassFromPayload((string) $job->payload);

                return [
                    'id' => (int) $job->id,
                    'queue' => (string) $job->queue,
                    'job_label' => class_basename($jobClass),
                    'job_class' => $jobClass,
                    'attempts' => (int) $job->attempts,
                    'available_at_label' => $this->formatUnixTimestamp($availableAt),
                    'status_label' => $statusLabel,
                    'status_tone' => $statusTone,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, uuid: string, queue: string, job_label: string, job_class: string, failed_at_label: string}>
     */
    private function loadFailedJobs(): array
    {
        if (!$this->failedJobsTableAvailable) {
            return [];
        }

        return DB::table('failed_jobs')
            ->select(['id', 'uuid', 'queue', 'payload', 'failed_at'])
            ->orderByDesc('failed_at')
            ->limit($this->failedJobLimit)
            ->get()
            ->map(function (object $job): array {
                $jobClass = $this->jobClassFromPayload((string) $job->payload);

                return [
                    'id' => (int) $job->id,
                    'uuid' => (string) $job->uuid,
                    'queue' => (string) $job->queue,
                    'job_label' => class_basename($jobClass),
                    'job_class' => $jobClass,
                    'failed_at_label' => $this->formatDateTime((string) $job->failed_at),
                ];
            })
            ->values()
            ->all();
    }

    private function jobLabelFromPayload(string $payload): string
    {
        return class_basename($this->jobClassFromPayload($payload));
    }

    private function jobClassFromPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return 'UnknownJob';
        }

        $displayName = trim((string) data_get($decoded, 'displayName', ''));

        if ($displayName !== '' && $displayName !== 'Illuminate\\Queue\\CallQueuedHandler@call') {
            return $displayName;
        }

        $commandName = trim((string) data_get($decoded, 'data.commandName', ''));

        if ($commandName !== '') {
            return $commandName;
        }

        $jobName = trim((string) data_get($decoded, 'job', ''));

        if ($jobName !== '') {
            return Str::contains($jobName, '@') ? (string) Str::before($jobName, '@') : $jobName;
        }

        return 'UnknownJob';
    }

    /**
     * @param  array<string, mixed>  $workerSnapshot
     */
    private function workerStatusTone(array $workerSnapshot): string
    {
        $runningWorkers = (int) ($workerSnapshot['running_workers'] ?? 0);
        $desiredWorkers = (int) ($workerSnapshot['desired_workers'] ?? 1);

        if (!(bool) ($workerSnapshot['ensure_running'] ?? false)) {
            return 'warn';
        }

        if ($runningWorkers === 0) {
            return 'alert';
        }

        if ($runningWorkers < $desiredWorkers) {
            return 'warn';
        }

        return 'good';
    }

    /**
     * @param  array<string, mixed>  $workerSnapshot
     */
    private function workerStatusLabel(array $workerSnapshot): string
    {
        $runningWorkers = (int) ($workerSnapshot['running_workers'] ?? 0);
        $desiredWorkers = (int) ($workerSnapshot['desired_workers'] ?? 1);

        if (!(bool) ($workerSnapshot['ensure_running'] ?? false)) {
            return 'Supervision off';
        }

        if ($runningWorkers === 0) {
            return 'Workers down';
        }

        if ($runningWorkers < $desiredWorkers) {
            return 'Scaling';
        }

        return 'Healthy';
    }

    /**
     * @param  array<string, mixed>  $workerSnapshot
     * @return array{0: string, 1: string|null}
     */
    private function workerPressureState(array $workerSnapshot): array
    {
        $desiredWorkers = (int) ($workerSnapshot['desired_workers'] ?? 1);
        $maximumWorkers = max(1, (int) ($workerSnapshot['maximum_workers'] ?? 1));

        if (!(bool) ($workerSnapshot['dynamic_enabled'] ?? false)) {
            return ['warning', 'Dynamic worker scaling is disabled. The worker pool will stay fixed until you change the configured process count.'];
        }

        if ($desiredWorkers >= $maximumWorkers) {
            return [
                'warning',
                'Worker demand has reached the configured ceiling of '.$maximumWorkers.' processes. If backlog keeps growing, raise CAMERA_RECORDING_WORKER_MAX_PROCESSES or reduce per-job load.',
            ];
        }

        if ($maximumWorkers > 1 && $desiredWorkers >= max(1, (int) ceil($maximumWorkers * 0.75))) {
            return [
                'warning',
                'Worker demand is nearing the configured ceiling: '.$desiredWorkers.' of '.$maximumWorkers.' available worker slots are now targeted.',
            ];
        }

        return ['warning', null];
    }

    private function formatUnixTimestamp(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }

    private function formatDateTime(string $timestamp): string
    {
        return Carbon::parse($timestamp)->format('Y-m-d H:i:s');
    }
}