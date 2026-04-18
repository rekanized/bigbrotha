<?php

namespace App\Livewire\Admin;

use App\Services\FailedJobRetryService;
use App\Services\RecordingWorkerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public bool $autoRetryEnabled = false;

    public int $autoRetryMaxRetries = 0;

    public int $autoRetryBatchSize = 0;

    public int $autoRetryCooldownSeconds = 0;

    /**
     * @var array<int, array{queue: string, pending_count: int, failed_count: int, next_job_label: string|null, next_available_label: string|null, status_label: string, status_tone: string}>
     */
    public array $queueSummary = [];

    /**
     * @var array<int, array{id: int, queue: string, job_label: string, job_class: string, attempts: int, available_at_label: string, status_label: string, status_tone: string}>
     */
    public array $upcomingJobs = [];

    /**
    * @var array<int, array{id: int, uuid: string, queue: string, connection: string, job_label: string, job_class: string, failed_at_label: string, exception_excerpt: string, exception_trace: string, retry_status_label: string, retry_status_tone: string, retry_summary: string, next_retry_label: string|null}>
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

        $retryResult = app(FailedJobRetryService::class)->retryFailedJobById($failedJobId, automatic: false, force: true);

        if ($retryResult['status'] === 'missing') {
            $this->statusTone = 'warn';
            $this->statusMessage = 'That failed job no longer exists. The panel has been refreshed.';
            $this->loadSnapshot(app(RecordingWorkerService::class));

            return;
        }

        if ($retryResult['status'] === 'retried') {
            $this->statusTone = 'good';
            $this->statusMessage = 'Queued the failed job for another attempt.';
        } else {
            $this->statusTone = 'alert';
            $this->statusMessage = 'Unable to retry the selected failed job: '.($retryResult['message'] ?? 'The retry attempt failed.');
        }

        $this->loadSnapshot(app(RecordingWorkerService::class));
    }

    public function deleteFailedJob(int $failedJobId): void
    {
        $this->statusMessage = null;

        if (!$this->failedJobsTableAvailable) {
            $this->statusTone = 'warn';
            $this->statusMessage = 'The failed jobs table is not available on this environment.';

            return;
        }

        try {
            $deletedCount = DB::table('failed_jobs')->where('id', $failedJobId)->delete();

            if ($deletedCount === 0) {
                $this->statusTone = 'warn';
                $this->statusMessage = 'That failed job no longer exists. The panel has been refreshed.';
                $this->loadSnapshot(app(RecordingWorkerService::class));

                return;
            }

            $this->statusTone = 'good';
            $this->statusMessage = 'Deleted the selected failed job.';
        } catch (Throwable $exception) {
            $this->statusTone = 'alert';
            $this->statusMessage = 'Unable to delete the selected failed job: '.$exception->getMessage();
        }

        $this->loadSnapshot(app(RecordingWorkerService::class));
    }

    public function clearFailedJobs(): void
    {
        $this->statusMessage = null;

        if (!$this->failedJobsTableAvailable) {
            $this->statusTone = 'warn';
            $this->statusMessage = 'The failed jobs table is not available on this environment.';

            return;
        }

        try {
            $deletedCount = DB::table('failed_jobs')->delete();

            if ($deletedCount === 0) {
                $this->statusTone = 'warn';
                $this->statusMessage = 'There were no failed jobs left to delete. The panel has been refreshed.';
                $this->loadSnapshot(app(RecordingWorkerService::class));

                return;
            }

            $this->statusTone = 'good';
            $this->statusMessage = 'Deleted '.$deletedCount.' failed job record'.($deletedCount === 1 ? '' : 's').'.';
        } catch (Throwable $exception) {
            $this->statusTone = 'alert';
            $this->statusMessage = 'Unable to clear failed jobs: '.$exception->getMessage();
        }

        $this->loadSnapshot(app(RecordingWorkerService::class));
    }

    public function render(): View
    {
        return view('livewire.admin.admin-job-queue');
    }

    private function loadSnapshot(RecordingWorkerService $workerService): void
    {
        $retryService = app(FailedJobRetryService::class);

        $this->queueConnection = (string) config('queue.default', '');
        $this->queueDriver = (string) config('queue.connections.'.$this->queueConnection.'.driver', '');
        $this->usesDatabaseQueue = $this->queueDriver === 'database';
        $this->jobsTableAvailable = Schema::hasTable('jobs');
        $this->failedJobsTableAvailable = Schema::hasTable('failed_jobs');
        $this->autoRetryEnabled = $retryService->autoRetryEnabled();
        $this->autoRetryMaxRetries = $retryService->maxAutoRetries();
        $this->autoRetryBatchSize = $retryService->autoRetryBatchSize();
        $this->autoRetryCooldownSeconds = $retryService->autoRetryCooldownSeconds();

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
            $this->failedJobs = $this->failedJobsTableAvailable ? $this->loadFailedJobs($retryService) : [];

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
        $this->failedJobs = $this->loadFailedJobs($retryService);
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
     * @return array<int, array{id: int, uuid: string, queue: string, connection: string, job_label: string, job_class: string, failed_at_label: string, exception_excerpt: string, exception_trace: string, retry_status_label: string, retry_status_tone: string, retry_summary: string, next_retry_label: string|null}>
     */
    private function loadFailedJobs(FailedJobRetryService $retryService): array
    {
        if (!$this->failedJobsTableAvailable) {
            return [];
        }

        return DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->limit($this->failedJobLimit)
            ->get()
            ->map(function (object $job) use ($retryService): array {
                $jobClass = $this->jobClassFromPayload((string) $job->payload);
                $retryMeta = $retryService->retryMetadataFromPayload((string) $job->payload);
                $failedAt = Carbon::parse((string) $job->failed_at);
                $retryState = $this->failedJobRetryState($retryMeta, $failedAt);

                return [
                    'id' => (int) $job->id,
                    'uuid' => (string) $job->uuid,
                    'connection' => trim((string) $job->connection) !== '' ? (string) $job->connection : (string) config('queue.default', 'unknown'),
                    'queue' => (string) $job->queue,
                    'job_label' => class_basename($jobClass),
                    'job_class' => $jobClass,
                    'failed_at_label' => $failedAt->format('Y-m-d H:i:s'),
                    'exception_excerpt' => $this->failedJobExceptionExcerpt((string) $job->exception),
                    'exception_trace' => $this->failedJobTracePreview((string) $job->exception),
                    'retry_status_label' => $retryState['label'],
                    'retry_status_tone' => $retryState['tone'],
                    'retry_summary' => $this->failedJobRetrySummary($retryMeta),
                    'next_retry_label' => $retryState['next_retry_label'],
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

        if ($runningWorkers === 0) {
            return 'Workers down';
        }

        if ($runningWorkers < $desiredWorkers) {
            return 'Degraded';
        }

        return 'Healthy';
    }

    /**
     * @param  array<string, mixed>  $workerSnapshot
     * @return array{0: string, 1: string|null}
     */
    private function workerPressureState(array $workerSnapshot): array
    {
        if (!(bool) ($workerSnapshot['dynamic_enabled'] ?? false)) {
            return ['neutral', null];
        }

        $desiredWorkers = max(1, (int) ($workerSnapshot['desired_workers'] ?? 1));
        $minimumWorkers = max(1, (int) ($workerSnapshot['minimum_workers'] ?? $desiredWorkers));
        $maximumWorkers = max($minimumWorkers, (int) ($workerSnapshot['maximum_workers'] ?? $desiredWorkers));

        if ($desiredWorkers >= $maximumWorkers) {
            return [
                'warning',
                'Worker demand has reached the configured ceiling of '.$maximumWorkers.' process'.($maximumWorkers === 1 ? '' : 'es').'.',
            ];
        }

        if ($desiredWorkers > $minimumWorkers) {
            return [
                'warning',
                'Worker demand currently requires '.$desiredWorkers.' process'.($desiredWorkers === 1 ? '' : 'es').' against the base capacity of '.$minimumWorkers.'.',
            ];
        }

        return ['neutral', null];
    }

    private function formatUnixTimestamp(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }

    private function formatDateTime(string $timestamp): string
    {
        return Carbon::parse($timestamp)->format('Y-m-d H:i:s');
    }

    private function failedJobExceptionExcerpt(string $exception): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($exception)) ?: [];
        $firstLine = trim((string) ($lines[0] ?? ''));

        if ($firstLine === '') {
            return 'No exception details were recorded for this failed job.';
        }

        return Str::limit((string) preg_replace('/\s+/', ' ', $firstLine), 220);
    }

    private function failedJobTracePreview(string $exception): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($exception)) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        return implode(PHP_EOL, array_slice($lines, 0, 6));
    }

    /**
     * @param  array{total_retries: int, auto_retries: int, manual_retries: int, last_retry_type: string|null, last_retry_at: string|null}  $retryMeta
     */
    private function failedJobRetrySummary(array $retryMeta): string
    {
        $summary = $retryMeta['total_retries'] === 0
            ? 'No requeue attempts have been made yet.'
            : 'Requeued '.$retryMeta['total_retries'].' time'.($retryMeta['total_retries'] === 1 ? '' : 's').': auto '.$retryMeta['auto_retries'].', manual '.$retryMeta['manual_retries'].'.';

        if ($retryMeta['last_retry_type'] !== null && $retryMeta['last_retry_at'] !== null) {
            $summary .= ' Last '.($retryMeta['last_retry_type'] === 'auto' ? 'automatic' : 'manual').' retry at '.$this->formatDateTime($retryMeta['last_retry_at']).'.';
        }

        return $summary;
    }

    /**
     * @param  array{total_retries: int, auto_retries: int, manual_retries: int, last_retry_type: string|null, last_retry_at: string|null}  $retryMeta
     * @return array{label: string, tone: string, next_retry_label: string|null}
     */
    private function failedJobRetryState(array $retryMeta, Carbon $failedAt): array
    {
        if (!$this->autoRetryEnabled || $this->autoRetryMaxRetries === 0) {
            return [
                'label' => 'Manual only',
                'tone' => 'neutral',
                'next_retry_label' => null,
            ];
        }

        if ($retryMeta['total_retries'] >= $this->autoRetryMaxRetries) {
            return [
                'label' => 'Limit reached',
                'tone' => 'alert',
                'next_retry_label' => null,
            ];
        }

        $nextRetryAt = $failedAt->copy()->addSeconds($this->autoRetryCooldownSeconds);

        return [
            'label' => $nextRetryAt->isFuture() ? 'Pending retry' : 'Eligible now',
            'tone' => $nextRetryAt->isFuture() ? 'warn' : 'neutral',
            'next_retry_label' => $nextRetryAt->format('Y-m-d H:i:s'),
        ];
    }
}