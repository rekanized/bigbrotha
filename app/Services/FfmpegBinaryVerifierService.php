<?php

namespace App\Services;

use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Facades\Process;
use Throwable;

class FfmpegBinaryVerifierService
{
    use ResolvesConfiguredBinaries;

    /**
     * @return array{ffmpeg: array{configured: ?string, resolved: ?string, executable: bool, successful: bool, output: ?string}, ffprobe: array{configured: ?string, resolved: ?string, executable: bool, successful: bool, output: ?string}}
     */
    public function verify(): array
    {
        return [
            'ffmpeg' => $this->verifyBinary(config('ffmpeg.ffmpeg.binaries', [])),
            'ffprobe' => $this->verifyBinary(config('ffmpeg.ffprobe.binaries', [])),
        ];
    }

    /**
     * @param  array<int, mixed>  $configuredCandidates
     * @return array{configured: ?string, resolved: ?string, executable: bool, successful: bool, output: ?string}
     */
    private function verifyBinary(array $configuredCandidates): array
    {
        $configuredPath = $this->firstConfiguredCandidate($configuredCandidates);
        $resolvedPath = $this->resolveBinary($configuredCandidates);

        if ($configuredPath === null && $resolvedPath === null) {
            return [
                'configured' => null,
                'resolved' => null,
                'executable' => false,
                'successful' => false,
                'output' => null,
            ];
        }

        if ($resolvedPath === null) {
            return [
                'configured' => $configuredPath,
                'resolved' => null,
                'executable' => false,
                'successful' => false,
                'output' => null,
            ];
        }

        try {
            $result = Process::timeout(10)->run([$resolvedPath, '-version']);
        } catch (Throwable $exception) {
            return [
                'configured' => $configuredPath,
                'resolved' => $resolvedPath,
                'executable' => true,
                'successful' => false,
                'output' => $exception->getMessage(),
            ];
        }

        return [
            'configured' => $configuredPath,
            'resolved' => $resolvedPath,
            'executable' => true,
            'successful' => $result->successful(),
            'output' => $result->output() !== '' ? strtok($result->output(), PHP_EOL) ?: null : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstConfiguredCandidate(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
