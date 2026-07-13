<?php

namespace App\Services;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ContinuousRecordingSegmenterService
{
    use ResolvesConfiguredBinaries;

    public function __construct(
        private readonly CameraStorageService $storage,
        private readonly RecordingReviewAssetService $reviewAssets,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('recording.continuous.segmenter_enabled', true);
    }

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     * @return array{started: bool, running: bool, imported: int, pid: int|null}
     */
    public function syncCamera(Camera $camera, array $source): array
    {
        if (! $this->enabled()) {
            return [
                'started' => false,
                'running' => false,
                'imported' => 0,
                'pid' => null,
            ];
        }

        $lock = Cache::lock($this->syncLockKey($camera), $this->syncLockSeconds());

        if (! $lock->get()) {
            return [
                'started' => false,
                'running' => $this->isRunning($camera, $source),
                'imported' => 0,
                'pid' => $this->pid($camera),
            ];
        }

        try {
            $this->collapseDuplicateProcesses($camera);

            $started = false;

            if (! $this->isRunning($camera, $source)) {
                $this->stop($camera);
                $this->start($camera, $source);
                $started = true;

                $startupDelayMs = max(0, (int) config('recording.continuous.startup_delay_ms', 250));

                if ($startupDelayMs > 0) {
                    usleep($startupDelayMs * 1000);
                }
            }

            $running = $this->isRunning($camera, $source);
            $imported = $this->importSegments($camera, $source['index'], $running);

            return [
                'started' => $started,
                'running' => $running,
                'imported' => $imported,
                'pid' => $this->pid($camera),
            ];
        } finally {
            $lock->release();
        }
    }

    public function stopUnmanagedRecorders(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $runtimeDirectory = $this->runtimeDirectory();

        if (! is_dir($runtimeDirectory)) {
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
                && $camera->recording_mode === Camera::RECORDING_MODE_CONTINUOUS) {
                continue;
            }

            $legacyCamera = new Camera;
            $legacyCamera->id = $cameraId;
            $legacyCamera->exists = true;

            if ($this->stop($legacyCamera)) {
                $stopped++;
            }

            $this->importSegments($legacyCamera, null, false);
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
                Log::warning('Unable to stop a continuous recording segmenter process.', [
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

        if (! $this->processMatchesExpectedInstance($camera, $pid)) {
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
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     */
    public function handleLegacyQueuedRecording(Camera $camera, CameraRecording $recording, array $source): void
    {
        $result = $this->syncCamera($camera, $source);

        $recording->forceFill([
            'status' => CameraRecording::STATUS_SKIPPED,
            'ended_at' => $recording->ended_at ?? now()->utc(),
            'message' => Str::limit(
                'Continuous recording is now handled by the persistent segment muxer. '
                .($result['started'] ? 'Started the recorder process. ' : 'Recorder process already matched the current source. ')
                .'Imported '.$result['imported'].' completed segment'.($result['imported'] === 1 ? '' : 's').'.',
                240,
            ),
        ])->save();
    }

    private function runtimeDirectory(): string
    {
        return rtrim((string) config('recording.continuous.runtime_dir', storage_path('app/private/continuous-recorders')), '/');
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

    private function recordingDirectory(Camera $camera): string
    {
        $cameraRoot = $this->storage->ensureCameraDirectories($camera);

        return $cameraRoot.'/recordings';
    }

    private function outputPattern(Camera $camera): string
    {
        $suffix = trim((string) config('recording.continuous.file_suffix', 'continuous'));
        $extension = trim((string) config('recording.extension', 'mkv'));

        return $this->recordingDirectory($camera).'/%Y%m%d_%H%M%S-'.($suffix !== '' ? $suffix : 'continuous').'.'.$extension;
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
        File::ensureDirectoryExists($this->recordingDirectory($camera));
        @chmod($this->runtimeDirectory(), 02775);
        @chmod($this->recordingDirectory($camera), 02775);
        File::append($this->logPath($camera), '');
        @chmod($this->logPath($camera), 0664);

        $command = array_merge([
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
            '0:a:0?',
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
            (string) max(1, (int) config('recording.segment_seconds', 60)),
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
        ]);

        $shellCommand = sprintf(
            'export TZ=UTC; export FFMPEG_FAKE_NOW_UTC=%s; nohup %s >> %s 2>&1 & echo $!',
            escapeshellarg(now()->utc()->format('Ymd_His')),
            $this->shellJoin($command),
            escapeshellarg($this->logPath($camera)),
        );

        $process = new Process(['sh', '-lc', $shellCommand]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to start the continuous recording segmenter: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $pid = (int) trim($process->getOutput());

        if ($pid > 0) {
            File::put($this->pidPath($camera), (string) $pid);
            @chmod($this->pidPath($camera), 0664);
        }

        File::put($this->metaPath($camera), json_encode([
            'camera_id' => (int) $camera->getKey(),
            'source_index' => $source['index'],
            'source_signature' => $this->sourceSignature($source),
            'output_pattern' => $this->outputPattern($camera),
            'started_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        @chmod($this->metaPath($camera), 0664);
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

        if (! is_file($metaPath)) {
            return null;
        }

        $contents = file_get_contents($metaPath);

        if (! is_string($contents) || trim($contents) === '') {
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

        return $this->processArgsMatchExpectedInstance($camera, $args)
            && $this->processIsRunning($pid);
    }

    private function processArgsMatchExpectedInstance(Camera $camera, string $args): bool
    {
        return str_contains($args, (string) ($this->ffmpegBinary() ?? 'ffmpeg'))
            && str_contains($args, $this->outputPattern($camera));
    }

    /**
     * @return array<int>
     */
    private function matchingPids(Camera $camera): array
    {
        $process = new Process(['ps', '-eo', 'pid=,args=']);
        $process->setTimeout(3);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $pids = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            [$pid, $args] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
            $resolvedPid = (int) ($pid ?? 0);

            if ($resolvedPid < 1 || ! is_string($args)) {
                continue;
            }

            if (! $this->processArgsMatchExpectedInstance($camera, $args)
                || ! $this->processIsRunning($resolvedPid)) {
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
                Log::warning('Unable to stop a duplicate continuous recording segmenter process.', [
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

        if (! $process->isSuccessful()) {
            return [];
        }

        $cameraIds = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) as $args) {
            if (! is_string($args) || trim($args) === '') {
                continue;
            }

            if (! preg_match('#/cameras/(\d+)/recordings/%Y%m%d_%H%M%S-[^\s]+\.[^\s]+#', $args, $matches)) {
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
        return 'camera-recordings:continuous-segmenter:'.(int) $camera->getKey();
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

        if (! $process->isSuccessful()) {
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
            'segment_seconds' => (int) config('recording.segment_seconds', 60),
        ], JSON_THROW_ON_ERROR));
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
        $value = max(0, (float) config('recording.continuous.segment_time_delta', 0.05));

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

    private function ffprobeBinary(): ?string
    {
        return $this->resolveBinary(config('ffmpeg.ffprobe.binaries', []));
    }

    private function importSegments(Camera $camera, ?int $sourceProfileIndex, bool $recorderRunning): int
    {
        $files = $this->segmentFiles($camera);

        if ($files === []) {
            return 0;
        }

        $imported = 0;
        $cameraLastRecordedAt = $camera->recording_last_recorded_at instanceof Carbon
            ? $camera->recording_last_recorded_at->copy()->utc()
            : null;

        foreach ($files as $index => $file) {
            $isLast = $index === array_key_last($files);

            if ($recorderRunning && $isLast) {
                continue;
            }

            $scheduledFor = $this->timestampFromSegmentPath($file['path']);

            if (! $scheduledFor instanceof Carbon) {
                continue;
            }

            $nextStart = isset($files[$index + 1])
                ? $this->timestampFromSegmentPath($files[$index + 1]['path'])
                : null;
            $endedAt = $nextStart instanceof Carbon
                ? $nextStart->copy()->utc()->startOfSecond()
                : $scheduledFor->copy()->addSeconds($this->segmentDurationSecondsForTail($file['path']));
            $relativePath = $this->storage->recordingRelativePathFromAbsolute($file['path']);

            try {
                $this->storage->finalizeStagedWrite($relativePath, $file['path']);
            } catch (Throwable $exception) {
                Log::warning('Failed to move a continuous recording segment into the active camera storage disk.', [
                    'camera_id' => $camera->getKey(),
                    'relative_path' => $relativePath,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $recording = CameraRecording::query()->firstOrNew([
                'camera_id' => $camera->getKey(),
                'scheduled_for' => $scheduledFor,
            ]);
            $wasRecorded = $recording->exists
                && $recording->status === CameraRecording::STATUS_RECORDED
                && $recording->relative_path === $relativePath;

            $recording->forceFill([
                'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'status' => CameraRecording::STATUS_RECORDED,
                'message' => Str::limit('Recorded continuously via the FFmpeg segment muxer.', 240),
                'started_at' => $scheduledFor,
                'ended_at' => $endedAt,
                'relative_path' => $relativePath,
                'file_size_bytes' => $file['size'],
                'source_profile_index' => $sourceProfileIndex,
            ])->save();

            if (! $wasRecorded) {
                $this->dispatchReviewAssets($recording);
                $imported++;
            }

            if ($cameraLastRecordedAt === null || $endedAt->greaterThan($cameraLastRecordedAt)) {
                $cameraLastRecordedAt = $endedAt;
            }
        }

        if ($cameraLastRecordedAt instanceof Carbon) {
            $camera->forceFill([
                'recording_last_recorded_at' => $cameraLastRecordedAt,
            ])->save();
        }

        return $imported;
    }

    /**
     * @return array<int, array{path: string, size: int|null, timestamp: Carbon}>
     */
    private function segmentFiles(Camera $camera): array
    {
        $suffix = trim((string) config('recording.continuous.file_suffix', 'continuous'));
        $extension = trim((string) config('recording.extension', 'mkv'));
        $pattern = $this->recordingDirectory($camera).'/*-'.($suffix !== '' ? $suffix : 'continuous').'.'.$extension;
        $files = [];

        foreach (File::glob($pattern) as $path) {
            if (! is_file($path)) {
                continue;
            }

            $timestamp = $this->timestampFromSegmentPath($path);

            if (! $timestamp instanceof Carbon) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => is_file($path) ? filesize($path) ?: null : null,
                'timestamp' => $timestamp,
            ];
        }

        usort($files, static fn (array $left, array $right): int => $left['timestamp']->getTimestamp() <=> $right['timestamp']->getTimestamp());

        return $files;
    }

    private function timestampFromSegmentPath(string $path): ?Carbon
    {
        $suffix = preg_quote(trim((string) config('recording.continuous.file_suffix', 'continuous')) ?: 'continuous', '/');
        $extension = preg_quote(trim((string) config('recording.extension', 'mkv')), '/');

        if (! preg_match('/(\d{8}_\d{6})-'.$suffix.'\.'.$extension.'$/', basename($path), $matches)) {
            return null;
        }

        $timestamp = Carbon::createFromFormat('Ymd_His', $matches[1], 'UTC');

        return $timestamp instanceof Carbon ? $timestamp->startOfSecond() : null;
    }

    private function segmentDurationSecondsForTail(string $absolutePath): int
    {
        $ffprobeBinary = $this->ffprobeBinary();

        if ($ffprobeBinary === null) {
            return max(1, (int) config('recording.segment_seconds', 60));
        }

        try {
            $process = new Process([
                $ffprobeBinary,
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $absolutePath,
            ]);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                $duration = (float) trim($process->getOutput());

                if ($duration > 0) {
                    return max(1, (int) round($duration));
                }
            }
        } catch (Throwable) {
            // Fall back to configured segment size when ffprobe cannot inspect the clip.
        }

        return max(1, (int) config('recording.segment_seconds', 60));
    }

    private function dispatchReviewAssets(CameraRecording $recording): void
    {
        try {
            GenerateRecordingReviewAssetsJob::dispatch($recording->getKey())
                ->onQueue($this->reviewAssets->queueName());
        } catch (Throwable $exception) {
            Log::warning('Unable to dispatch review assets for a continuous recording segment.', [
                'recording_id' => $recording->getKey(),
                'camera_id' => $recording->camera_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
