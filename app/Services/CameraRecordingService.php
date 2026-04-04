<?php

namespace App\Services;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Jobs\ProcessCameraRecordingJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class CameraRecordingService
{
    public function __construct(
        private readonly CameraStorageService $storage,
        private readonly RecordingReviewAssetService $reviewAssets,
    ) {
    }

    public function segmentDurationSeconds(): int
    {
        return (int) config('recording.segment_seconds', 60);
    }

    public function queueName(): string
    {
        return (string) config('recording.queue', 'recordings');
    }

    public function recordingJobTimeoutSeconds(): int
    {
        return max(
            (int) config('recording.job_timeout_seconds', 240),
            $this->segmentDurationSeconds() + max(3, (int) config('recording.motion.analysis_seconds', 5)) + 90,
        );
    }

    public function stalePendingSeconds(): int
    {
        return max(
            (int) config('recording.stale_seconds', 420),
            $this->recordingJobTimeoutSeconds() + 120,
            (int) config('recording.lock_seconds', 180) + 120,
        );
    }

    public function continuityToleranceSeconds(): int
    {
        return max(1, min(10, (int) floor($this->segmentDurationSeconds() / 6)));
    }

    public function dispatchRecording(CameraRecording $recording, string $message): bool
    {
        $this->markRecordingQueued($recording, $message);

        try {
            ProcessCameraRecordingJob::dispatch($recording->getKey())
                ->onQueue($this->queueName());

            return true;
        } catch (Throwable $exception) {
            $this->markRecordingFailed(
                $recording,
                'Unable to dispatch the recording job. '.$this->summarizeThrowable($exception, 'The queue rejected the recording job.'),
            );

            $this->safeReport($exception);

            return false;
        }
    }

    public function recoverStalePendingRecordings(): int
    {
        $cutoff = now()->utc()->subSeconds($this->stalePendingSeconds());
        $recovered = 0;

        CameraRecording::query()
            ->whereIn('status', CameraRecording::pendingStatuses())
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($recordings) use (&$recovered): void {
                foreach ($recordings as $recording) {
                    $ageSeconds = $recording->updated_at instanceof Carbon
                        ? $recording->updated_at->diffInSeconds(now()->utc())
                        : $this->stalePendingSeconds();

                    if ($this->dispatchRecording(
                        $recording,
                        'Recovered a stale '.$recording->status.' segment after '.$ageSeconds.' seconds without progress.',
                    )) {
                        $recovered++;
                    }
                }
            }, 'id');

        return $recovered;
    }

    public function ensureContinuousRecordingQueued(Camera $camera, ?Carbon $now = null): ?CameraRecording
    {
        $now = ($now ?? now()->utc())->copy()->utc()->startOfSecond();

        $pendingRecording = CameraRecording::query()
            ->where('camera_id', $camera->getKey())
            ->whereIn('status', CameraRecording::pendingStatuses())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->first();

        if ($pendingRecording instanceof CameraRecording) {
            return null;
        }

        $latestRecording = $this->latestCameraRecording($camera);
        $scheduledFor = $latestRecording instanceof CameraRecording
            ? $this->nextContinuousSegmentStart($camera, $latestRecording, $now)
            : $now->copy();
        $scheduledFor = $this->nextAvailableScheduledFor($camera, $scheduledFor);

        $recording = CameraRecording::query()->firstOrCreate([
            'camera_id' => $camera->getKey(),
            'scheduled_for' => $scheduledFor,
        ], [
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'message' => 'Queued by the continuous recorder watchdog.',
        ]);

        if (!$recording->wasRecentlyCreated) {
            return null;
        }

        return $recording;
    }

    public function markRecordingQueued(CameraRecording $recording, string $message, ?float $motionScore = null): void
    {
        $attributes = [
            'started_at' => null,
            'ended_at' => null,
            'relative_path' => null,
            'file_size_bytes' => null,
        ];

        if ($motionScore !== null || $recording->motion_score !== null) {
            $attributes['motion_score'] = $motionScore;
        }

        $this->markRecording($recording, CameraRecording::STATUS_QUEUED, $message, $attributes);
    }

    public function markRecordingProcessing(CameraRecording $recording, string $message): void
    {
        $this->markRecording($recording, CameraRecording::STATUS_PROCESSING, $message, [
            'ended_at' => null,
        ]);
    }

    public function markRecordingFailed(CameraRecording $recording, string $message, ?float $motionScore = null): void
    {
        $attributes = [
            'ended_at' => $recording->ended_at ?? now()->utc(),
        ];

        if ($motionScore !== null || $recording->motion_score !== null) {
            $attributes['motion_score'] = $motionScore;
        }

        $this->markRecording($recording, CameraRecording::STATUS_FAILED, $message, $attributes);
    }

    public function ffmpegBinary(): ?string
    {
        return $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
    }

    /**
     * @return array<int, string>
     */
    public function recordingInputTimeoutArguments(): array
    {
        return [
            '-timeout',
            (string) config('ffmpeg.streaming.rw_timeout', 10000000),
        ];
    }

    /**
     * @return array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    public function resolveRecordingSource(Camera $camera): ?array
    {
        $profiles = $camera->rtspProfiles();
        $selectedIndex = $camera->recording_profile_index;

        if (is_int($selectedIndex) && isset($profiles[$selectedIndex]) && is_array($profiles[$selectedIndex])) {
            $uri = $this->stringOrNull($profiles[$selectedIndex]['uri'] ?? null);

            if ($uri !== null) {
                return [
                    'index' => $selectedIndex,
                    'profile' => $profiles[$selectedIndex],
                    'authenticated_uri' => $this->injectCredentials($uri, $camera->username, $camera->password),
                    'transport' => $this->transport($camera),
                ];
            }
        }

        foreach ($profiles as $index => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $uri = $this->stringOrNull($profile['uri'] ?? null);

            if ($uri === null) {
                continue;
            }

            return [
                'index' => $index,
                'profile' => $profile,
                'authenticated_uri' => $this->injectCredentials($uri, $camera->username, $camera->password),
                'transport' => $this->transport($camera),
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
                'path' => $camera->rtsp_path,
            ],
            'authenticated_uri' => $this->injectCredentials($endpoint, $camera->username, $camera->password),
            'transport' => $this->transport($camera),
        ];
    }

    /**
     * @return array{detected: bool, activity_ratio: float, selected_frames: int, total_frames: int}
     */
    public function detectMotion(Camera $camera, array $source): array
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        $analysisSeconds = max(3, (int) config('recording.motion.analysis_seconds', 5));
        $analysisFps = max(1, (int) config('recording.motion.analysis_fps', 3));
        $scaledWidth = max(96, (int) config('recording.motion.scaled_width', 320));
        $threshold = $this->sceneThreshold($camera->motion_sensitivity);
        $filter = implode(',', [
            $this->buildMotionCropFilter($camera),
            'fps='.$analysisFps,
            'scale='.$scaledWidth.':-2',
            'format=gray',
            'select=gt(scene\\,'.$this->formatDecimal($threshold).')',
            'showinfo',
        ]);

        $process = new Process(array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'info',
            '-rtsp_transport',
            $source['transport'],
        ], $this->recordingInputTimeoutArguments(), [
            '-probesize',
            (string) config('ffmpeg.streaming.input_probe_size', 32768),
            '-analyzeduration',
            (string) config('ffmpeg.streaming.input_analyze_duration', 0),
            '-t',
            (string) $analysisSeconds,
            '-i',
            $source['authenticated_uri'],
            '-an',
            '-sn',
            '-dn',
            '-vf',
            $filter,
            '-f',
            'null',
            '-',
        ]));
        $process->setTimeout($analysisSeconds + 15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($this->summarizeProcessFailure($process, 'Unable to evaluate motion for this camera.'));
        }

        $selectedFrames = preg_match_all('/showinfo/', $process->getErrorOutput()) ?: 0;
        $totalFrames = max(1, $analysisSeconds * $analysisFps);
        $activityRatio = min(1, $selectedFrames / $totalFrames);

        return [
            'detected' => $selectedFrames > 0,
            'activity_ratio' => round($activityRatio, 4),
            'selected_frames' => $selectedFrames,
            'total_frames' => $totalFrames,
        ];
    }

    public function processRecording(CameraRecording $recording): void
    {
        $recording->loadMissing('camera');
        $camera = $recording->camera;

        if (!$camera instanceof Camera) {
            $this->markRecordingFailed($recording, 'The camera record is no longer available.');

            return;
        }

        if (!$camera->hasRecordingEnabled()) {
            $this->markRecording($recording, CameraRecording::STATUS_SKIPPED, 'Recording is not enabled for this camera.', [
                'ended_at' => $recording->ended_at ?? now()->utc(),
            ]);

            return;
        }

        $source = $this->resolveRecordingSource($camera);

        if ($source === null) {
            $this->markRecordingFailed($recording, 'No RTSP source is available for this camera recording.');

            return;
        }

        $motionScore = null;

        if ($recording->capture_mode === Camera::RECORDING_MODE_MOTION) {
            $motion = $this->detectMotion($camera, $source);
            $motionScore = $motion['activity_ratio'];

            if (!$motion['detected']) {
                $this->markRecording($recording, CameraRecording::STATUS_SKIPPED, 'No motion crossed the configured threshold in the selected region.', [
                    'motion_score' => $motionScore,
                    'ended_at' => $recording->ended_at ?? now()->utc(),
                ]);

                return;
            }

            $camera->forceFill([
                'recording_last_motion_at' => now(),
            ])->save();
        }

        $this->captureSegment($camera, $recording, $source, $motionScore);
    }

    public function pruneExpiredRecordings(): int
    {
        $deleted = 0;

        $this->forEachExpiredRecording(function (CameraRecording $recording) use (&$deleted): void {
            if ($this->pruneRecording($recording)) {
                $deleted++;
            }
        });

        return $deleted;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function expiredRecordingAuditRows(?int $cameraId = null): array
    {
        $rows = [];

        $this->forEachExpiredRecording(function (CameraRecording $recording, Camera $camera, Carbon $cutoff) use (&$rows): void {
            $rows[] = [
                'recording_id' => (int) $recording->getKey(),
                'camera_id' => (int) $camera->getKey(),
                'camera_name' => (string) $camera->name,
                'retention_days' => max(1, (int) $camera->recording_retention_days),
                'created_at' => $recording->created_at instanceof Carbon
                    ? $recording->created_at->copy()->utc()->format('Y-m-d H:i:s')
                    : 'n/a',
                'ended_at' => $recording->ended_at instanceof Carbon
                    ? $recording->ended_at->copy()->utc()->format('Y-m-d H:i:s')
                    : 'n/a',
                'cutoff_at' => $cutoff->copy()->utc()->format('Y-m-d H:i:s'),
                'file_present' => $this->storage->resolveRecordingAbsolutePath($recording->relative_path) !== null ? 'yes' : 'no',
                'relative_path' => (string) ($recording->relative_path ?? ''),
            ];
        }, $cameraId);

        return $rows;
    }

    private function forEachExpiredRecording(callable $callback, ?int $cameraId = null, ?Carbon $now = null): void
    {
        $now = ($now ?? now()->utc())->copy()->utc();

        Camera::query()
            ->select(['id', 'name', 'recording_retention_days'])
            ->when($cameraId !== null, function (Builder $query) use ($cameraId): void {
                $query->whereKey($cameraId);
            })
            ->orderBy('id')
            ->chunkById(100, function ($cameras) use ($callback, $now): void {
                foreach ($cameras as $camera) {
                    $retentionDays = max(1, (int) $camera->recording_retention_days);
                    $cutoff = $now->copy()->subDays($retentionDays);

                    CameraRecording::query()
                        ->where('camera_id', $camera->getKey())
                        ->whereNotNull('ended_at')
                        ->whereNotNull('relative_path')
                        ->where('created_at', '<', $cutoff)
                        ->orderBy('id')
                        ->chunkById(100, function ($recordings) use ($callback, $camera, $cutoff): void {
                            foreach ($recordings as $recording) {
                                $callback($recording, $camera, $cutoff);
                            }
                        }, 'id');
                }
            });
    }

    private function pruneRecording(CameraRecording $recording): bool
    {
        try {
            return DB::transaction(function () use ($recording): bool {
                $lockedRecording = CameraRecording::query()
                    ->whereKey($recording->getKey())
                    ->lockForUpdate()
                    ->first();

                if (!$lockedRecording instanceof CameraRecording) {
                    return false;
                }

                if (!$this->storage->deleteRecordingFile($lockedRecording->relative_path)) {
                    Log::warning('Skipped pruning a camera recording because the segment file could not be deleted.', [
                        'recording_id' => $lockedRecording->getKey(),
                        'relative_path' => $lockedRecording->relative_path,
                    ]);

                    return false;
                }

                $this->reviewAssets->pruneForRecording($lockedRecording);

                return (bool) $lockedRecording->delete();
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('Failed to prune a camera recording.', [
                'recording_id' => $recording->getKey(),
                'relative_path' => $recording->relative_path,
                'error' => $this->summarizeThrowable($exception, 'Unable to prune the expired recording.'),
            ]);

            $this->safeReport($exception);

            return false;
        }
    }

    public function playbackResponse(CameraRecording $recording): StreamedResponse
    {
        $absolutePath = $this->storage->resolveRecordingAbsolutePath($recording->relative_path);

        if ($absolutePath === null) {
            throw new RuntimeException('The saved recording segment is not available on disk.');
        }

        $command = $this->buildPlaybackCommand($absolutePath);
        $fileName = Str::slug($recording->camera?->name ?: 'camera-recording').'-'.($recording->scheduled_for?->format('Ymd_His') ?? 'segment').'.mp4';

        return response()->stream(function () use ($command, $recording): void {
            $this->streamPlaybackOutput($command, $recording);
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function bufferedPlaybackResponse(CameraRecording $recording): Response
    {
        $absolutePath = $this->storage->resolveRecordingAbsolutePath($recording->relative_path);

        if ($absolutePath === null) {
            throw new RuntimeException('The saved recording segment is not available on disk.');
        }

        $command = $this->buildPlaybackCommand($absolutePath);
        $fileName = Str::slug($recording->camera?->name ?: 'camera-recording').'-'.($recording->scheduled_for?->format('Ymd_His') ?? 'segment').'.mp4';
        $process = new Process($command);
        $process->setTimeout($this->playbackTimeoutSeconds($recording));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($this->summarizeProcessFailure($process, 'Unable to load the saved recording clip.'));
        }

        $output = $process->getOutput();

        if ($output === '') {
            throw new RuntimeException('The saved recording clip did not produce playable output.');
        }

        $response = response($output, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => (string) strlen($output),
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);

        unset($output, $process, $command);

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }

        return $response;
    }

    private function captureSegment(Camera $camera, CameraRecording $recording, array $source, ?float $motionScore): void
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        $startedAt = now()->utc()->startOfSecond();
        $scheduledFor = $this->captureScheduledFor($camera, $recording, $startedAt);
        $absolutePath = $this->buildRecordingAbsolutePath($camera, $scheduledFor, $recording->capture_mode);
        $relativePath = $this->storage->recordingRelativePathFromAbsolute($absolutePath);
        $durationSeconds = $this->segmentDurationSeconds();
        $endedAt = $startedAt->copy()->addSeconds($durationSeconds);

        $this->markRecording($recording, CameraRecording::STATUS_PROCESSING, 'Capturing a '.$durationSeconds.' second recording segment.', [
            'scheduled_for' => $scheduledFor,
            'started_at' => $startedAt,
            'ended_at' => null,
            'relative_path' => null,
            'file_size_bytes' => null,
            'source_profile_index' => $source['index'],
            'motion_score' => $motionScore,
        ]);

        $process = new Process(array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $source['transport'],
        ], $this->recordingInputTimeoutArguments(), [
            '-probesize',
            (string) config('ffmpeg.streaming.input_probe_size', 32768),
            '-analyzeduration',
            (string) config('ffmpeg.streaming.input_analyze_duration', 0),
            '-y',
            '-i',
            $source['authenticated_uri'],
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-sn',
            '-dn',
            '-t',
            (string) $durationSeconds,
            '-c',
            'copy',
            $absolutePath,
        ]));
        $process->setTimeout($durationSeconds + 30);
        $process->run();

        if (!$process->isSuccessful() || !is_file($absolutePath)) {
            $this->markRecordingFailed(
                $recording,
                $this->summarizeProcessFailure($process, 'Unable to write the recording segment.'),
                $motionScore,
            );

            return;
        }

        clearstatcache(true, $absolutePath);
        $fileSize = is_file($absolutePath) ? filesize($absolutePath) : null;

        $this->markRecording($recording, CameraRecording::STATUS_RECORDED, 'Recorded '.$durationSeconds.' seconds to '.$relativePath.'.', [
            'scheduled_for' => $scheduledFor,
            'relative_path' => $relativePath,
            'file_size_bytes' => is_int($fileSize) ? $fileSize : null,
            'ended_at' => $endedAt,
            'motion_score' => $motionScore,
        ]);

        $camera->forceFill([
            'recording_last_recorded_at' => $endedAt,
        ])->save();

        try {
            GenerateRecordingReviewAssetsJob::dispatch($recording->getKey())
                ->onQueue($this->reviewAssets->queueName());
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            $recording->forceFill([
                'message' => Str::limit(
                    'Recorded '.$durationSeconds.' seconds to '.$relativePath.'. Review asset dispatch failed: '
                    .$this->summarizeThrowable($exception, 'the review asset job could not be queued.'),
                    240,
                ),
            ])->save();

            $this->safeLog('warning', 'Recording review asset dispatch failed after segment capture.', [
                'recording_id' => $recording->getKey(),
                'camera_id' => $camera->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        if ($camera->recording_mode === Camera::RECORDING_MODE_CONTINUOUS && $this->shouldChainContinuousSegments()) {
            $this->dispatchContinuousFollowUp($camera->fresh(), $endedAt);
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildPlaybackCommand(string $absolutePath): array
    {
        $ffmpegBinary = $this->ffmpegBinary();

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $absolutePath,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-sn',
            '-dn',
            '-c:v',
            'copy',
            '-c:a',
            'aac',
            '-b:a',
            '96k',
            '-movflags',
            '+cmaf+frag_keyframe+empty_moov+default_base_moof',
            '-frag_duration',
            (string) config('ffmpeg.streaming.relay_fragment_duration', 500000),
            '-muxdelay',
            '0',
            '-muxpreload',
            '0',
            '-f',
            'mp4',
            'pipe:1',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function markRecording(CameraRecording $recording, string $status, string $message, array $attributes = []): void
    {
        $previousStatus = $recording->status;

        $recording->forceFill(array_merge($attributes, [
            'status' => $status,
            'message' => Str::limit($message, 240),
        ]))->save();

        $context = [
            'recording_id' => $recording->getKey(),
            'camera_id' => $recording->camera_id,
            'from' => $previousStatus,
            'to' => $status,
            'scheduled_for' => $recording->scheduled_for?->toIso8601String(),
            'message' => $recording->message,
        ];

        if ($status === CameraRecording::STATUS_FAILED) {
            $this->safeLog('warning', 'Camera recording transitioned to a failed state.', $context);

            return;
        }

        $this->safeLog('info', 'Camera recording state advanced.', $context);
    }

    private function buildRecordingAbsolutePath(Camera $camera, Carbon $scheduledFor, string $mode): string
    {
        $fileName = $scheduledFor->format('Ymd_His').'-'.Str::slug($mode).'.'.config('recording.extension', 'mkv');

        return $this->storage->recordingAbsolutePath($camera, $scheduledFor, $fileName);
    }

    private function playbackTimeoutSeconds(CameraRecording $recording): int
    {
        if ($recording->started_at instanceof Carbon && $recording->ended_at instanceof Carbon) {
            return max(30, $recording->ended_at->diffInSeconds($recording->started_at) + 30);
        }

        return max(30, $this->segmentDurationSeconds() + 30);
    }

    private function buildMotionCropFilter(Camera $camera): string
    {
        $area = $camera->recordingMotionArea();

        return sprintf(
            'crop=w=iw*%s:h=ih*%s:x=iw*%s:y=ih*%s',
            $this->formatDecimal($area['width'] / 100),
            $this->formatDecimal($area['height'] / 100),
            $this->formatDecimal($area['x'] / 100),
            $this->formatDecimal($area['y'] / 100),
        );
    }

    private function sceneThreshold(int $sensitivity): float
    {
        $normalized = (max(1, min(100, $sensitivity)) - 1) / 99;

        return 0.18 - ($normalized * 0.16);
    }

    private function captureScheduledFor(Camera $camera, CameraRecording $recording, Carbon $startedAt): Carbon
    {
        if ($camera->recording_mode !== Camera::RECORDING_MODE_CONTINUOUS) {
            return ($recording->scheduled_for instanceof Carbon ? $recording->scheduled_for->copy()->utc() : $startedAt->copy())->startOfSecond();
        }

        return $this->nextAvailableScheduledFor($camera, $startedAt, $recording->getKey());
    }

    private function dispatchContinuousFollowUp(Camera $camera, Carbon $scheduledFor): void
    {
        if (!$camera->hasRecordingEnabled() || $camera->recording_mode !== Camera::RECORDING_MODE_CONTINUOUS) {
            return;
        }

        $scheduledFor = $this->nextAvailableScheduledFor($camera, $scheduledFor);
        $recording = CameraRecording::query()->firstOrCreate([
            'camera_id' => $camera->getKey(),
            'scheduled_for' => $scheduledFor,
        ], [
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'message' => 'Queued immediately after the previous continuous segment.',
        ]);

        if (!$recording->wasRecentlyCreated) {
            return;
        }

        $this->dispatchRecording(
            $recording,
            'Queued immediately after the previous continuous segment ended at '.$scheduledFor->format('Y-m-d H:i:s').' UTC.',
        );
    }

    private function latestCameraRecording(Camera $camera): ?CameraRecording
    {
        return CameraRecording::query()
            ->where('camera_id', $camera->getKey())
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->first();
    }

    private function nextContinuousSegmentStart(Camera $camera, CameraRecording $latestRecording, Carbon $now): Carbon
    {
        $candidate = $this->recordingExpectedEnd($latestRecording);
        $restartThreshold = $now->copy()->subSeconds($this->segmentDurationSeconds() + $this->continuityToleranceSeconds());

        if ($candidate->lessThan($restartThreshold)) {
            return $now->copy();
        }

        return $this->nextAvailableScheduledFor($camera, $candidate, $latestRecording->getKey());
    }

    private function recordingExpectedEnd(CameraRecording $recording): Carbon
    {
        if ($recording->ended_at instanceof Carbon) {
            return $recording->ended_at->copy()->utc()->startOfSecond();
        }

        $base = $recording->started_at instanceof Carbon
            ? $recording->started_at->copy()->utc()
            : ($recording->scheduled_for instanceof Carbon ? $recording->scheduled_for->copy()->utc() : now()->utc());

        return $base->startOfSecond()->addSeconds($this->segmentDurationSeconds());
    }

    private function nextAvailableScheduledFor(Camera|int $camera, Carbon $scheduledFor, ?int $ignoreRecordingId = null): Carbon
    {
        $cameraId = $camera instanceof Camera ? (int) $camera->getKey() : (int) $camera;
        $candidate = $scheduledFor->copy()->utc()->startOfSecond();

        while (CameraRecording::query()
            ->where('camera_id', $cameraId)
            ->where('scheduled_for', $candidate)
            ->when($ignoreRecordingId !== null, function (Builder $query) use ($ignoreRecordingId): void {
                $query->whereKeyNot($ignoreRecordingId);
            })
            ->exists()) {
            $candidate->addSecond();
        }

        return $candidate;
    }

    private function shouldChainContinuousSegments(): bool
    {
        return (string) config('queue.default', 'sync') !== 'sync';
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

    /**
     * @param  array<int, string>  $command
     */
    private function streamPlaybackOutput(array $command, CameraRecording $recording): void
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
            throw new RuntimeException('Unable to start ffmpeg for recorded playback.');
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
                        echo $chunk;

                        if (function_exists('ob_flush')) {
                            @ob_flush();
                        }

                        flush();

                        continue;
                    }

                    $stderr .= $chunk;
                }

                if (connection_aborted()) {
                    proc_terminate($process);
                    break;
                }
            }

            $remainingStdout = stream_get_contents($pipes[1]);

            if (is_string($remainingStdout) && $remainingStdout !== '') {
                echo $remainingStdout;

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            }

            $remainingStderr = stream_get_contents($pipes[2]);

            if (is_string($remainingStderr) && $remainingStderr !== '') {
                $stderr .= $remainingStderr;
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $exitCode = proc_close($process);

            if ($exitCode !== 0 && !connection_aborted()) {
                Log::warning('Recorded playback remux exited with an error.', [
                    'recording_id' => $recording->getKey(),
                    'camera_id' => $recording->camera_id,
                    'stderr' => Str::limit(trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr), 500),
                ]);
            }
        }
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

    private function summarizeProcessFailure(Process $process, string $fallback): string
    {
        $message = trim($process->getErrorOutput() ?: $process->getOutput());

        if ($message === '') {
            return $fallback;
        }

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 240);
    }

    private function summarizeThrowable(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = class_basename($exception);
        }

        if ($message === '') {
            return $fallback;
        }

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 240);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // Logging cannot be allowed to break the recording pipeline.
        }
    }

    private function safeReport(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Reporting cannot be allowed to break the recording pipeline.
        }
    }

    private function formatDecimal(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
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