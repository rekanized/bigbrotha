<?php

namespace App\Services;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Jobs\ProcessCameraRecordingJob;
use App\Models\Camera;
use App\Models\CameraMotionState;
use App\Models\CameraRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
        private readonly RecordingMotionDetectorService $motionDetector,
        private readonly ContinuousRecordingSegmenterService $continuousSegmenter,
        private readonly MotionRecordingSegmenterService $motionSegmenter,
    ) {
    }

    public function segmentDurationSeconds(): int
    {
        return (int) config('recording.segment_seconds', 60);
    }

    public function motionPreRollSeconds(?Camera $camera = null): int
    {
        if ($camera instanceof Camera) {
            return $camera->motionPreRollSeconds();
        }

        return max(0, (int) config('recording.motion.pre_roll_seconds', 8));
    }

    public function motionPostTriggerSeconds(?Camera $camera = null): int
    {
        if ($camera instanceof Camera) {
            return $camera->motionPostTriggerSeconds();
        }

        return max(1, (int) config('recording.motion.post_trigger_seconds', 20));
    }

    public function motionAnalysisSeconds(): int
    {
        return max(3, (int) config('recording.motion.analysis_seconds', 5));
    }

    public function motionMonitoringSeconds(?Camera $camera = null): int
    {
        return $this->motionAnalysisSeconds() + $this->motionPostTriggerSeconds($camera);
    }

    public function motionSegmentSeconds(): int
    {
        return max(1, (int) config('recording.motion.segment_seconds', 1));
    }

    public function motionIdleBufferSeconds(?Camera $camera = null): int
    {
        return max(
            max(30, (int) config('recording.motion.idle_buffer_seconds', 180)),
            $this->motionPreRollSeconds($camera) + $this->motionPostTriggerSeconds($camera) + 30,
        );
    }

    public function queueName(): string
    {
        return (string) config('recording.queue', 'recordings');
    }

    public function recordingJobTimeoutSeconds(): int
    {
        return max(
            (int) config('recording.job_timeout_seconds', 240),
            $this->segmentDurationSeconds() + $this->motionAnalysisSeconds() + 90,
            $this->motionPipelineTimeoutSeconds() + 90,
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

    public function isMotionRecordingActive(Camera|int $camera): bool
    {
        $cameraId = $camera instanceof Camera ? (int) $camera->getKey() : (int) $camera;

        return CameraMotionState::query()
            ->where('camera_id', $cameraId)
            ->whereNotNull('active_recording_id')
            ->exists()
            || Cache::has($this->motionRecordingCacheKey($camera));
    }

    public function hasPendingMotionRecording(Camera|int $camera): bool
    {
        $cameraId = $camera instanceof Camera ? (int) $camera->getKey() : (int) $camera;

        return CameraRecording::query()
            ->where('camera_id', $cameraId)
            ->where('capture_mode', Camera::RECORDING_MODE_MOTION)
            ->whereIn('status', CameraRecording::pendingStatuses())
            ->exists();
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
            ->where('capture_mode', '!=', Camera::RECORDING_MODE_CONTINUOUS)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($recordings) use (&$recovered): void {
                foreach ($recordings as $recording) {
                    $ageSeconds = $recording->updated_at instanceof Carbon
                        ? $recording->updated_at->diffInSeconds(now()->utc())
                        : $this->stalePendingSeconds();

                    if ($recording->capture_mode === Camera::RECORDING_MODE_MOTION
                        && $this->shouldDiscardTransientMotionRecording($recording)) {
                        $this->discardTransientMotionRecording(
                            $recording,
                            'Discarded a stale pending motion evaluation after '.$ageSeconds.' seconds without progress.',
                            [
                                'stale_age_seconds' => $ageSeconds,
                                'previous_status' => $recording->status,
                            ],
                        );

                        continue;
                    }

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
            (string) config('ffmpeg.recording.rw_timeout', 20000000),
        ];
    }

    /**
     * @return array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    public function resolveRecordingSource(Camera $camera, ?int $profileIndex = null): ?array
    {
        $profiles = $camera->rtspProfiles();
        $selectedIndex = $profileIndex ?? $camera->recording_profile_index;

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
     * @return array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}
     */
    public function detectMotion(Camera $camera, array $source): array
    {
        return $this->motionDetector->detect($camera, $source);
    }

    /**
     * @return array{started: bool, finalized: int, running: bool}
     */
    public function syncMotionRecorder(Camera $camera, ?CameraRecording $preferredRecording = null): array
    {
        if (!$camera->hasRecordingEnabled() || $camera->recording_mode !== Camera::RECORDING_MODE_MOTION) {
            $this->motionSegmenter->stop($camera);

            return [
                'started' => false,
                'finalized' => 0,
                'running' => false,
            ];
        }

        $source = $this->resolveRecordingSource($camera);

        if ($source === null) {
            $this->motionSegmenter->stop($camera);

            if ($preferredRecording instanceof CameraRecording && $preferredRecording->isPending()) {
                $this->markRecordingFailed($preferredRecording, 'No RTSP source is available for this camera recording.');
            }

            return [
                'started' => false,
                'finalized' => 0,
                'running' => false,
            ];
        }

        $captureSource = $this->preferredMotionCaptureSource($camera, $source);

        try {
            $segmenter = $this->motionSegmenter->syncCamera($camera, $captureSource);
        } catch (Throwable $exception) {
            if ($preferredRecording instanceof CameraRecording && $preferredRecording->isPending()) {
                $this->markRecordingFailed(
                    $preferredRecording,
                    $this->summarizeThrowable($exception, 'Unable to start the rolling motion recorder.'),
                );
            } else {
                $this->safeLog('warning', 'Unable to start the rolling motion recorder.', [
                    'camera_id' => $camera->getKey(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->safeReport($exception);
            }

            return [
                'started' => false,
                'finalized' => 0,
                'running' => false,
            ];
        }

        $state = $this->motionState($camera);
        $finalized = 0;
        $started = false;
        $activeRecording = $this->activeMotionRecording($state);

        if ($activeRecording === null && $state->active_recording_id !== null) {
            $this->clearMotionState($state);
            $state->refresh();
        }

        $segments = $this->motionSegmenter->closedSegmentsSince($camera, $state->last_processed_segment_at, $segmenter['running']);

        foreach ($segments as $segment) {
            if ($activeRecording instanceof CameraRecording
                && $state->finalize_after instanceof Carbon
                && $segment['started_at']->greaterThanOrEqualTo($state->finalize_after)) {
                if ($this->finalizeMotionRecording($camera, $state, $activeRecording, $segmenter['running'])) {
                    $finalized++;
                }

                $state->refresh();
                $activeRecording = $this->activeMotionRecording($state);
            }

            try {
                $motion = $this->motionDetector->detectClip($camera, $segment['path']);
            } catch (Throwable $exception) {
                $targetRecording = $activeRecording instanceof CameraRecording ? $activeRecording : $preferredRecording;

                if ($targetRecording instanceof CameraRecording && $targetRecording->isPending()) {
                    $this->markRecordingFailed(
                        $targetRecording,
                        $this->summarizeThrowable($exception, 'Unable to evaluate motion for this camera.'),
                    );
                } else {
                    $this->safeLog('warning', 'Unable to evaluate a buffered motion segment.', [
                        'camera_id' => $camera->getKey(),
                        'segment_path' => $segment['path'],
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                    $this->safeReport($exception);
                }

                if ($activeRecording instanceof CameraRecording) {
                    $this->clearMotionState($state);
                }

                return [
                    'started' => $started || $segmenter['started'],
                    'finalized' => $finalized,
                    'running' => $segmenter['running'],
                ];
            }

            if ($motion['detected']) {
                if (!$activeRecording instanceof CameraRecording) {
                    $activeRecording = $this->startMotionRecordingEvent($camera, $state, $source, $segment, $motion, $preferredRecording);
                    $started = true;
                } else {
                    $this->touchMotionRecordingEvent($camera, $state, $activeRecording, $source, $segment, $motion);
                }
            }

            $state->forceFill([
                'last_processed_segment_at' => $segment['started_at'],
            ])->save();
        }

        if ($activeRecording instanceof CameraRecording && $this->finalizeMotionRecording($camera, $state, $activeRecording, $segmenter['running'])) {
            $finalized++;
            $state->refresh();
            $activeRecording = $this->activeMotionRecording($state);
        }

        $keepFrom = $activeRecording instanceof CameraRecording
            ? (($state->event_started_at instanceof Carbon ? $state->event_started_at->copy()->utc() : $activeRecording->started_at?->copy()->utc())
                ?? now()->utc()->subSeconds($this->motionIdleBufferSeconds($camera)))
            : now()->utc()->subSeconds($this->motionIdleBufferSeconds($camera));

        $this->motionSegmenter->pruneSegments($camera, $keepFrom, $segmenter['running']);

        if ($preferredRecording instanceof CameraRecording
            && $preferredRecording->capture_mode === Camera::RECORDING_MODE_MOTION
            && $preferredRecording->isPending()
            && !$this->motionStateOwnsRecording($preferredRecording)) {
            $this->discardTransientMotionRecording($preferredRecording, 'Discarded a legacy queued motion row because no active motion event currently owns it.');
        }

        return [
            'started' => $started || $segmenter['started'],
            'finalized' => $finalized,
            'running' => $segmenter['running'],
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
            if ($this->shouldDiscardTransientMotionRecording($recording)) {
                $this->discardTransientMotionRecording($recording, 'Recording is no longer enabled for this camera.');

                return;
            }

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

        if ($recording->capture_mode === Camera::RECORDING_MODE_CONTINUOUS && $this->continuousSegmenter->enabled()) {
            $this->continuousSegmenter->handleLegacyQueuedRecording($camera, $recording, $source);

            return;
        }

        if ($recording->capture_mode === Camera::RECORDING_MODE_MOTION) {
            $this->syncMotionRecorder($camera, $recording);

            return;
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

    public function reconcileMissingRecordedFiles(?int $cameraId = null): int
    {
        $reconciled = 0;

        CameraRecording::query()
            ->when($cameraId !== null, function (Builder $query) use ($cameraId): void {
                $query->where('camera_id', $cameraId);
            })
            ->where('status', CameraRecording::STATUS_RECORDED)
            ->orderBy('id')
            ->chunkById(100, function ($recordings) use (&$reconciled): void {
                foreach ($recordings as $recording) {
                    if ($this->reconcileMissingRecordedFile($recording)) {
                        $reconciled++;
                    }
                }
            }, 'id');

        return $reconciled;
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
                        ->whereNotIn('status', CameraRecording::pendingStatuses())
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

    private function reconcileMissingRecordedFile(CameraRecording $recording): bool
    {
        if ($this->storage->recordingExists($recording->relative_path)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($recording): bool {
                $lockedRecording = CameraRecording::query()
                    ->whereKey($recording->getKey())
                    ->lockForUpdate()
                    ->first();

                if (!$lockedRecording instanceof CameraRecording || $lockedRecording->status !== CameraRecording::STATUS_RECORDED) {
                    return false;
                }

                if ($this->storage->recordingExists($lockedRecording->relative_path)) {
                    return false;
                }

                $this->reviewAssets->pruneForRecording($lockedRecording);

                $this->markRecording(
                    $lockedRecording,
                    CameraRecording::STATUS_FAILED,
                    'Saved recording file is missing from active storage. Marked failed by the hourly maintenance pass.',
                    [
                        'ended_at' => $lockedRecording->ended_at ?? now()->utc(),
                        'file_size_bytes' => null,
                    ],
                );

                return true;
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('Failed to reconcile a recorded row whose segment file is missing.', [
                'recording_id' => $recording->getKey(),
                'relative_path' => $recording->relative_path,
                'error' => $this->summarizeThrowable($exception, 'Unable to reconcile the missing recording file.'),
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
        $fileName = $this->playbackFileName($recording);

        return response()->stream(function () use ($absolutePath, $command, $recording): void {
            try {
                $this->streamPlaybackOutput($command, $recording);
            } finally {
                $this->storage->deleteTemporaryFile($absolutePath);
            }
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

        $bufferedPath = $this->bufferedPlaybackAbsolutePath($recording);
        $command = $this->buildBufferedPlaybackCommand($absolutePath, $bufferedPath);
        $process = new Process($command, base_path());
        $process->setTimeout($this->playbackTimeoutSeconds($recording));

        try {
            $process->run();

            if (!$process->isSuccessful() || !is_file($bufferedPath)) {
                Log::warning('Recorded playback buffer generation exited with an error.', [
                    'recording_id' => $recording->getKey(),
                    'camera_id' => $recording->camera_id,
                    'stderr' => Str::limit(trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? $process->getErrorOutput()), 500),
                ]);

                throw new RuntimeException($this->summarizeProcessFailure($process, 'Unable to prepare the buffered review playback segment.'));
            }
        } catch (Throwable $exception) {
            if (is_file($bufferedPath)) {
                @unlink($bufferedPath);
            }

            throw $exception;
        } finally {
            $this->storage->deleteTemporaryFile($absolutePath);
        }

        $response = response()->file($bufferedPath, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$this->playbackFileName($recording).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);

        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function captureSegment(Camera $camera, CameraRecording $recording, array $source, ?float $motionScore): void
    {
        $startedAt = now()->utc()->startOfSecond();
        $scheduledFor = $this->captureScheduledFor($camera, $recording, $startedAt);
        $absolutePath = $this->buildRecordingAbsolutePath($camera, $scheduledFor, $recording->capture_mode);
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

        $process = $this->captureStreamCopyClip($source, $durationSeconds, $absolutePath);

        if (!$process->isSuccessful() || !is_file($absolutePath)) {
            $this->markRecordingFailed(
                $recording,
                $this->summarizeProcessFailure($process, 'Unable to write the recording segment.'),
                $motionScore,
            );

            return;
        }

        clearstatcache(true, $absolutePath);
        $relativePath = $this->storage->recordingRelativePathFromAbsolute($absolutePath);
        $fileSize = is_file($absolutePath) ? filesize($absolutePath) : null;

        try {
            $this->storage->finalizeStagedWrite($relativePath, $absolutePath);
        } catch (Throwable $exception) {
            $this->markRecordingFailed(
                $recording,
                $this->summarizeThrowable($exception, 'Unable to move the recording segment into network storage.'),
                $motionScore,
            );

            return;
        }

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

        $this->dispatchReviewAssetGeneration($recording, $camera, 'Recorded '.$durationSeconds.' seconds to '.$relativePath.'.');

        if ($camera->recording_mode === Camera::RECORDING_MODE_CONTINUOUS && $this->shouldChainContinuousSegments()) {
            $this->dispatchContinuousFollowUp($camera->fresh(), $endedAt);
        }
    }

    /**
     * @param  array{path: string, started_at: Carbon, ended_at: Carbon}  $segment
     * @param  array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}  $motion
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     */
    private function startMotionRecordingEvent(
        Camera $camera,
        CameraMotionState $state,
        array $source,
        array $segment,
        array $motion,
        ?CameraRecording $preferredRecording = null,
    ): CameraRecording {
        $startedAt = $segment['started_at']->copy()->subSeconds($this->motionPreRollSeconds($camera))->utc()->startOfSecond();
        $scheduledFor = $this->nextAvailableScheduledFor($camera, $startedAt, $preferredRecording?->getKey());
        $recording = $preferredRecording instanceof CameraRecording
            && $preferredRecording->camera_id === $camera->getKey()
            && $preferredRecording->capture_mode === Camera::RECORDING_MODE_MOTION
            && $preferredRecording->isPending()
                ? $preferredRecording
                : CameraRecording::query()->create([
                    'camera_id' => $camera->getKey(),
                    'capture_mode' => Camera::RECORDING_MODE_MOTION,
                    'status' => CameraRecording::STATUS_QUEUED,
                    'scheduled_for' => $scheduledFor,
                    'message' => 'Detected motion in the rolling segment buffer.',
                ]);

        $this->markRecording(
            $recording,
            CameraRecording::STATUS_PROCESSING,
            'Detected motion in the rolling segment buffer. Keeping '.$this->motionPreRollSeconds($camera).' seconds of pre-roll context and extending the event until the trailing quiet window expires.',
            [
                'scheduled_for' => $scheduledFor,
                'started_at' => $startedAt,
                'ended_at' => null,
                'relative_path' => null,
                'file_size_bytes' => null,
                'source_profile_index' => $source['index'],
                'motion_score' => $motion['activity_ratio'],
            ],
        );

        $state->forceFill([
            'active_recording_id' => $recording->getKey(),
            'source_profile_index' => $source['index'],
            'event_started_at' => $startedAt,
            'last_motion_at' => $segment['ended_at'],
            'finalize_after' => $segment['ended_at']->copy()->addSeconds($this->motionPostTriggerSeconds($camera)),
        ])->save();

        $camera->forceFill([
            'recording_last_motion_at' => $segment['ended_at'],
        ])->save();

        return $recording->fresh() ?? $recording;
    }

    /**
     * @param  array{path: string, started_at: Carbon, ended_at: Carbon}  $segment
     * @param  array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}  $motion
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     */
    private function touchMotionRecordingEvent(
        Camera $camera,
        CameraMotionState $state,
        CameraRecording $recording,
        array $source,
        array $segment,
        array $motion,
    ): void {
        $startedAt = ($state->event_started_at instanceof Carbon ? $state->event_started_at->copy()->utc() : null)
            ?? ($recording->started_at instanceof Carbon ? $recording->started_at->copy()->utc() : null)
            ?? $segment['started_at']->copy()->subSeconds($this->motionPreRollSeconds($camera))->utc()->startOfSecond();
        $scheduledFor = $recording->scheduled_for instanceof Carbon
            ? $recording->scheduled_for->copy()->utc()
            : $this->nextAvailableScheduledFor($camera, $startedAt, $recording->getKey());
        $message = 'Motion is still active in the rolling segment buffer. The trailing quiet window now expires at '
            .$segment['ended_at']->copy()->addSeconds($this->motionPostTriggerSeconds($camera))->format('Y-m-d H:i:s').' UTC.';

        if ($recording->status !== CameraRecording::STATUS_PROCESSING || !$recording->started_at instanceof Carbon) {
            $this->markRecording($recording, CameraRecording::STATUS_PROCESSING, $message, [
                'scheduled_for' => $scheduledFor,
                'started_at' => $startedAt,
                'ended_at' => null,
                'relative_path' => null,
                'file_size_bytes' => null,
                'source_profile_index' => $source['index'],
                'motion_score' => max((float) ($recording->motion_score ?? 0), (float) $motion['activity_ratio']),
            ]);
        } else {
            $recording->forceFill([
                'source_profile_index' => $source['index'],
                'motion_score' => max((float) ($recording->motion_score ?? 0), (float) $motion['activity_ratio']),
                'message' => Str::limit($message, 240),
            ])->save();
        }

        $state->forceFill([
            'event_started_at' => $startedAt,
            'source_profile_index' => $source['index'],
            'last_motion_at' => $segment['ended_at'],
            'finalize_after' => $segment['ended_at']->copy()->addSeconds($this->motionPostTriggerSeconds($camera)),
        ])->save();

        $camera->forceFill([
            'recording_last_motion_at' => $segment['ended_at'],
        ])->save();
    }

    private function finalizeMotionRecording(
        Camera $camera,
        CameraMotionState $state,
        CameraRecording $recording,
        bool $recorderRunning,
    ): bool {
        $windowStart = ($state->event_started_at instanceof Carbon ? $state->event_started_at->copy()->utc() : null)
            ?? ($recording->started_at instanceof Carbon ? $recording->started_at->copy()->utc() : null)
            ?? ($recording->scheduled_for instanceof Carbon ? $recording->scheduled_for->copy()->utc() : null);
        $windowEnd = $state->finalize_after instanceof Carbon ? $state->finalize_after->copy()->utc() : null;

        if (!$windowStart instanceof Carbon || !$windowEnd instanceof Carbon) {
            return false;
        }

        $segments = $this->motionSegmenter->segmentsForWindow($camera, $windowStart, $windowEnd, $recorderRunning);

        if ($segments === []) {
            return false;
        }

        $coveredUntil = end($segments)['ended_at'] ?? null;

        if (!$coveredUntil instanceof Carbon || $coveredUntil->lessThan($windowEnd)) {
            return false;
        }

        $workspace = $this->motionWorkspacePath($recording);
        File::ensureDirectoryExists($workspace);
        $absolutePath = $this->buildRecordingAbsolutePath(
            $camera,
            $recording->scheduled_for instanceof Carbon ? $recording->scheduled_for->copy()->utc() : $windowStart,
            $recording->capture_mode,
        );

        try {
            $process = $this->concatMotionSegments($segments, $absolutePath, $workspace.'/segments.ffconcat');

            if (!$process->isSuccessful() || !is_file($absolutePath)) {
                $this->markRecordingProcessing(
                    $recording,
                    $this->summarizeProcessFailure($process, 'Unable to finalize the rolling motion event. Laravel will retry on the next scheduler tick.'),
                );

                return false;
            }

            clearstatcache(true, $absolutePath);
            $relativePath = $this->storage->recordingRelativePathFromAbsolute($absolutePath);
            $fileSize = is_file($absolutePath) ? filesize($absolutePath) : null;

            try {
                $this->storage->finalizeStagedWrite($relativePath, $absolutePath);
            } catch (Throwable $exception) {
                $this->markRecordingProcessing(
                    $recording,
                    $this->summarizeThrowable($exception, 'Unable to move the finalized motion clip into active storage. Laravel will retry on the next scheduler tick.'),
                );

                return false;
            }

            $endedAt = $coveredUntil->copy()->utc();
            $lastMotionAt = $state->last_motion_at instanceof Carbon ? $state->last_motion_at->copy()->utc() : $endedAt;

            $this->markRecording(
                $recording,
                CameraRecording::STATUS_RECORDED,
                'Recorded a stitched motion event to '.$relativePath.' from the rolling segment buffer with '
                .$this->motionPreRollSeconds($camera).' seconds of pre-roll context and a dynamically extended trailing quiet window.',
                [
                    'relative_path' => $relativePath,
                    'file_size_bytes' => is_int($fileSize) ? $fileSize : null,
                    'ended_at' => $endedAt,
                ],
            );

            $camera->forceFill([
                'recording_last_motion_at' => $lastMotionAt,
                'recording_last_recorded_at' => $endedAt,
            ])->save();

            $this->dispatchReviewAssetGeneration($recording, $camera, 'Recorded a motion event to '.$relativePath.'.');
            $this->clearMotionState($state);

            return true;
        } finally {
            if (is_dir($workspace)) {
                File::deleteDirectory($workspace);
            }
        }
    }

    private function dispatchReviewAssetGeneration(CameraRecording $recording, Camera $camera, string $successMessage): void
    {
        try {
            GenerateRecordingReviewAssetsJob::dispatch($recording->getKey())
                ->onQueue($this->reviewAssets->queueName());
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            $recording->forceFill([
                'message' => Str::limit(
                    $successMessage.' Review asset dispatch failed: '
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
    }

    private function motionState(Camera $camera): CameraMotionState
    {
        return CameraMotionState::query()->firstOrCreate([
            'camera_id' => $camera->getKey(),
        ]);
    }

    private function activeMotionRecording(CameraMotionState $state): ?CameraRecording
    {
        if (!is_numeric($state->active_recording_id)) {
            return null;
        }

        return CameraRecording::query()->find((int) $state->active_recording_id);
    }

    private function clearMotionState(CameraMotionState $state): void
    {
        $state->forceFill([
            'active_recording_id' => null,
            'source_profile_index' => null,
            'event_started_at' => null,
            'last_motion_at' => null,
            'finalize_after' => null,
        ])->save();
    }

    private function motionStateOwnsRecording(CameraRecording $recording): bool
    {
        return CameraMotionState::query()
            ->where('camera_id', $recording->camera_id)
            ->where('active_recording_id', $recording->getKey())
            ->exists();
    }

    /**
     * @param  array<int, array{path: string, started_at: Carbon, ended_at: Carbon}>  $segments
     */
    private function concatMotionSegments(array $segments, string $absolutePath, string $manifestPath): Process
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
        $fpsMode = trim((string) config('ffmpeg.recording.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.recording.avoid_negative_ts', 'make_zero'));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        File::ensureDirectoryExists(dirname($manifestPath));
        File::ensureDirectoryExists(dirname($absolutePath));

        $manifestLines = ["ffconcat version 1.0"];

        foreach ($segments as $segment) {
            $manifestLines[] = "file '".str_replace("'", "'\\''", $segment['path'])."'";
        }

        File::put($manifestPath, implode("\n", $manifestLines)."\n");

        $process = new Process([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $manifestPath,
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
            $absolutePath,
        ]);
        $process->setTimeout(max(30, count($segments) * max(1, $this->motionSegmentSeconds()) + 30));
        $process->run();

        return $process;
    }

    private function captureStreamCopyClip(array $source, int $durationSeconds, string $absolutePath): Process
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
        $fpsMode = trim((string) config('ffmpeg.recording.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.recording.avoid_negative_ts', 'make_zero'));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        File::ensureDirectoryExists(dirname($absolutePath));

        $process = new Process(array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            $source['transport'],
            '-thread_queue_size',
            (string) config('ffmpeg.recording.thread_queue_size', 512),
        ], $this->recordingInputTimeoutArguments(), [
            '-rtbufsize',
            (string) config('ffmpeg.recording.rtbufsize', '64M'),
            '-fflags',
            (string) config('ffmpeg.recording.input_fflags', '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            config('ffmpeg.recording.use_wallclock_timestamps', true) ? '1' : '0',
            '-probesize',
            (string) config('ffmpeg.recording.input_probe_size', 262144),
            '-analyzeduration',
            (string) config('ffmpeg.recording.input_analyze_duration', 1000000),
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
            (string) max(1, $durationSeconds),
            '-fps_mode',
            $fpsMode !== '' ? $fpsMode : 'passthrough',
            '-avoid_negative_ts',
            $avoidNegativeTs !== '' ? $avoidNegativeTs : 'make_zero',
            '-c',
            'copy',
            '-copyinkf',
            '-max_muxing_queue_size',
            (string) config('ffmpeg.recording.max_muxing_queue_size', 1024),
            $absolutePath,
        ]));
        $process->setTimeout(max(30, $durationSeconds + 30));
        $process->run();

        return $process;
    }

    /**
     * @param  array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $source
     * @return array{index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}
     */
    private function preferredMotionCaptureSource(Camera $camera, array $source): array
    {
        if (!(bool) config('recording.motion.use_relay_source', false)) {
            return $source;
        }

        $readerUser = trim((string) config('mediamtx.auth.reader_user', ''));
        $readerPass = trim((string) config('mediamtx.auth.reader_pass', ''));
        $internalBaseUrl = rtrim((string) config('mediamtx.rtsp.internal_base_url', ''), '/');

        if ($readerUser === '' || $readerPass === '' || $internalBaseUrl === '') {
            return $source;
        }

        $configuredProfileIndex = is_numeric($camera->recording_profile_index)
            ? (int) $camera->recording_profile_index
            : null;
        $path = $configuredProfileIndex === null
            ? 'camera-'.$camera->getKey().'-recording'
            : 'camera-'.$camera->getKey().'-recording-profile-'.$configuredProfileIndex;
        $relayUri = $this->injectCredentials($internalBaseUrl.'/'.$path, $readerUser, $readerPass);

        return [
            'index' => $source['index'],
            'profile' => $source['profile'],
            'authenticated_uri' => $relayUri,
            'transport' => 'tcp',
        ];
    }


    /**
     * @return array<int, string>
     */
    private function buildPlaybackCommand(string $absolutePath): array
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $fpsMode = trim((string) config('ffmpeg.playback.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.playback.avoid_negative_ts', 'make_zero'));

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
            '-fps_mode',
            $fpsMode !== '' ? $fpsMode : 'passthrough',
            '-avoid_negative_ts',
            $avoidNegativeTs !== '' ? $avoidNegativeTs : 'make_zero',
            '-c:v',
            'copy',
            '-copyinkf',
            '-c:a',
            'aac',
            '-b:a',
            (string) config('ffmpeg.playback.audio_bitrate', '128k'),
            '-af',
            (string) config('ffmpeg.playback.audio_resample', 'aresample=async=1:first_pts=0'),
            '-movflags',
            '+cmaf+frag_keyframe+empty_moov+default_base_moof',
            '-frag_duration',
            (string) config('ffmpeg.playback.fragment_duration', 500000),
            '-max_muxing_queue_size',
            (string) config('ffmpeg.playback.max_muxing_queue_size', 1024),
            '-f',
            'mp4',
            'pipe:1',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildBufferedPlaybackCommand(string $absolutePath, string $outputPath): array
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $fpsMode = trim((string) config('ffmpeg.playback.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.playback.avoid_negative_ts', 'make_zero'));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $absolutePath,
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
            '-c:v',
            'copy',
            '-copyinkf',
            '-c:a',
            'aac',
            '-b:a',
            (string) config('ffmpeg.playback.audio_bitrate', '128k'),
            '-af',
            (string) config('ffmpeg.playback.audio_resample', 'aresample=async=1:first_pts=0'),
            '-movflags',
            '+faststart',
            '-max_muxing_queue_size',
            (string) config('ffmpeg.playback.max_muxing_queue_size', 1024),
            '-f',
            'mp4',
            $outputPath,
        ];
    }

    private function playbackFileName(CameraRecording $recording): string
    {
        return Str::slug($recording->camera?->name ?: 'camera-recording').'-'.($recording->scheduled_for?->format('Ymd_His') ?? 'segment').'.mp4';
    }

    private function bufferedPlaybackAbsolutePath(CameraRecording $recording): string
    {
        $configuredTemporaryDirectory = trim((string) config('ffmpeg.temporary_directory', storage_path('app/private/ffmpeg-temp')));

        if ($configuredTemporaryDirectory !== '') {
            $configuredBufferDirectory = rtrim(str_replace('\\', '/', $configuredTemporaryDirectory), '/').'/recording-playback';

            if ($this->ensureWritableDirectory($configuredBufferDirectory)) {
                $configuredBufferedPath = $this->temporaryPlaybackFile($configuredBufferDirectory, 'review-'.$recording->getKey().'-');

                if ($configuredBufferedPath !== null) {
                    return $configuredBufferedPath;
                }
            }
        }

        $systemTemporaryDirectory = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');

        if (!is_dir($systemTemporaryDirectory) || !is_writable($systemTemporaryDirectory)) {
            throw new RuntimeException('Unable to prepare a writable temporary directory for buffered review playback.');
        }

        $bufferedPath = $this->temporaryPlaybackFile($systemTemporaryDirectory, 'bigbrothas-review-'.$recording->getKey().'-');

        if ($bufferedPath === null) {
            throw new RuntimeException('Unable to allocate a temporary file for buffered review playback.');
        }

        return $bufferedPath;
    }

    private function ensureWritableDirectory(string $directory): bool
    {
        try {
            File::ensureDirectoryExists($directory);
            @chmod($directory, 02775);
        } catch (Throwable) {
            return false;
        }

        clearstatcache(true, $directory);

        return is_dir($directory) && is_writable($directory);
    }

    private function temporaryPlaybackFile(string $directory, string $prefix): ?string
    {
        $bufferedPath = tempnam($directory, $prefix);

        if ($bufferedPath === false) {
            return null;
        }

        @chmod($bufferedPath, 0664);

        return $bufferedPath;
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

    private function motionPipelineTimeoutSeconds(?Camera $camera = null): int
    {
        return $this->motionPreRollSeconds($camera) + $this->motionMonitoringSeconds($camera);
    }

    private function motionWorkspacePath(CameraRecording $recording): string
    {
        $temporaryDirectory = rtrim((string) config('ffmpeg.temporary_directory', storage_path('app/private/ffmpeg-temp')), '/');

        return $temporaryDirectory.'/motion-recordings/'.$recording->getKey().'-'.Str::uuid();
    }

    private function motionRecordingCacheKey(Camera|int $camera): string
    {
        $cameraId = $camera instanceof Camera ? (int) $camera->getKey() : (int) $camera;

        return 'camera-recordings:motion-event:camera:'.$cameraId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function discardTransientMotionRecording(CameraRecording $recording, string $reason, array $context = []): void
    {
        $recordingId = $recording->getKey();
        $cameraId = $recording->camera_id;
        $scheduledFor = $recording->scheduled_for?->toIso8601String();

        $recording->delete();

        $this->safeLog('info', 'Discarded a transient motion recording row.', array_merge([
            'recording_id' => $recordingId,
            'camera_id' => $cameraId,
            'scheduled_for' => $scheduledFor,
            'reason' => Str::limit($reason, 240),
        ], $context));
    }

    private function shouldDiscardTransientMotionRecording(CameraRecording $recording): bool
    {
        return $recording->capture_mode === Camera::RECORDING_MODE_MOTION
            && $recording->relative_path === null
            && $recording->status !== CameraRecording::STATUS_RECORDED
            && !$this->motionStateOwnsRecording($recording);
    }

    private function acquireMotionRecordingState(Camera|int $camera, int $recordingId): bool
    {
        $cacheKey = $this->motionRecordingCacheKey($camera);
        $seconds = $this->motionPipelineTimeoutSeconds() + 30;
        $payload = [
            'recording_id' => $recordingId,
            'expires_at' => now()->utc()->addSeconds($this->motionPipelineTimeoutSeconds())->toIso8601String(),
        ];

        $existing = Cache::get($cacheKey);

        if (is_array($existing) && (int) ($existing['recording_id'] ?? 0) === $recordingId) {
            Cache::put($cacheKey, $payload, $seconds);

            return true;
        }

        return Cache::add($cacheKey, $payload, $seconds);
    }

    private function releaseMotionRecordingState(Camera|int $camera, int $recordingId): void
    {
        $payload = Cache::get($this->motionRecordingCacheKey($camera));

        if (!is_array($payload) || (int) ($payload['recording_id'] ?? 0) !== $recordingId) {
            return;
        }

        Cache::forget($this->motionRecordingCacheKey($camera));
    }

    private function playbackTimeoutSeconds(CameraRecording $recording): int
    {
        if ($recording->started_at instanceof Carbon && $recording->ended_at instanceof Carbon) {
            return max(45, $recording->ended_at->diffInSeconds($recording->started_at) + 45);
        }

        return max(45, $this->segmentDurationSeconds() + 45);
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