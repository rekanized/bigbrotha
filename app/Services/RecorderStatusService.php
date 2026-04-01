<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class RecorderStatusService
{
    /**
     * @return array<string, bool|string|null>
     */
    public function snapshot(): array
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
        $ffprobeBinary = $this->resolveBinary(config('ffmpeg.ffprobe.binaries', []));
        $temporaryDirectory = (string) config('ffmpeg.temporary_directory');

        $temporaryDirectoryExists = $temporaryDirectory !== '' && File::isDirectory($temporaryDirectory);
        $temporaryDirectoryWritable = $temporaryDirectoryExists && is_writable($temporaryDirectory);
        $isReady = $ffmpegBinary !== null && $ffprobeBinary !== null && $temporaryDirectoryWritable;

        return [
            'is_ready' => $isReady,
            'summary' => $isReady ? 'Ready' : 'Attention needed',
            'ffmpeg_binary' => $ffmpegBinary,
            'ffprobe_binary' => $ffprobeBinary,
            'temporary_directory' => $temporaryDirectory,
            'temporary_directory_exists' => $temporaryDirectoryExists,
            'temporary_directory_writable' => $temporaryDirectoryWritable,
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function resolveBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }

                continue;
            }

            $resolved = $this->resolveFromPath($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveFromPath(string $binary): ?string
    {
        $path = getenv('PATH') ?: '';

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}