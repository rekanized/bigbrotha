<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CameraLiveStreamService
{
    private const MJPEG_BOUNDARY = 'bigbrotha-live';

    /**
     * @return array{index: int|null, profile: array<string, string|null>}|null
     */
    public function selectWallProfile(Camera $camera, ?int $profileIndex = null): ?array
    {
        if ($profileIndex !== null) {
            return $this->selectExplicitProfile($camera, $profileIndex);
        }

        $candidates = [];

        foreach ($camera->rtspProfiles() as $index => $profile) {
            if (!is_array($profile) || $this->stringOrNull($profile['uri'] ?? null) === null) {
                continue;
            }

            $candidates[] = [
                'index' => $index,
                'profile' => $profile,
                'is_efficient' => $this->isEfficientWallProfile($profile),
                'is_healthy' => strcasecmp((string) ($profile['probe_status'] ?? ''), 'Healthy') === 0,
                'pixel_count' => $this->profilePixelCount($profile),
            ];
        }

        if ($candidates !== []) {
            usort($candidates, function (array $left, array $right): int {
                return [
                    $right['is_efficient'] ? 1 : 0,
                    $right['is_healthy'] ? 1 : 0,
                    $left['pixel_count'] ?? PHP_INT_MAX,
                    $left['index'],
                ] <=> [
                    $left['is_efficient'] ? 1 : 0,
                    $left['is_healthy'] ? 1 : 0,
                    $right['pixel_count'] ?? PHP_INT_MAX,
                    $right['index'],
                ];
            });

            return [
                'index' => $candidates[0]['index'],
                'profile' => $candidates[0]['profile'],
            ];
        }

        $endpoint = $camera->rtspEndpoint();

        if ($endpoint === null) {
            return null;
        }

        return [
            'index' => null,
            'profile' => [
                'name' => 'Saved endpoint',
                'uri' => $endpoint,
                'encoding' => null,
                'resolution' => null,
                'video_codec' => null,
                'video_resolution' => null,
                'probe_status' => null,
            ],
        ];
    }

    public function mjpegResponse(Camera $camera, ?int $profileIndex = null): StreamedResponse
    {
        $selection = $this->selectedProfileOrFail($camera, $profileIndex);
        $command = $this->buildMjpegCommand($camera, $selection['profile']);

        return response()->stream(function () use ($command, $camera, $selection): void {
            $this->streamProcessOutput($command, $camera, 'wall-mjpeg', $selection);
        }, 200, $this->defaultHeaders([
            'Content-Type' => 'multipart/x-mixed-replace;boundary='.self::MJPEG_BOUNDARY,
            'X-Live-Stream-Mode' => 'wall-mjpeg',
        ], $selection));
    }

    public function relayResponse(Camera $camera, ?int $profileIndex = null): StreamedResponse
    {
        $selection = $this->selectedProfileOrFail($camera, $profileIndex);
        $command = $this->buildRelayCommand($camera, $selection['profile']);
        $fileName = Str::slug($camera->name ?: 'camera-live').'-relay.mp4';

        return response()->stream(function () use ($command, $camera, $selection): void {
            $this->streamProcessOutput($command, $camera, 'copy-relay', $selection);
        }, 200, $this->defaultHeaders([
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'X-Live-Stream-Mode' => 'copy-relay',
        ], $selection));
    }

    /**
     * @return array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    public function selectedWebRtcSource(Camera $camera, ?int $profileIndex = null): ?array
    {
        $selection = $this->selectWallProfile($camera, $profileIndex);

        if ($selection === null) {
            return null;
        }

        return [
            'index' => $selection['index'],
            'profile' => $selection['profile'],
            'authenticated_uri' => $this->authenticatedUri($camera, $selection['profile']),
            'transport' => $this->transport($camera),
        ];
    }

    public function ffmpegBinary(): ?string
    {
        return $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
    }

    /**
     * @param  array<string, string|null>  $profile
     * @return array<int, string>
     */
    private function buildMjpegCommand(Camera $camera, array $profile): array
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the live streaming configuration first.');
        }

        return array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $this->transport($camera),
            '-thread_queue_size',
            (string) config('ffmpeg.live.thread_queue_size', 1024),
        ], $this->liveInputTimeoutArguments(), [
            '-rtbufsize',
            (string) config('ffmpeg.live.rtbufsize', '64M'),
            '-fflags',
            (string) config('ffmpeg.live.input_fflags', '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            config('ffmpeg.live.use_wallclock_timestamps', true) ? '1' : '0',
            '-analyzeduration',
            (string) config('ffmpeg.live.input_analyze_duration', 1000000),
            '-probesize',
            (string) config('ffmpeg.live.input_probe_size', 131072),
            '-i',
            $this->authenticatedUri($camera, $profile),
            '-map',
            '0:v:0',
            '-an',
            '-vf',
            'fps='.(string) config('ffmpeg.live.wall_fps', 4),
            '-q:v',
            (string) config('ffmpeg.live.wall_mjpeg_quality', 7),
            '-f',
            'mpjpeg',
            '-boundary_tag',
            self::MJPEG_BOUNDARY,
            'pipe:1',
        ]);
    }

    /**
     * @param  array<string, string|null>  $profile
     * @return array<int, string>
     */
    private function buildRelayCommand(Camera $camera, array $profile): array
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
        $fpsMode = trim((string) config('ffmpeg.live.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.live.avoid_negative_ts', 'make_zero'));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the live streaming configuration first.');
        }

        return array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $this->transport($camera),
            '-thread_queue_size',
            (string) config('ffmpeg.live.thread_queue_size', 1024),
        ], $this->liveInputTimeoutArguments(), [
            '-rtbufsize',
            (string) config('ffmpeg.live.rtbufsize', '64M'),
            '-fflags',
            (string) config('ffmpeg.live.input_fflags', '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            config('ffmpeg.live.use_wallclock_timestamps', true) ? '1' : '0',
            '-analyzeduration',
            (string) config('ffmpeg.live.input_analyze_duration', 1000000),
            '-probesize',
            (string) config('ffmpeg.live.input_probe_size', 131072),
            '-i',
            $this->authenticatedUri($camera, $profile),
            '-map',
            '0:v:0',
            '-an',
            '-fps_mode',
            $fpsMode !== '' ? $fpsMode : 'passthrough',
            '-avoid_negative_ts',
            $avoidNegativeTs !== '' ? $avoidNegativeTs : 'make_zero',
            '-c:v',
            'copy',
            '-copyinkf',
            '-max_muxing_queue_size',
            (string) config('ffmpeg.live.max_muxing_queue_size', 1024),
            '-movflags',
            '+cmaf+frag_keyframe+empty_moov+default_base_moof',
            '-frag_duration',
            (string) config('ffmpeg.live.relay_fragment_duration', 500000),
            '-f',
            'mp4',
            'pipe:1',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function liveInputTimeoutArguments(): array
    {
        return [
            '-timeout',
            (string) config('ffmpeg.live.rw_timeout', 10000000),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array{index: int|null, profile: array<string, string|null>}  $selection
     * @return array<string, string>
     */
    private function defaultHeaders(array $headers, array $selection): array
    {
        return array_merge([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Live-Profile-Name' => $this->stringOrNull($selection['profile']['name'] ?? null) ?? 'Unnamed profile',
        ], $headers);
    }

    /**
     * @param  array<int, string>  $command
     * @param  array{index: int|null, profile: array<string, string|null>}  $selection
     */
    private function streamProcessOutput(array $command, Camera $camera, string $mode, array $selection): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, base_path());

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ffmpeg for the live stream.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';

        try {
            while (true) {
                $read = [];

                if (!feof($pipes[1])) {
                    $read[] = $pipes[1];
                }

                if (!feof($pipes[2])) {
                    $read[] = $pipes[2];
                }

                if ($read === []) {
                    $status = proc_get_status($process);

                    if (!($status['running'] ?? false)) {
                        break;
                    }

                    if (connection_aborted()) {
                        proc_terminate($process);
                        break;
                    }

                    usleep(25000);

                    continue;
                }

                $write = null;
                $except = null;
                $selectedStreams = @stream_select($read, $write, $except, 1, 0);

                if ($selectedStreams === false) {
                    break;
                }

                if ($selectedStreams === 0) {
                    if (connection_aborted()) {
                        proc_terminate($process);
                        break;
                    }

                    $status = proc_get_status($process);

                    if (!($status['running'] ?? false)) {
                        break;
                    }

                    continue;
                }

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);

                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $this->emitChunk($chunk);

                        continue;
                    }

                    $stderr .= $chunk;
                }

                if (connection_aborted()) {
                    proc_terminate($process);
                    break;
                }
            }

            $this->drainRemainingOutput($pipes[1], true);
            $stderr .= $this->drainRemainingOutput($pipes[2], false);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $exitCode = proc_close($process);

            if ($exitCode !== 0 && !connection_aborted()) {
                Log::warning('Live camera stream process exited with an error.', [
                    'camera_id' => $camera->getKey(),
                    'camera_name' => $camera->name,
                    'mode' => $mode,
                    'profile' => $selection['profile']['name'] ?? null,
                    'profile_index' => $selection['index'],
                    'exit_code' => $exitCode,
                    'message' => $this->summarizeErrorOutput($stderr),
                ]);
            }
        }
    }

    private function emitChunk(string $chunk): void
    {
        echo $chunk;

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }

    private function drainRemainingOutput(mixed $pipe, bool $emitOutput): string
    {
        if (!is_resource($pipe)) {
            return '';
        }

        $buffer = '';

        while (!feof($pipe)) {
            $chunk = fread($pipe, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            if ($emitOutput) {
                $this->emitChunk($chunk);

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @return array{index: int|null, profile: array<string, string|null>}
     */
    private function selectedProfileOrFail(Camera $camera, ?int $profileIndex = null): array
    {
        $selection = $this->selectWallProfile($camera, $profileIndex);

        if ($selection === null) {
            throw new RuntimeException('This camera does not have a usable RTSP stream yet. Refresh RTSP profiles first.');
        }

        return $selection;
    }

    /**
     * @return array{index: int, profile: array<string, string|null>}|null
     */
    private function selectExplicitProfile(Camera $camera, int $profileIndex): ?array
    {
        $profile = $camera->rtspProfiles()[$profileIndex] ?? null;

        if (!is_array($profile) || $this->stringOrNull($profile['uri'] ?? null) === null) {
            return null;
        }

        return [
            'index' => $profileIndex,
            'profile' => $profile,
        ];
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function isEfficientWallProfile(array $profile): bool
    {
        $haystack = strtolower(trim((string) ($profile['name'] ?? '').' '.(string) ($profile['token'] ?? '').' '.(string) ($profile['resolution'] ?? '').' '.(string) ($profile['video_resolution'] ?? '')));

        return preg_match('/(sub|minor|secondary|low|mobile|extra)/', $haystack) === 1;
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function profilePixelCount(array $profile): ?int
    {
        $resolution = $this->stringOrNull($profile['video_resolution'] ?? $profile['resolution'] ?? null);

        if ($resolution === null || !preg_match('/^(\d+)x(\d+)$/i', $resolution, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * ((int) $matches[2]);
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function authenticatedUri(Camera $camera, array $profile): string
    {
        $uri = $this->stringOrNull($profile['uri'] ?? null);

        if ($uri === null) {
            throw new RuntimeException('The selected RTSP profile does not contain a usable URI.');
        }

        $parts = parse_url($uri);

        if (!is_array($parts) || isset($parts['user']) || $camera->username === null || $camera->username === '' || $camera->password === null || $camera->password === '') {
            return $uri;
        }

        $authority = rawurlencode($camera->username).':'.rawurlencode($camera->password).'@'.$parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return ($parts['scheme'] ?? 'rtsp').'://'.$authority.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function transport(Camera $camera): string
    {
        return in_array($camera->rtsp_transport, ['tcp', 'udp'], true) ? $camera->rtsp_transport : 'tcp';
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

    private function summarizeErrorOutput(string $stderr): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr);

        return $message === ''
            ? 'The live stream stopped without a diagnostic message.'
            : Str::limit($message, 220);
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
