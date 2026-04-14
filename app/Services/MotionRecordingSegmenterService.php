<?php

namespace App\Services;

use App\Models\Camera;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class MotionRecordingSegmenterService
{
    use ResolvesConfiguredBinaries;

    public function enabled(): bool
    {
        return (bool) config('recording.motion.segmenter_enabled', true);
    }

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     * @return array{started: bool, running: bool, pid: int|null}
     */
    public function syncCamera(Camera $camera, array $source): array
    {
        if (!$this->enabled()) {
            return [
                'started' => false,
                'running' => false,
                'pid' => null,
            ];
        }

        $lock = Cache::lock($this->syncLockKey($camera), $this->syncLockSeconds());

        if (!$lock->get()) {
            return [
                'started' => false,
                'running' => $this->isRunning($camera, $source),
                'pid' => $this->pid($camera),
            ];
        }

        try {
            $this->collapseDuplicateProcesses($camera);

            $started = false;

            if (!$this->isRunning($camera, $source)) {
                $this->stop($camera);
                $this->start($camera, $source);
                $started = true;

                $startupDelayMs = max(0, (int) config('recording.motion.startup_delay_ms', 150));

                if ($startupDelayMs > 0) {
                    usleep($startupDelayMs * 1000);
                }
            }

            return [
                'started' => $started,
                'running' => $this->isRunning($camera, $source),
                'pid' => $this->pid($camera),
            ];
        } finally {
            $lock->release();
        }
    }

    public function stopUnmanagedRecorders(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $runtimeDirectory = $this->runtimeDirectory();

        if (!is_dir($runtimeDirectory)) {
            return 0;
        }

        $stopped = 0;

        $cameraIds = [];

        foreach (File::glob($runtimeDirectory.'/camera-*.json') as $metaPath) {
            if (preg_match('/camera-(\d+)\.json$/', $metaPath, $matches)) {
                $cameraIds[] = (int) $matches[1];
            }
        }

        $cameraIds = array_values(array_unique(array_merge($cameraIds, $this->runningCameraIds())));

        foreach ($cameraIds as $cameraId) {
            $camera = Camera::query()->find($cameraId);

            if ($camera instanceof Camera
                && $camera->is_enabled
                && $camera->supports_rtsp
                && $camera->recording_mode === Camera::RECORDING_MODE_MOTION) {
                continue;
            }

            $legacyCamera = new Camera();
            $legacyCamera->id = $cameraId;
            $legacyCamera->exists = true;

            if ($this->stop($legacyCamera)) {
                $stopped++;
            }
        }

        return $stopped;
    }

    public function stop(Camera $camera): bool
    {
        $pids = array_values(array_unique(array_filter([
            $this->pid($camera),
            ...$this->matchingPids($camera),
        ])));

        foreach ($pids as $pid) {
            try {
                $process = new Process(['sh', '-lc', 'kill '.(int) $pid.' >/dev/null 2>&1 || true']);
                $process->setTimeout(10);
                $process->run();
            } catch (Throwable $exception) {
                Log::warning('Unable to stop a motion recording segmenter process.', [
                    'camera_id' => $camera->getKey(),
                    'pid' => $pid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        File::delete([
            $this->pidPath($camera),
            $this->metaPath($camera),
        ]);

        return $pids !== [];
    }

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null  $source
     */
    public function isRunning(Camera $camera, ?array $source = null): bool
    {
        $pid = $this->pid($camera);

        if ($pid === null) {
            return false;
        }

        if (!$this->processMatchesExpectedInstance($camera, $pid)) {
            File::delete($this->pidPath($camera));

            return false;
        }

        if ($source === null) {
            return true;
        }

        $meta = $this->meta($camera);

        return is_array($meta)
            && hash_equals((string) ($meta['source_signature'] ?? ''), $this->sourceSignature($source));
    }

    /**
     * @return array<int, array{path: string, started_at: Carbon, ended_at: Carbon}>
     */
    public function closedSegmentsSince(Camera $camera, ?Carbon $after = null, bool $recorderRunning = true): array
    {
        $segments = $this->segmentRows($camera, $recorderRunning);

        if (!$after instanceof Carbon) {
            return $segments;
        }

        return array_values(array_filter($segments, static fn (array $segment): bool => $segment['started_at']->greaterThan($after)));
    }

    /**
     * @return array<int, array{path: string, started_at: Carbon, ended_at: Carbon}>
     */
    public function segmentsForWindow(Camera $camera, Carbon $windowStart, Carbon $windowEnd, bool $recorderRunning = true): array
    {
        return array_values(array_filter(
            $this->segmentRows($camera, $recorderRunning),
            static fn (array $segment): bool => $segment['ended_at']->greaterThan($windowStart)
                && $segment['started_at']->lessThan($windowEnd),
        ));
    }

    public function pruneSegments(Camera $camera, ?Carbon $keepFrom = null, bool $recorderRunning = true): int
    {
        $segments = $this->segmentRows($camera, $recorderRunning);

        if ($segments === []) {
            return 0;
        }

        $keepFrom = ($keepFrom ?? now()->utc()->subSeconds($this->idleBufferSeconds()))->copy()->utc();
        $deleted = 0;

        foreach ($segments as $segment) {
            if ($segment['ended_at']->greaterThan($keepFrom)) {
                continue;
            }

            if (@unlink($segment['path'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function runtimeDirectory(): string
    {
        return rtrim((string) config('recording.motion.runtime_dir', storage_path('app/private/motion-recorders')), '/');
    }

    private function cameraDirectory(Camera $camera): string
    {
        return $this->runtimeDirectory().'/camera-'.(int) $camera->getKey();
    }

    private function segmentDirectory(Camera $camera): string
    {
        return $this->cameraDirectory($camera).'/segments';
    }

    private function pidPath(Camera $camera): string
    {
        return $this->runtimeDirectory().'/camera-'.(int) $camera->getKey().'.pid';
    }

    private function metaPath(Camera $camera): string
    {
        return $this->runtimeDirectory().'/camera-'.(int) $camera->getKey().'.json';
    }

    private function logPath(Camera $camera): string
    {
        return $this->runtimeDirectory().'/camera-'.(int) $camera->getKey().'.log';
    }

    private function outputPattern(Camera $camera): string
    {
        return $this->segmentDirectory($camera).'/%Y%m%d_%H%M%S-buffer.'.trim((string) config('recording.extension', 'mkv'));
    }

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     */
    private function start(Camera $camera, array $source): void
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $fpsMode = trim((string) config('ffmpeg.recording.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.recording.avoid_negative_ts', 'make_zero'));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        File::ensureDirectoryExists($this->runtimeDirectory());
        File::deleteDirectory($this->segmentDirectory($camera));
        File::ensureDirectoryExists($this->segmentDirectory($camera));

        $command = [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $source['transport'],
            '-thread_queue_size',
            (string) config('ffmpeg.recording.thread_queue_size', 1024),
            '-timeout',
            (string) config('ffmpeg.recording.rw_timeout', 20000000),
            '-rtbufsize',
            (string) config('ffmpeg.recording.rtbufsize', '128M'),
            '-fflags',
            (string) config('ffmpeg.recording.input_fflags', '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            config('ffmpeg.recording.use_wallclock_timestamps', true) ? '1' : '0',
            '-probesize',
            (string) config('ffmpeg.recording.input_probe_size', 262144),
            '-analyzeduration',
            (string) config('ffmpeg.recording.input_analyze_duration', 1000000),
            '-i',
            $source['authenticated_uri'],
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-sn',
            '-dn',
            '-fps_mode',
            $fpsMode !== '' ? $fpsMode : 'passthrough',
            '-avoid_negative_ts',
            $avoidNegativeTs !== '' ? $avoidNegativeTs : 'make_zero',
            '-c',
            'copy',
            '-copyinkf',
            '-max_muxing_queue_size',
            (string) config('ffmpeg.recording.max_muxing_queue_size', 1024),
            '-f',
            'segment',
            '-segment_time',
            (string) $this->segmentSeconds(),
            '-segment_atclocktime',
            '1',
            '-segment_time_delta',
            $this->segmentTimeDelta(),
            '-reset_timestamps',
            '1',
            '-strftime',
            '1',
            '-segment_format',
            $this->segmentFormat(),
            $this->outputPattern($camera),
        ];

        $shellCommand = sprintf(
            'export TZ=UTC; export FFMPEG_FAKE_NOW_UTC=%s; nohup %s >> %s 2>&1 & echo $!',
            escapeshellarg(now()->utc()->format('Ymd_His')),
            $this->shellJoin($command),
            escapeshellarg($this->logPath($camera)),
        );

        $process = new Process(['sh', '-lc', $shellCommand]);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Unable to start the motion recording segmenter: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $pid = (int) trim($process->getOutput());

        if ($pid > 0) {
            File::put($this->pidPath($camera), (string) $pid);
        }

        File::put($this->metaPath($camera), json_encode([
            'camera_id' => (int) $camera->getKey(),
            'source_index' => $source['index'],
            'source_signature' => $this->sourceSignature($source),
            'output_pattern' => $this->outputPattern($camera),
            'started_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function pid(Camera $camera): ?int
    {
        $pidPath = $this->pidPath($camera);

        if (is_file($pidPath)) {
            $pid = (int) trim((string) file_get_contents($pidPath));

            if ($pid > 0 && $this->processMatchesExpectedInstance($camera, $pid)) {
                return $pid;
            }
        }

        $matchingPids = $this->matchingPids($camera);

        if ($matchingPids === []) {
            File::delete($pidPath);

            return null;
        }

        $pid = $matchingPids[0];
        File::ensureDirectoryExists($this->runtimeDirectory());
        File::put($pidPath, (string) $pid);

        return $pid > 0 ? $pid : null;
    }

    private function meta(Camera $camera): ?array
    {
        $metaPath = $this->metaPath($camera);

        if (!is_file($metaPath)) {
            return null;
        }

        $contents = file_get_contents($metaPath);

        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function processMatchesExpectedInstance(Camera $camera, int $pid): bool
    {
        $args = $this->readProcessArgs($pid);

        if ($args === null) {
            return false;
        }

        return str_contains($args, (string) ($this->ffmpegBinary() ?? 'ffmpeg'))
            && str_contains($args, $this->outputPattern($camera))
            && $this->processIsRunning($pid);
    }

    /**
     * @return array<int>
     */
    private function matchingPids(Camera $camera): array
    {
        $process = new Process(['ps', '-eo', 'pid=,args=']);
        $process->setTimeout(3);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $pids = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            [$pid, $args] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
            $resolvedPid = (int) ($pid ?? 0);

            if ($resolvedPid < 1 || !is_string($args)) {
                continue;
            }

            if (!$this->processMatchesExpectedInstance($camera, $resolvedPid)) {
                continue;
            }

            $pids[] = $resolvedPid;
        }

        sort($pids);

        return array_values(array_unique($pids));
    }

    private function collapseDuplicateProcesses(Camera $camera): void
    {
        $matchingPids = $this->matchingPids($camera);

        if ($matchingPids === []) {
            File::delete($this->pidPath($camera));

            return;
        }

        $primaryPid = $matchingPids[0];
        $pidFromFile = is_file($this->pidPath($camera)) ? (int) trim((string) file_get_contents($this->pidPath($camera))) : 0;

        if ($pidFromFile > 0 && in_array($pidFromFile, $matchingPids, true)) {
            $primaryPid = $pidFromFile;
        }

        foreach ($matchingPids as $pid) {
            if ($pid === $primaryPid) {
                continue;
            }

            try {
                $process = new Process(['sh', '-lc', 'kill '.(int) $pid.' >/dev/null 2>&1 || true']);
                $process->setTimeout(10);
                $process->run();
            } catch (Throwable $exception) {
                Log::warning('Unable to stop a duplicate motion recording segmenter process.', [
                    'camera_id' => $camera->getKey(),
                    'pid' => $pid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        File::ensureDirectoryExists($this->runtimeDirectory());
        File::put($this->pidPath($camera), (string) $primaryPid);
    }

    /**
     * @return array<int>
     */
    private function runningCameraIds(): array
    {
        $process = new Process(['ps', '-eo', 'args=']);
        $process->setTimeout(3);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $cameraIds = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) as $args) {
            if (!is_string($args) || trim($args) === '') {
                continue;
            }

            if (!preg_match('#/motion-recorders/camera-(\d+)/segments/%Y%m%d_%H%M%S-buffer\.[^\s]+#', $args, $matches)) {
                continue;
            }

            $cameraIds[] = (int) $matches[1];
        }

        sort($cameraIds);

        return array_values(array_unique($cameraIds));
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

    private function syncLockKey(Camera $camera): string
    {
        return 'camera-recordings:motion-segmenter:'.(int) $camera->getKey();
    }

    private function syncLockSeconds(): int
    {
        return max(15, min(180, (int) config('recording.lock_seconds', 180)));
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

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     */
    private function sourceSignature(array $source): string
    {
        return hash('sha256', json_encode([
            'index' => $source['index'],
            'uri' => $source['authenticated_uri'],
            'transport' => $source['transport'],
            'segment_seconds' => $this->segmentSeconds(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, string>
     */
    private function segmentFiles(Camera $camera): array
    {
        $directory = $this->segmentDirectory($camera);

        if (!is_dir($directory)) {
            return [];
        }

        $files = File::glob($directory.'/*.'.trim((string) config('recording.extension', 'mkv')));
        sort($files);

        return $files;
    }

    /**
     * @return array<int, array{path: string, started_at: Carbon, ended_at: Carbon}>
     */
    private function segmentRows(Camera $camera, bool $recorderRunning): array
    {
        $files = $this->segmentFiles($camera);

        if ($files === []) {
            return [];
        }

        $rows = [];

        foreach ($files as $index => $path) {
            $isLast = $index === array_key_last($files);

            if ($recorderRunning && $isLast) {
                continue;
            }

            $startedAt = $this->timestampFromSegmentPath($path);

            if (!$startedAt instanceof Carbon) {
                continue;
            }

            $nextStartedAt = isset($files[$index + 1]) ? $this->timestampFromSegmentPath($files[$index + 1]) : null;
            $endedAt = $nextStartedAt instanceof Carbon
                ? $nextStartedAt->copy()->utc()->startOfSecond()
                : $startedAt->copy()->addSeconds($this->segmentSeconds());

            $rows[] = [
                'path' => $path,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
            ];
        }

        return $rows;
    }

    private function timestampFromSegmentPath(string $path): ?Carbon
    {
        if (!preg_match('/(\d{8}_\d{6})-buffer\.[^.]+$/', basename($path), $matches)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd_His', $matches[1], 'UTC')->utc()->startOfSecond();
        } catch (Throwable) {
            return null;
        }
    }

    private function segmentSeconds(): int
    {
        return max(1, (int) config('recording.motion.segment_seconds', 1));
    }

    private function idleBufferSeconds(): int
    {
        return max(30, (int) config('recording.motion.idle_buffer_seconds', 180));
    }

    /**
     * @param  array<int, string>  $command
     */
    private function shellJoin(array $command): string
    {
        return implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $command));
    }

    private function segmentTimeDelta(): string
    {
        $value = max(0, (float) config('recording.motion.segment_time_delta', config('recording.continuous.segment_time_delta', 0.05)));

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function segmentFormat(): string
    {
        return match (strtolower(trim((string) config('recording.extension', 'mkv')))) {
            'mkv' => 'matroska',
            'mp4' => 'mp4',
            default => 'matroska',
        };
    }

    private function ffmpegBinary(): ?string
    {
        return $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
    }

}