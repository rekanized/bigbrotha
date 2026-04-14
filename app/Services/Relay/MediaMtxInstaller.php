<?php

namespace App\Services\Relay;

use RuntimeException;

class MediaMtxInstaller
{
    public function isInstalled(): bool
    {
        $binaryPath = $this->binaryPath();

        return is_file($binaryPath) && is_executable($binaryPath);
    }

    public function binaryPath(): string
    {
        return (string) config('mediamtx.binary_path');
    }

    public function ensureInstalled(): string
    {
        $binaryPath = $this->binaryPath();

        if (!$this->isInstalled()) {
            throw new RuntimeException('Bundled MediaMTX binary not found at '.$binaryPath.'. Rebuild the Docker image before starting the relay.');
        }

        @chmod($binaryPath, 0755);

        return $binaryPath;
    }
}
