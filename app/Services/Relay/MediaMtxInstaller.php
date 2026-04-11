<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class MediaMtxInstaller
{
    public function install(bool $force = false): string
    {
        $binaryPath = $this->binaryPath();
        $installMode = strtolower((string) config('mediamtx.install_mode', 'download'));

        if ($installMode === 'bundled') {
            if ($this->isInstalled()) {
                @chmod($binaryPath, 0755);

                return $binaryPath;
            }

            throw new RuntimeException('MediaMTX install mode is set to bundled, but the binary was not found at '.$binaryPath.'.');
        }

        if (!$force && $this->isInstalled()) {
            return $binaryPath;
        }

        $installDirectory = (string) config('mediamtx.install_directory');
        $installRoot = (string) config('mediamtx.install_root');
        $archiveDirectory = $installRoot.'/archives';
        $archivePath = $archiveDirectory.'/'.$this->archiveFileName();

        File::ensureDirectoryExists($archiveDirectory);
        File::ensureDirectoryExists(dirname($installDirectory));
        File::deleteDirectory($installDirectory);
        File::ensureDirectoryExists($installDirectory);

        Http::timeout((int) config('mediamtx.download_timeout', 180))
            ->withOptions(['sink' => $archivePath])
            ->get($this->downloadUrl())
            ->throw();

        $extractProcess = new Process([
            'tar',
            '-xzf',
            $archivePath,
            '-C',
            $installDirectory,
        ]);
        $extractProcess->setTimeout((int) config('mediamtx.download_timeout', 180));
        $extractProcess->run();

        if (!$extractProcess->isSuccessful()) {
            throw new RuntimeException('Unable to extract the MediaMTX archive: '.trim($extractProcess->getErrorOutput() ?: $extractProcess->getOutput()));
        }

        if (!is_file($binaryPath)) {
            throw new RuntimeException('MediaMTX was downloaded, but the binary was not found at '.$binaryPath.'.');
        }

        @chmod($binaryPath, 0755);

        return $binaryPath;
    }

    public function isInstalled(): bool
    {
        $binaryPath = $this->binaryPath();

        return is_file($binaryPath) && is_executable($binaryPath);
    }

    public function binaryPath(): string
    {
        return (string) config('mediamtx.binary_path');
    }

    public function downloadUrl(): string
    {
        return rtrim((string) config('mediamtx.download_base_url'), '/').'/v'.config('mediamtx.version').'/'.$this->archiveFileName();
    }

    private function archiveFileName(): string
    {
        return 'mediamtx_v'.config('mediamtx.version').'_'.$this->platformIdentifier().'.tar.gz';
    }

    private function platformIdentifier(): string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        return match ($os) {
            'linux' => match ($arch) {
                'x86_64', 'amd64' => 'linux_amd64',
                'aarch64', 'arm64' => 'linux_arm64v8',
                'armv7l', 'armv7' => 'linux_armv7',
                default => throw new RuntimeException('Unsupported Linux architecture for MediaMTX: '.$arch),
            },
            'darwin' => match ($arch) {
                'x86_64', 'amd64' => 'darwin_amd64',
                'arm64', 'aarch64' => 'darwin_arm64',
                default => throw new RuntimeException('Unsupported macOS architecture for MediaMTX: '.$arch),
            },
            default => throw new RuntimeException('Unsupported operating system for MediaMTX installation: '.PHP_OS_FAMILY),
        };
    }
}
