<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class RecordingWorkerService
{
    /**
     * @return array{ok: bool, wrote: bool, enabled: bool, started: bool, message: string, path: string}
     */
    public function installSystemdUserService(bool $start = true): array
    {
        $path = $this->systemdServicePath();
        $contents = $this->systemdServiceContents();

        try {
            File::ensureDirectoryExists(dirname($path));

            $existingContents = is_file($path) ? file_get_contents($path) : false;
            $wrote = !is_string($existingContents) || $existingContents !== $contents;

            if ($wrote) {
                File::put($path, $contents);
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
                    'message' => 'Wrote the recordings worker service file, but systemd daemon-reload failed: '.trim($reload->getErrorOutput() ?: $reload->getOutput()),
                    'path' => $path,
                ];
            }

            $enable = new Process(
                $start
                    ? [$this->systemctlBinary(), '--user', 'enable', '--now', $this->systemdServiceName()]
                    : [$this->systemctlBinary(), '--user', 'enable', $this->systemdServiceName()],
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
                    'message' => 'The recordings worker service file exists, but systemd could not enable it: '.trim($enable->getErrorOutput() ?: $enable->getOutput()),
                    'path' => $path,
                ];
            }

            return [
                'ok' => true,
                'wrote' => $wrote,
                'enabled' => true,
                'started' => $start,
                'message' => $start
                    ? 'Installed and started the recordings worker systemd unit.'
                    : 'Installed and enabled the recordings worker systemd unit.',
                'path' => $path,
            ];
        } catch (Throwable $exception) {
            $this->safeLogWarning('Unable to install the recordings queue worker systemd unit.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'wrote' => false,
                'enabled' => false,
                'started' => false,
                'message' => 'Unable to install the recordings worker systemd unit: '.$exception->getMessage(),
                'path' => $path,
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
            $pid = $this->runningPid();

            if ($pid !== null) {
                return [
                    'ok' => true,
                    'running' => true,
                    'started' => false,
                    'method' => 'process',
                    'message' => 'The recordings queue worker is already running.',
                    'pid' => $pid,
                ];
            }

            if (!(bool) config('recording.worker.ensure_running', false)) {
                return [
                    'ok' => true,
                    'running' => false,
                    'started' => false,
                    'method' => 'disabled',
                    'message' => 'Recordings worker supervision is disabled.',
                    'pid' => null,
                ];
            }

            $systemdResult = $this->startViaSystemd();

            if ($systemdResult !== null) {
                return $systemdResult;
            }

            if ((bool) config('recording.worker.fallback_start', false)) {
                return $this->startDetachedWorker();
            }

            return [
                'ok' => false,
                'running' => false,
                'started' => false,
                'method' => 'unavailable',
                'message' => 'No recordings worker is running, systemd start was unavailable, and detached fallback start is disabled.',
                'pid' => null,
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
        $process = new Process([$this->psBinary(), '-eo', 'pid=,args=']);
        $process->setTimeout(2);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
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
                return $resolvedPid;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, running: bool, started: bool, method: string, message: string, pid: int|null}|null
     */
    private function startViaSystemd(): ?array
    {
        $service = trim((string) config('recording.worker.systemd_service', ''));

        if ($service === '') {
            return null;
        }

        try {
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
                return [
                    'ok' => true,
                    'running' => true,
                    'started' => false,
                    'method' => 'systemd',
                    'message' => 'The recordings queue worker systemd unit is already active.',
                    'pid' => $this->runningPid(),
                ];
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

            usleep(300000);
            $pid = $this->runningPid();

            return [
                'ok' => true,
                'running' => true,
                'started' => true,
                'method' => 'systemd',
                'message' => 'Started the recordings queue worker via the configured systemd unit.',
                'pid' => $pid,
            ];
        } catch (Throwable $exception) {
            $this->safeLogWarning('Unable to manage the recordings queue worker via systemd.', [
                'service' => $service,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{ok: bool, running: bool, started: bool, method: string, message: string, pid: int|null}
     */
    private function startDetachedWorker(): array
    {
        $logPath = (string) config('recording.worker.log_path', storage_path('logs/recordings-queue-worker.log'));
        File::ensureDirectoryExists(dirname($logPath));

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
                'running' => false,
                'started' => false,
                'method' => 'detached',
                'message' => 'Unable to start the recordings queue worker from the scheduler fallback: '.trim($process->getErrorOutput() ?: $process->getOutput()),
                'pid' => null,
            ];
        }

        $pid = (int) trim($process->getOutput());

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($pid > 0 && $this->processIsRunning($pid)) {
                return [
                    'ok' => true,
                    'running' => true,
                    'started' => true,
                    'method' => 'detached',
                    'message' => 'Started the recordings queue worker from the scheduler fallback.',
                    'pid' => $pid,
                ];
            }

            usleep(200000);
        }

        return [
            'ok' => false,
            'running' => false,
            'started' => false,
            'method' => 'detached',
            'message' => 'The scheduler fallback launched a worker command, but the process did not remain running.',
            'pid' => $pid > 0 ? $pid : null,
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

    private function systemdServicePath(): string
    {
        return rtrim((string) config('recording.worker.systemd_user_dir', $this->defaultSystemdUserDirectory()), '/').'/'.$this->systemdServiceName();
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

    private function workerQueues(): string
    {
        return (string) config('recording.worker.queue', config('recording.queue', 'recordings').',default');
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