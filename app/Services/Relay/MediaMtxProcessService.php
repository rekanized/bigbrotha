<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class MediaMtxProcessService
{
    public function __construct(
        private readonly MediaMtxInstaller $installer,
        private readonly MediaMtxConfigService $configService,
    ) {
    }

    /**
     * @return array{installed: bool, running: bool, api_reachable: bool, config_changed: bool, binary_path: string, config_path: string, log_path: string, pid: int|null}
     */
    public function ensureRunning(): array
    {
        $configChanged = $this->syncConfig();
        $installed = $this->installer->isInstalled();
        $running = $this->isRunning();
        $apiReachable = $running && $this->apiReachable();

        if ($installed && (bool) config('mediamtx.auto_start', true)) {
            if (($configChanged || ($running && !$apiReachable)) && $running) {
                $this->stop();
                $running = false;
                $apiReachable = false;
            }

            if (!$running) {
                $this->start(syncConfig: false);
                $running = $this->isRunning();
                $apiReachable = $running && $this->apiReachable();
            }
        }

        return $this->status($configChanged);
    }

    public function syncConfig(): bool
    {
        $configPath = (string) config('mediamtx.config_path');
        $configContents = $this->configService->buildConfig();

        File::ensureDirectoryExists(dirname($configPath));

        if (is_file($configPath) && file_get_contents($configPath) === $configContents) {
            return false;
        }

        File::put($configPath, $configContents);

        return true;
    }

    /**
     * @return array{installed: bool, running: bool, api_reachable: bool, config_changed: bool, binary_path: string, config_path: string, log_path: string, pid: int|null}
     */
    public function start(bool $syncConfig = true): array
    {
        if ($syncConfig) {
            $this->syncConfig();
        }

        if (!$this->installer->isInstalled()) {
            throw new RuntimeException('MediaMTX is not installed yet. Run composer relay:install first.');
        }

        if ($this->isRunning()) {
            return $this->status(false);
        }

        File::ensureDirectoryExists(dirname((string) config('mediamtx.pid_path')));
        File::ensureDirectoryExists(dirname((string) config('mediamtx.log_path')));

        $command = sprintf(
            'nohup %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($this->installer->binaryPath()),
            escapeshellarg((string) config('mediamtx.config_path')),
            escapeshellarg((string) config('mediamtx.log_path')),
        );

        $process = new Process(['sh', '-lc', $command]);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Unable to start MediaMTX: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $pid = (int) trim($process->getOutput());

        if ($pid > 0) {
            File::put((string) config('mediamtx.pid_path'), (string) $pid);
        }

        $this->waitUntilReady();

        $status = $this->status(false);

        if (!$status['running'] || !$status['api_reachable']) {
            throw new RuntimeException('MediaMTX failed to become ready. Check '.$status['log_path'].' for details.');
        }

        return $status;
    }

    public function stop(): void
    {
        $pid = $this->pid();

        if ($pid !== null) {
            $process = new Process(['sh', '-lc', 'kill '.(int) $pid.' >/dev/null 2>&1 || true']);
            $process->run();
        }

        File::delete((string) config('mediamtx.pid_path'));
    }

    /**
     * @return array{installed: bool, running: bool, api_reachable: bool, config_changed: bool, binary_path: string, config_path: string, log_path: string, pid: int|null}
     */
    public function status(bool $configChanged = false): array
    {
        return [
            'installed' => $this->installer->isInstalled(),
            'running' => $this->isRunning(),
            'api_reachable' => $this->apiReachable(),
            'config_changed' => $configChanged,
            'binary_path' => $this->installer->binaryPath(),
            'config_path' => (string) config('mediamtx.config_path'),
            'log_path' => (string) config('mediamtx.log_path'),
            'pid' => $this->pid(),
        ];
    }

    public function isRunning(): bool
    {
        return $this->pid() !== null;
    }

    private function pid(): ?int
    {
        $storedPid = $this->storedPid();

        if ($storedPid !== null && $this->processMatchesExpectedInstance($storedPid)) {
            return $storedPid;
        }

        $discoveredPid = $this->discoverRunningPid();

        if ($discoveredPid !== null) {
            if ($discoveredPid !== $storedPid) {
                File::put((string) config('mediamtx.pid_path'), (string) $discoveredPid);
            }

            return $discoveredPid;
        }

        if ($storedPid !== null) {
            File::delete((string) config('mediamtx.pid_path'));
        }

        return null;
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 8;

        do {
            if ($this->apiReachable()) {
                return;
            }

            usleep(250000);
        } while (microtime(true) < $deadline);
    }

    private function apiReachable(): bool
    {
        if (!$this->installer->isInstalled()) {
            return false;
        }

        try {
            $response = Http::timeout(2)->get(rtrim((string) config('mediamtx.api.base_url'), '/').'/v3/paths/list');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function storedPid(): ?int
    {
        $pidPath = (string) config('mediamtx.pid_path');

        if (!is_file($pidPath)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($pidPath));

        return $pid > 0 ? $pid : null;
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
                return $this->readProcessArgs($pid) !== null;
            }

            return false;
        }

        $process = new Process(['sh', '-lc', 'kill -0 '.(int) $pid.' >/dev/null 2>&1']);
        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->readProcessArgs($pid) !== null;
    }

    private function discoverRunningPid(): ?int
    {
        if (!$this->installer->isInstalled()) {
            return null;
        }

        $binaryPath = $this->installer->binaryPath();
        $configPath = (string) config('mediamtx.config_path');
        $process = new Process(['ps', '-eo', 'pid=,args=']);
        $process->setTimeout(2);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }

            [$pid, $args] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
            $resolvedPid = (int) ($pid ?? 0);

            if ($resolvedPid < 1 || !is_string($args)) {
                continue;
            }

            if (str_contains($args, $binaryPath) && str_contains($args, $configPath) && $this->processIsRunning($resolvedPid)) {
                return $resolvedPid;
            }
        }

        return null;
    }

    private function processMatchesExpectedInstance(int $pid): bool
    {
        $args = $this->readProcessArgs($pid);

        if ($args === null) {
            return false;
        }

        return str_contains($args, $this->installer->binaryPath())
            && str_contains($args, (string) config('mediamtx.config_path'));
    }

    private function readProcessArgs(int $pid): ?string
    {
        if ($pid < 1) {
            return null;
        }

        $procPath = '/proc/'.$pid.'/cmdline';

        if (is_file($procPath) && is_readable($procPath)) {
            $contents = file_get_contents($procPath);

            if (is_string($contents) && $contents !== '') {
                return str_replace("\0", ' ', trim($contents));
            }
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'args=']);
        $process->setTimeout(2);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $args = trim($process->getOutput());

        return $args !== '' ? $args : null;
    }
}
