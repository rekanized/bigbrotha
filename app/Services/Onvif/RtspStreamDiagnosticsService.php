<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use App\Services\CameraStorageService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class RtspStreamDiagnosticsService
{
    /**
     * @param  array<string, string|null>  $profile
     * @return array<string, string|null>
     */
    public function testAndPreview(Camera $camera, array $profile, int $profileIndex, int $timeoutSeconds = 12): array
    {
        $uri = $this->stringOrNull($profile['uri'] ?? null);

        if ($uri === null) {
            throw new RuntimeException('This RTSP profile does not have a stream URI yet. Refresh RTSP profiles first.');
        }

        $ffprobeBinary = $this->resolveBinary(config('ffmpeg.ffprobe.binaries', []));
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffprobeBinary === null || $ffmpegBinary === null) {
            throw new RuntimeException('ffprobe or ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        $transport = in_array($camera->rtsp_transport, ['tcp', 'udp'], true) ? $camera->rtsp_transport : 'tcp';
        $authenticatedUri = $this->injectCredentials($uri, $camera->username, $camera->password);
        $probeCheckedAt = now()->utc()->format('Y-m-d H:i:s').' UTC';
        $previewPath = $this->buildPreviewRelativePath($camera, $profile, $profileIndex);
        $absolutePreviewPath = storage_path('app/private/'.$previewPath);

        $probeProcess = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-rtsp_transport',
            $transport,
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=codec_name,width,height,avg_frame_rate',
            '-of',
            'json',
            $authenticatedUri,
        ]);
        $probeProcess->setTimeout($timeoutSeconds);
        $probeProcess->run();

        if (!$probeProcess->isSuccessful()) {
            return array_merge($profile, [
                'probe_status' => 'Failed',
                'probe_checked_at' => $probeCheckedAt,
                'probe_message' => $this->summarizeProcessFailure($probeProcess),
            ]);
        }

        $streamInfo = $this->parseProbeOutput($probeProcess->getOutput());

        app(CameraStorageService::class)->ensureCameraDirectories($camera);

        $previewProcess = new Process([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $transport,
            '-y',
            '-i',
            $authenticatedUri,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $absolutePreviewPath,
        ]);
        $previewProcess->setTimeout($timeoutSeconds);
        $previewProcess->run();

        $previewMessage = $previewProcess->isSuccessful() && is_file($absolutePreviewPath)
            ? 'Snapshot captured successfully.'
            : $this->summarizeProcessFailure($previewProcess, 'Connected to the stream, but snapshot capture failed.');

        return array_merge($profile, [
            'probe_status' => 'Healthy',
            'probe_checked_at' => $probeCheckedAt,
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => $streamInfo['codec_name'],
            'video_resolution' => $streamInfo['resolution'],
            'preview_path' => is_file($absolutePreviewPath) ? $previewPath : ($profile['preview_path'] ?? null),
            'preview_generated_at' => is_file($absolutePreviewPath) ? $probeCheckedAt : ($profile['preview_generated_at'] ?? null),
            'preview_message' => $previewMessage,
            'transport' => strtoupper($transport),
        ]);
    }

    /**
     * @return array{codec_name: string|null, resolution: string|null}
     */
    private function parseProbeOutput(string $output): array
    {
        $decoded = json_decode($output, true);
        $stream = is_array($decoded) && isset($decoded['streams'][0]) && is_array($decoded['streams'][0])
            ? $decoded['streams'][0]
            : [];
        $width = isset($stream['width']) ? (string) $stream['width'] : null;
        $height = isset($stream['height']) ? (string) $stream['height'] : null;

        return [
            'codec_name' => $this->stringOrNull($stream['codec_name'] ?? null),
            'resolution' => $width !== null && $height !== null ? $width.'x'.$height : null,
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

    /**
     * @param  array<string, string|null>  $profile
     */
    private function buildPreviewRelativePath(Camera $camera, array $profile, int $profileIndex): string
    {
        $baseName = $this->stringOrNull($profile['name'] ?? null)
            ?? $this->stringOrNull($profile['token'] ?? null)
            ?? 'profile-'.($profileIndex + 1);

        return app(CameraStorageService::class)->previewRelativePath(
            $camera,
            Str::slug($baseName).'-'.($profileIndex + 1).'.jpg',
        );
    }

    private function injectCredentials(string $uri, ?string $username, ?string $password): string
    {
        $parts = parse_url($uri);

        if (!is_array($parts) || isset($parts['user']) || $username === null || $username === '' || $password === null || $password === '') {
            return $uri;
        }

        $authority = rawurlencode($username).':'.rawurlencode($password).'@'.$parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return ($parts['scheme'] ?? 'rtsp').'://'.$authority.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function summarizeProcessFailure(Process $process, string $fallback = 'Unable to inspect the RTSP stream.'): string
    {
        $message = trim($process->getErrorOutput() ?: $process->getOutput());

        if ($message === '') {
            return $fallback;
        }

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 220);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}