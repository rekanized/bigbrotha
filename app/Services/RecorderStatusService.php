<?php

namespace App\Services;

use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Facades\File;

class RecorderStatusService
{
    use ResolvesConfiguredBinaries;

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
}
