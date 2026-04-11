<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class RecordingWorkerService
{
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
        $minimumWorkers = max(1, (int) config('recording.worker.processes', 1));

        return [
            'running' => $runningPids !== [],
            'running_workers' => count($runningPids),
            'desired_workers' => $this->desiredWorkerCount(),
            'running_pids' => $runningPids,
            'queue_names' => $this->workerQueueList(),
            'ensure_running' => (bool) config('recording.worker.ensure_running', false),
            'dynamic_enabled' => (bool) config('recording.worker.dynamic_enabled', true),
            'minimum_workers' => $minimumWorkers,
            'maximum_workers' => max($minimumWorkers, (int) config('recording.worker.max_processes', $minimumWorkers)),
            'cameras_per_process' => max(1, (int) config('recording.worker.cameras_per_process', 4)),
            'jobs_per_process' => max(1, (int) config('recording.worker.jobs_per_process', 200)),
            'enabled_recording_cameras' => $this->enabledRecordingCameraCount(),
            'queued_worker_jobs' => $this->queuedWorkerJobsCount(),
        ];
    }

    /**
     * @return array{ok: bool, wrote: bool, enabled: bool, started: bool, message: string, path: string}
     */
    public function installSystemdUserService(bool $start = true): array
    {
        $paths = [];
        $wrote = false;

        try {
            foreach ($this->systemdServiceNames() as $serviceName) {
                $path = $this->systemdServicePath($serviceName);
                $contents = $this->systemdServiceContents();

                File::ensureDirectoryExists(dirname($path));

                $existingContents = is_file($path) ? file_get_contents($path) : false;
                $serviceWrote = !is_string($existingContents) || $existingContents !== $contents;

                if ($serviceWrote) {
                    File::put($path, $contents);
                }

                $wrote = $wrote || $serviceWrote;
                $paths[] = $path;
            }

            $reload = new Process([
                $this->systemctlBinary(),
                '--user',
                'daemon-reload',
            ], base_path(), $this->systemdEnvironment());
            $reload->setTimeout(15);
            $reload->run();

            if (!$reload->isSuccessful()) {
                return [
                    'ok' => false,
                    'wrote' => $wrote,
                    'enabled' => false,
                    'started' => false,
                    'message' => 'Wrote the recordings worker service file'.($this->desiredWorkerCount() === 1 ? '' : 's').', but systemd daemon-reload failed: '.trim($reload->getErrorOutput() ?: $reload->getOutput()),
                    'path' => implode(', ', $paths),
                ];
            }

            $enableCommand = [$this->systemctlBinary(), '--user', 'enable'];

            if ($start) {
                $enableCommand[] = '--now';
            }

            $enableCommand = array_merge($enableCommand, $this->systemdServiceNames());

            $enable = new Process(
                $enableCommand,
                base_path(),
                $this->systemdEnvironment(),
            );
            $enable->setTimeout(20);
            $enable->run();

            if (!$enable->isSuccessful()) {
                return [
                    'ok' => false,
                    'wrote' => $wrote,
                    'enabled' => false,
                    'started' => false,
                    'message' => 'The recordings worker service file'.($this->desiredWorkerCount() === 1 ? ' exists' : 's exist').', but systemd could not enable '.($this->desiredWorkerCount() === 1 ? 'it' : 'them').': '.trim($enable->getErrorOutput() ?: $enable->getOutput()),
                    'path' => implode(', ', $paths),
                ];
            }

            $this->disableLegacySingleWorkerService();
            $this->disableStaleNumberedWorkerServices();

            return [
                'ok' => true,
                'wrote' => $wrote,
                'enabled' => true,
                'started' => $start,
                'message' => $start
                    ? ($this->desiredWorkerCount() === 1
                        ? 'Installed and started the recordings worker systemd unit.'
                        : 'Installed and started '.$this->desiredWorkerCount().' recordings worker systemd units.')
                    : ($this->desiredWorkerCount() === 1
                        ? 'Installed and enabled the recordings worker systemd unit.'
                        : 'Installed and enabled '.$this->desiredWorkerCount().' recordings worker systemd units.'),
                'path' => implode(', ', $paths),
            ];
        } catch (Throwable $exception) {
            $this->safeLogWarning('Unable to install the recordings queue worker systemd unit.', [
                'path' => implode(', ', $paths),
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'wrote' => false,
                'enabled' => false,
                'started' => false,
                'message' => 'Unable to install the recordings worker systemd unit: '.$exception->getMessage(),
                'path' => implode(', ', $paths),
            ];
        }
    }

    /**
     * @return array{ok: bool, running: bool, started: bool, method: string, message: string, pid: int|null}
     */
    public function ensureRunning(): array
    {
        $lock = Cache::lock('camera-recordings:ensure-worker', 50);

        if (!$lock->get()) {
            return [
                'ok' => true,
                'running' => $this->isRunning(),
                'started' => false,
                'method' => 'locked',
                'message' => 'Another scheduler tick is already checking the recordings worker.',
                'pid' => $this->runningPid(),
            ];
        }

        try {
            $pids = $this->runningPids();
            $requiredWorkers = $this->desiredWorkerCount();

            if (count($pids) >= $requiredWorkers) {
                return [
                    'ok' => true,
                    'running' => true,
                    'started' => false,
                    'method' => 'process',
                    'message' => $requiredWorkers === 1
                        ? 'The recordings queue worker is already running.'
                        : 'All '.$requiredWorkers.' recordings queue workers are already running.',
                    'pid' => $pids[0] ?? null,
                ];
            }

            if (!(bool) config('recording.worker.ensure_running', false)) {
                return [
                    'ok' => true,
                    'running' => $pids !== [],
                    'started' => false,
                    'method' => 'disabled',
                    'message' => 'Recordings worker supervision is disabled.',
                    'pid' => $pids[0] ?? null,
                ];
            }

            $serviceInstallResult = $this->ensureSystemdServicesInstalled();

            if ($serviceInstallResult !== null && !$serviceInstallResult['ok']) {
                return [
                    'ok' => false,
                    'running' => $pids !== [],
                    'started' => false,
                    'method' => 'systemd',
                    'message' => $serviceInstallResult['message'],
                    'pid' => $pids[0] ?? null,
                ];
            }

            $systemdResult = $this->startViaSystemd();

            if ($systemdResult !== null) {
                return $systemdResult;
            }

            if ((bool) config('recording.worker.fallback_start', false)) {
                return $this->startDetachedWorkers();
            }

            return [
                'ok' => false,
                'running' => $this->runningPids() !== [],
                'started' => false,
                'method' => 'unavailable',
                'message' => $requiredWorkers === 1
                    ? 'No recordings worker is running, systemd start was unavailable, and detached fallback start is disabled.'
                    : 'Only '.$this->runningWorkerCount().' of '.$requiredWorkers.' recordings workers are running, systemd start was unavailable, and detached fallback start is disabled.',
                'pid' => ($this->runningPids())[0] ?? null,
            ];
        } finally {
            $lock->release();
        }
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

        $process = new Process([$this->psBinary(), '-eo', 'pid=,args=']);
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

    /**
     * @return array{ok: bool, running: bool, started: bool, method: string, message: string, pid: int|null}|null
     */
    private function startViaSystemd(): ?array
    {
        $services = $this->systemdServiceNames();

        if ($services === []) {
            return null;
        }

        try {
            $startedServices = [];

            foreach ($services as $service) {
                $status = new Process([
                    $this->systemctlBinary(),
                    '--user',
                    'is-active',
                    '--quiet',
                    $service,
                ], base_path(), $this->systemdEnvironment());
                $status->setTimeout(8);
                $status->run();

                if ($status->isSuccessful()) {
                    continue;
                }

                $start = new Process([
                    $this->systemctlBinary(),
                    '--user',
                    'start',
                    $service,
                ], base_path(), $this->systemdEnvironment());
                $start->setTimeout(15);
                $start->run();

                if (!$start->isSuccessful()) {
                    $this->safeLogWarning('Unable to start the recordings queue worker via systemd.', [
                        'service' => $service,
                        'error' => trim($start->getErrorOutput() ?: $start->getOutput()),
                    ]);

                    return null;
                }

                $startedServices[] = $service;
            }

            usleep(300000);
            $pids = $this->runningPids();
            $requiredWorkers = $this->desiredWorkerCount();

            return [
                'ok' => true,
                'running' => true,
                'started' => $startedServices !== [],
                'method' => 'systemd',
                'message' => $startedServices === []
                    ? ($requiredWorkers === 1
                        ? 'The recordings queue worker systemd unit is already active.'
                        : 'All '.$requiredWorkers.' recordings queue worker systemd units are already active.')
                    : ($requiredWorkers === 1
                        ? 'Started the recordings queue worker via the configured systemd unit.'
                        : 'Started '.count($startedServices).' recordings queue worker'.(count($startedServices) === 1 ? '' : 's').' via the configured systemd units.'),
                'pid' => $pids[0] ?? null,
            ];
        } catch (Throwable $exception) {
            $this->safeLogWarning('Unable to manage the recordings queue worker via systemd.', [
                'service' => implode(', ', $services),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{ok: bool, running: bool, started: bool, method: string, message: string, pid: int|null}
     */
    private function startDetachedWorkers(): array
    {
        $logPath = (string) config('recording.worker.log_path', storage_path('logs/recordings-queue-worker.log'));
        File::ensureDirectoryExists(dirname($logPath));

        $missingWorkers = max(0, $this->desiredWorkerCount() - $this->runningWorkerCount());
        $startedPids = [];

        for ($workerIndex = 0; $workerIndex < $missingWorkers; $workerIndex++) {
            $command = sprintf(
                'cd %s && nohup %s artisan queue:work --queue=%s --max-jobs=%d --max-time=%d --memory=%d >> %s 2>&1 & echo $!',
                escapeshellarg(base_path()),
                escapeshellarg($this->phpBinary()),
                escapeshellarg($this->workerQueues()),
                $this->maxJobs(),
                $this->maxTime(),
                $this->memoryLimit(),
                escapeshellarg($logPath),
            );

            $process = new Process([$this->shellBinary(), '-lc', $command]);
            $process->setTimeout(15);
            $process->run();

            if (!$process->isSuccessful()) {
                return [
                    'ok' => false,
                    'running' => $this->runningPids() !== [],
                    'started' => false,
                    'method' => 'detached',
                    'message' => 'Unable to start the recordings queue worker from the scheduler fallback: '.trim($process->getErrorOutput() ?: $process->getOutput()),
                    'pid' => $this->runningPid(),
                ];
            }

            $pid = (int) trim($process->getOutput());

            for ($attempt = 0; $attempt < 5; $attempt++) {
                if ($pid > 0 && $this->processIsRunning($pid)) {
                    $startedPids[] = $pid;
                    continue 2;
                }

                usleep(200000);
            }

            return [
                'ok' => false,
                'running' => $this->runningPids() !== [],
                'started' => $startedPids !== [],
                'method' => 'detached',
                'message' => 'The scheduler fallback launched a worker command, but the process did not remain running.',
                'pid' => $pid > 0 ? $pid : ($startedPids[0] ?? null),
            ];
        }

        if ($this->runningWorkerCount() < $this->desiredWorkerCount()) {
            return [
                'ok' => false,
                'running' => $this->runningPids() !== [],
                'started' => $startedPids !== [],
                'method' => 'detached',
                'message' => 'The scheduler fallback started additional workers, but the expected worker count was not reached.',
                'pid' => $startedPids[0] ?? $this->runningPid(),
            ];
        }

        return [
            'ok' => true,
            'running' => true,
            'started' => $startedPids !== [],
            'method' => 'detached',
            'message' => $this->desiredWorkerCount() === 1
                ? 'Started the recordings queue worker from the scheduler fallback.'
                : 'Started '.count($startedPids).' recordings queue worker'.(count($startedPids) === 1 ? '' : 's').' from the scheduler fallback.',
            'pid' => $startedPids[0] ?? $this->runningPid(),
        ];
    }

    private function argsMatchWorker(string $args): bool
    {
        if (!str_contains($args, 'artisan queue:work')) {
            return false;
        }

        if (!str_contains($args, '--queue='.$this->workerQueues()) && !str_contains($args, '--queue '.$this->workerQueues())) {
            return false;
        }

        return true;
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

            if (posix_get_last_error() === 1) {
                return true;
            }

            return false;
        }

        return is_dir('/proc/'.$pid);
    }

    /**
     * @return array<string, string>
     */
    private function systemdEnvironment(): array
    {
        $environment = [];
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if (!is_int($uid)) {
            return $environment;
        }

        $runtimeDirectory = '/run/user/'.$uid;

        if (!is_dir($runtimeDirectory)) {
            return $environment;
        }

        $environment['XDG_RUNTIME_DIR'] = $runtimeDirectory;

        if (is_file($runtimeDirectory.'/bus')) {
            $environment['DBUS_SESSION_BUS_ADDRESS'] = 'unix:path='.$runtimeDirectory.'/bus';
        }

        return $environment;
    }

    private function systemdServicePath(string $serviceName): string
    {
        return rtrim((string) config('recording.worker.systemd_user_dir', $this->defaultSystemdUserDirectory()), '/').'/'.$serviceName;
    }

    private function defaultSystemdUserDirectory(): string
    {
        $home = env('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/').'/.config/systemd/user';
        }

        return base_path('/storage/app/private/systemd-user');
    }

    private function systemdServiceName(): string
    {
        return (string) config('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
    }

    /**
     * @return array<string>
     */
    private function systemdServiceNames(): array
    {
        $service = trim($this->systemdServiceName());

        if ($service === '') {
            return [];
        }

        $workerCount = $this->desiredWorkerCount();

        if ($workerCount === 1) {
            return [$service];
        }

        if (preg_match('/^(.*?)(\.service)$/', $service, $matches) !== 1) {
            return [$service];
        }

        $prefix = $matches[1];
        $suffix = $matches[2];
        $services = [];

        for ($index = 1; $index <= $workerCount; $index++) {
            $services[] = $prefix.'-'.$index.$suffix;
        }

        return $services;
    }

    private function systemdServiceContents(): string
    {
        return implode(PHP_EOL, [
            '[Unit]',
            'Description=BigBrothas recordings queue worker',
            'After=default.target',
            '',
            '[Service]',
            'Type=simple',
            'WorkingDirectory='.base_path(),
            'ExecStart='.$this->phpBinary().' artisan queue:work --queue='.$this->workerQueues().' --max-jobs='.$this->maxJobs().' --max-time='.$this->maxTime().' --memory='.$this->memoryLimit(),
            'ExecReload='.$this->phpBinary().' artisan queue:restart',
            'Restart=always',
            'RestartSec=5',
            'KillSignal=SIGTERM',
            'TimeoutStopSec=90',
            '',
            '[Install]',
            'WantedBy=default.target',
            '',
        ]);
    }

    /**
     * @return array{ok: bool, message: string}|null
     */
    private function ensureSystemdServicesInstalled(): ?array
    {
        if (!$this->systemdServicesNeedInstall()) {
            return null;
        }

        $result = $this->installSystemdUserService(false);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? 'Unable to install the recordings worker systemd units.'),
        ];
    }

    private function systemdServicesNeedInstall(): bool
    {
        $services = $this->systemdServiceNames();

        if ($services === []) {
            return false;
        }

        $expectedContents = $this->systemdServiceContents();

        foreach ($services as $serviceName) {
            $path = $this->systemdServicePath($serviceName);

            if (!is_file($path)) {
                return true;
            }

            $existingContents = file_get_contents($path);

            if (!is_string($existingContents) || $existingContents !== $expectedContents) {
                return true;
            }
        }

        return false;
    }

    private function disableLegacySingleWorkerService(): void
    {
        $legacyService = $this->legacySingleWorkerServiceName();

        if ($legacyService === null) {
            return;
        }

        try {
            $disable = new Process([
                $this->systemctlBinary(),
                '--user',
                'disable',
                '--now',
                $legacyService,
            ], base_path(), $this->systemdEnvironment());
            $disable->setTimeout(20);
            $disable->run();

            if (!$disable->isSuccessful()) {
                $this->safeLogWarning('Unable to retire the legacy recordings queue worker systemd unit.', [
                    'service' => $legacyService,
                    'error' => trim($disable->getErrorOutput() ?: $disable->getOutput()),
                ]);
            }
        } catch (Throwable $exception) {
            $this->safeLogWarning('Unable to retire the legacy recordings queue worker systemd unit.', [
                'service' => $legacyService,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function disableStaleNumberedWorkerServices(): void
    {
        $service = trim($this->systemdServiceName());

        if ($service === '' || preg_match('/^(.*?)(\.service)$/', $service, $matches) !== 1) {
            return;
        }

        $directory = rtrim((string) config('recording.worker.systemd_user_dir', $this->defaultSystemdUserDirectory()), '/');

        if (!is_dir($directory)) {
            return;
        }

        $prefix = $matches[1];
        $suffix = $matches[2];
        $desiredServices = $this->systemdServiceNames();
        $pattern = $directory.'/'.$prefix.'-*'.$suffix;
        $servicePaths = glob($pattern);

        if ($servicePaths === false) {
            return;
        }

        foreach ($servicePaths as $path) {
            $serviceName = basename($path);

            if (!is_string($serviceName) || $serviceName === '' || in_array($serviceName, $desiredServices, true)) {
                continue;
            }

            if (preg_match('/^'.preg_quote($prefix, '/').'-\d+'.preg_quote($suffix, '/').'$/', $serviceName) !== 1) {
                continue;
            }

            try {
                $disable = new Process([
                    $this->systemctlBinary(),
                    '--user',
                    'disable',
                    '--now',
                    $serviceName,
                ], base_path(), $this->systemdEnvironment());
                $disable->setTimeout(20);
                $disable->run();

                if (!$disable->isSuccessful()) {
                    $this->safeLogWarning('Unable to retire stale numbered recordings queue worker systemd units.', [
                        'service' => $serviceName,
                        'error' => trim($disable->getErrorOutput() ?: $disable->getOutput()),
                    ]);
                }
            } catch (Throwable $exception) {
                $this->safeLogWarning('Unable to retire stale numbered recordings queue worker systemd units.', [
                    'service' => $serviceName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function desiredWorkerCount(): int
    {
        $minimumWorkers = max(1, (int) config('recording.worker.processes', 1));

        if (!(bool) config('recording.worker.dynamic_enabled', true)) {
            return $minimumWorkers;
        }

        $maximumWorkers = max($minimumWorkers, (int) config('recording.worker.max_processes', $minimumWorkers));
        $cameraTarget = (int) ceil($this->enabledRecordingCameraCount() / max(1, (int) config('recording.worker.cameras_per_process', 4)));
        $queueTarget = (int) ceil($this->queuedWorkerJobsCount() / max(1, (int) config('recording.worker.jobs_per_process', 200)));

        return min($maximumWorkers, max($minimumWorkers, $cameraTarget, $queueTarget));
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

    private function legacySingleWorkerServiceName(): ?string
    {
        if ($this->desiredWorkerCount() === 1) {
            return null;
        }

        $service = trim($this->systemdServiceName());

        if ($service === '' || in_array($service, $this->systemdServiceNames(), true)) {
            return null;
        }

        return $service;
    }

    private function runningWorkerCount(): int
    {
        return count($this->runningPids());
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

    private function maxJobs(): int
    {
        return max(1, (int) config('recording.worker.max_jobs', 50));
    }

    private function maxTime(): int
    {
        return max(60, (int) config('recording.worker.max_time', 3600));
    }

    private function memoryLimit(): int
    {
        return max(64, (int) config('recording.worker.memory', 256));
    }

    private function phpBinary(): string
    {
        return (string) config('recording.worker.php_binary', PHP_BINARY);
    }

    private function psBinary(): string
    {
        return (string) config('recording.worker.ps_binary', '/usr/bin/ps');
    }

    private function shellBinary(): string
    {
        return (string) config('recording.worker.shell_binary', '/bin/sh');
    }

    private function systemctlBinary(): string
    {
        return (string) config('recording.worker.systemctl_binary', '/usr/bin/systemctl');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLogWarning(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Logging failures must not block the scheduler.
        }
    }
}