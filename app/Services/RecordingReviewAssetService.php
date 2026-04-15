<?php

namespace App\Services;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\CameraRecording;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RecordingReviewAssetService
{
    use ResolvesConfiguredBinaries;

    private const ASSET_PIPELINE_VERSION = 8;

    public const STATUS_READY = 'ready';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MISSING = 'missing';

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $manifestCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $timelinePlaybackMetadataCache = [];

    public function __construct(
        private readonly CameraStorageService $storage,
    ) {
    }

    public function queueName(): string
    {
        return (string) config('recording.review_assets.queue', config('recording.queue', 'recordings'));
    }

    public function lockSeconds(): int
    {
        return max(60, (int) config('recording.review_assets.lock_seconds', 120));
    }

    public function dispatchSuppressionSeconds(): int
    {
        return max(
            $this->lockSeconds(),
            (int) config('recording.review_assets.dispatch_suppression_seconds', max($this->jobTimeoutSeconds() * 6, 900)),
        );
    }

    public function markQueued(int $recordingId): bool
    {
        return Cache::add(
            $this->queuedCacheKey($recordingId),
            now()->utc()->toIso8601String(),
            now()->addSeconds($this->dispatchSuppressionSeconds()),
        );
    }

    public function clearQueued(int $recordingId): void
    {
        Cache::forget($this->queuedCacheKey($recordingId));
    }

    public function isQueued(int $recordingId): bool
    {
        return Cache::has($this->queuedCacheKey($recordingId));
    }

    /**
     * @return array{ok: bool, jobs_scanned: int, jobs_matched: int, recordings_matched: int, recordings_with_duplicates: int, jobs_deleted: int, jobs_requeued: int, active_reserved_recordings: int, message: string}
     */
    public function reconcileQueuedJobs(bool $dryRun = false): array
    {
        if (!$this->usesDatabaseQueue()) {
            return [
                'ok' => false,
                'jobs_scanned' => 0,
                'jobs_matched' => 0,
                'recordings_matched' => 0,
                'recordings_with_duplicates' => 0,
                'jobs_deleted' => 0,
                'jobs_requeued' => 0,
                'active_reserved_recordings' => 0,
                'message' => 'Review-asset queue reconciliation requires the database queue driver and jobs table.',
            ];
        }

        $jobs = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'reserved_at', 'available_at', 'created_at'])
            ->where('payload', 'like', '%'.class_basename(GenerateRecordingReviewAssetsJob::class).'%')
            ->orderBy('id')
            ->get();

        $matchedJobs = 0;
        $groups = [];

        foreach ($jobs as $job) {
            $recordingId = $this->reviewAssetRecordingIdFromPayload((string) $job->payload);

            if ($recordingId === null) {
                continue;
            }

            $matchedJobs++;
            $groups[$recordingId] ??= [];
            $groups[$recordingId][] = $job;
        }

        $jobsDeleted = 0;
        $jobsRequeued = 0;
        $recordingsWithDuplicates = 0;
        $activeReservedRecordings = 0;
        $targetQueue = $this->queueName();

        foreach ($groups as $recordingId => $recordingJobs) {
            $reservedJobs = array_values(array_filter($recordingJobs, static fn (object $job): bool => $job->reserved_at !== null));
            $readyJobs = array_values(array_filter($recordingJobs, static fn (object $job): bool => $job->reserved_at === null));

            if (count($recordingJobs) > 1) {
                $recordingsWithDuplicates++;
            }

            if ($reservedJobs !== []) {
                $activeReservedRecordings++;
                $deleteIds = array_map(static fn (object $job): int => (int) $job->id, $readyJobs);

                if ($deleteIds !== []) {
                    $jobsDeleted += count($deleteIds);

                    if (!$dryRun) {
                        DB::table('jobs')->whereIn('id', $deleteIds)->delete();
                    }
                }

                continue;
            }

            usort($readyJobs, static function (object $left, object $right): int {
                $createdComparison = ((int) $left->created_at) <=> ((int) $right->created_at);

                if ($createdComparison !== 0) {
                    return $createdComparison;
                }

                return ((int) $left->id) <=> ((int) $right->id);
            });

            $keptJob = $readyJobs[0] ?? null;
            $duplicateJobs = array_slice($readyJobs, 1);
            $deleteIds = array_map(static fn (object $job): int => (int) $job->id, $duplicateJobs);

            if ($deleteIds !== []) {
                $jobsDeleted += count($deleteIds);

                if (!$dryRun) {
                    DB::table('jobs')->whereIn('id', $deleteIds)->delete();
                }
            }

            if ($keptJob !== null && (string) $keptJob->queue !== $targetQueue) {
                $jobsRequeued++;

                if (!$dryRun) {
                    DB::table('jobs')
                        ->where('id', (int) $keptJob->id)
                        ->update(['queue' => $targetQueue]);
                }
            }

            if (!$dryRun && $keptJob !== null) {
                Cache::put(
                    $this->queuedCacheKey((int) $recordingId),
                    now()->utc()->toIso8601String(),
                    now()->addSeconds($this->dispatchSuppressionSeconds()),
                );
            }
        }

        return [
            'ok' => true,
            'jobs_scanned' => $jobs->count(),
            'jobs_matched' => $matchedJobs,
            'recordings_matched' => count($groups),
            'recordings_with_duplicates' => $recordingsWithDuplicates,
            'jobs_deleted' => $jobsDeleted,
            'jobs_requeued' => $jobsRequeued,
            'active_reserved_recordings' => $activeReservedRecordings,
            'message' => 'Review-asset queue reconciliation complete.',
        ];
    }

    public function recordJobFailure(CameraRecording $recording, string $message): void
    {
        $manifestRelativePath = $this->manifestRelativePath($recording);
        $playbackRelativePath = $this->playbackRelativePath($recording);
        $previewRelativePath = $this->previewRelativePath($recording);
        $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);
        $manifestAbsolutePath = $this->manifestAbsolutePath($recording, true);
        $playbackAbsolutePath = $this->playbackAbsolutePath($recording, true);
        $previewAbsolutePath = $this->previewAbsolutePath($recording, true);
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording, true);

        if ($manifestRelativePath === null
            || $playbackRelativePath === null
            || $previewRelativePath === null
            || $scrubSpriteRelativePath === null
            || $manifestAbsolutePath === null
            || $playbackAbsolutePath === null
            || $previewAbsolutePath === null
            || $scrubSpriteAbsolutePath === null) {
            return;
        }

        $scrubManifest = $this->scrubManifest($recording, $scrubSpriteAbsolutePath);
        $manifest = [
            'status' => self::STATUS_FAILED,
            'version' => $this->assetVersion($recording),
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => $this->recordingDurationSeconds($recording),
            'playback_relative_path' => $playbackRelativePath,
            'playback_status' => self::STATUS_FAILED,
            'preview_relative_path' => $previewRelativePath,
            'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
            'preview_width' => $this->previewWidth(),
            'scrub_status' => $scrubManifest === null ? self::STATUS_MISSING : self::STATUS_FAILED,
            'error_message' => Str::limit($message, 240),
        ];

        if ($scrubManifest !== null) {
            $manifest = array_merge($manifest, $scrubManifest, [
                'scrub_error_message' => Str::limit($message, 240),
            ]);
        }

        file_put_contents($manifestAbsolutePath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->storage->finalizeStagedWrite($manifestRelativePath, $manifestAbsolutePath);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(CameraRecording $recording): ?array
    {
        $cacheKey = $this->recordingCacheKey($recording).':manifest';

        if (array_key_exists($cacheKey, $this->manifestCache)) {
            return $this->manifestCache[$cacheKey];
        }

        $manifestRelativePath = $this->manifestRelativePath($recording);

        if ($manifestRelativePath === null) {
            return $this->manifestCache[$cacheKey] = null;
        }

        $manifestAbsolutePath = $this->storage->resolveReviewAssetAbsolutePath($manifestRelativePath);

        if ($manifestAbsolutePath === null || !is_file($manifestAbsolutePath)) {
            return $this->manifestCache[$cacheKey] = null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifestAbsolutePath), true);
        } finally {
            $this->storage->deleteTemporaryFile($manifestAbsolutePath);
        }

        return $this->manifestCache[$cacheKey] = (is_array($decoded) ? $decoded : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function timelinePlaybackMetadata(CameraRecording $recording): array
    {
        $cacheKey = $this->recordingCacheKey($recording).':timeline';

        if (array_key_exists($cacheKey, $this->timelinePlaybackMetadataCache)) {
            return $this->timelinePlaybackMetadataCache[$cacheKey];
        }

        if ($this->shouldSkipTimelineManifestLookup($recording)) {
            return $this->timelinePlaybackMetadataCache[$cacheKey] = $this->networkTimelinePlaybackMetadata($recording);
        }

        $manifest = $this->manifest($recording);
        $versionCurrent = is_array($manifest) && ($manifest['version'] ?? null) === $this->assetVersion($recording);
        $manifestStatus = $versionCurrent && is_string($manifest['status'] ?? null)
            ? $manifest['status']
            : self::STATUS_MISSING;
        $scrubStatus = $versionCurrent && is_string($manifest['scrub_status'] ?? null)
            ? $manifest['scrub_status']
            : self::STATUS_MISSING;
        $previewRelativePath = is_string($manifest['preview_relative_path'] ?? null)
            ? $manifest['preview_relative_path']
            : $this->previewRelativePath($recording);
        $scrubSpriteRelativePath = is_string($manifest['scrub_sprite_relative_path'] ?? null)
            ? $manifest['scrub_sprite_relative_path']
            : $this->scrubSpriteRelativePath($recording);
        $scrubFrameCount = is_numeric($manifest['scrub_frame_count'] ?? null)
            ? (int) $manifest['scrub_frame_count']
            : $this->scrubFrameCount($recording);
        $scrubFrameIntervalSeconds = is_numeric($manifest['scrub_frame_interval_seconds'] ?? null)
            ? (int) $manifest['scrub_frame_interval_seconds']
            : $this->scrubFrameIntervalSeconds();
        $scrubFrameWidth = is_numeric($manifest['scrub_frame_width'] ?? null)
            ? (int) $manifest['scrub_frame_width']
            : $this->scrubFrameWidth();
        $scrubFrameHeight = is_numeric($manifest['scrub_frame_height'] ?? null)
            ? (int) $manifest['scrub_frame_height']
            : $this->scrubFrameHeight();
        $scrubColumns = is_numeric($manifest['scrub_columns'] ?? null)
            ? (int) $manifest['scrub_columns']
            : $this->scrubColumns();
        $scrubRows = is_numeric($manifest['scrub_rows'] ?? null)
            ? (int) $manifest['scrub_rows']
            : (int) ceil(max(1, $scrubFrameCount) / max(1, $scrubColumns));
        $previewAvailable = $versionCurrent && $manifestStatus === self::STATUS_READY && $previewRelativePath !== null;
        $scrubSpriteAvailable = $versionCurrent && $scrubStatus === self::STATUS_READY && $scrubSpriteRelativePath !== null;
        $thumbnailAvailable = $scrubSpriteAvailable;

        return $this->timelinePlaybackMetadataCache[$cacheKey] = [
            'status' => $previewAvailable ? self::STATUS_READY : $manifestStatus,
            'ready' => $previewAvailable,
            'preview_available' => $previewAvailable,
            'thumbnail_available' => $thumbnailAvailable,
            'scrub_status' => $scrubStatus,
            'scrub_sprite_available' => $scrubSpriteAvailable,
            'duration_seconds' => is_numeric($manifest['duration_seconds'] ?? null)
                ? (int) $manifest['duration_seconds']
                : $this->recordingDurationSeconds($recording),
            'scrub' => [
                'relative_path' => $scrubSpriteRelativePath,
                'frame_count' => $scrubFrameCount,
                'frame_interval_seconds' => $scrubFrameIntervalSeconds,
                'frame_width' => $scrubFrameWidth,
                'frame_height' => $scrubFrameHeight,
                'columns' => $scrubColumns,
                'rows' => $scrubRows,
                'available' => $scrubSpriteAvailable,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function networkTimelinePlaybackMetadata(CameraRecording $recording): array
    {
        $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);
        $scrubFrameCount = $this->scrubFrameCount($recording);
        $scrubFrameIntervalSeconds = $this->scrubFrameIntervalSeconds();
        $scrubFrameWidth = $this->scrubFrameWidth();
        $scrubFrameHeight = $this->scrubFrameHeight();
        $scrubColumns = $this->scrubColumns();
        $scrubRows = (int) ceil(max(1, $scrubFrameCount) / max(1, $scrubColumns));
        $scrubSpriteAvailable = $scrubSpriteRelativePath !== null
            && $scrubFrameCount > 0
            && (!$this->storage->reviewSpritesStoredLocally() || $this->storage->privateFileExists($scrubSpriteRelativePath));

        return [
            'status' => self::STATUS_MISSING,
            'ready' => false,
            'preview_available' => false,
            'thumbnail_available' => $scrubSpriteAvailable,
            'scrub_status' => $scrubSpriteAvailable ? self::STATUS_READY : self::STATUS_MISSING,
            'scrub_sprite_available' => $scrubSpriteAvailable,
            'duration_seconds' => $this->recordingDurationSeconds($recording),
            'scrub' => [
                'relative_path' => $scrubSpriteRelativePath,
                'frame_count' => $scrubFrameCount,
                'frame_interval_seconds' => $scrubFrameIntervalSeconds,
                'frame_width' => $scrubFrameWidth,
                'frame_height' => $scrubFrameHeight,
                'columns' => $scrubColumns,
                'rows' => $scrubRows,
                'available' => $scrubSpriteAvailable,
            ],
        ];
    }

    private function shouldSkipTimelineManifestLookup(CameraRecording $recording): bool
    {
        $relativePath = $this->storage->normalizePrivateStorageRelativePath($recording->relative_path);

        if ($relativePath === null) {
            return false;
        }

        return $this->storage->pathUsesNetworkStorage($relativePath);
    }

    /**
     * @return array<string, mixed>
     */
    public function assetState(CameraRecording $recording): array
    {
        $manifest = $this->manifest($recording);
        $playbackRelativePath = $this->playbackRelativePath($recording);
        $previewRelativePath = $this->previewRelativePath($recording);
        $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);
        $queued = $this->isQueued($recording->getKey());
        $versionCurrent = is_array($manifest) && ($manifest['version'] ?? null) === $this->assetVersion($recording);
        $manifestStatus = $versionCurrent && is_string($manifest['status'] ?? null) ? $manifest['status'] : self::STATUS_MISSING;
        $playbackStatus = $versionCurrent && is_string($manifest['playback_status'] ?? null) ? $manifest['playback_status'] : self::STATUS_MISSING;
        $scrubStatus = $versionCurrent && is_string($manifest['scrub_status'] ?? null) ? $manifest['scrub_status'] : self::STATUS_MISSING;
        $playbackFileAvailable = $this->assetFileAvailableForRequest($playbackRelativePath);
        $previewFileAvailable = $this->assetFileAvailableForRequest($previewRelativePath);
        $scrubSpriteFileAvailable = $this->assetFileAvailableForRequest($scrubSpriteRelativePath);
        $playbackAvailable = $versionCurrent && $playbackStatus === self::STATUS_READY && $playbackFileAvailable;
        $previewAvailable = $versionCurrent && $manifestStatus === self::STATUS_READY && $previewFileAvailable;
        $scrubSpriteAvailable = $versionCurrent && $scrubStatus === self::STATUS_READY && $scrubSpriteFileAvailable;
        $thumbnailAvailable = $scrubSpriteAvailable;
        $ready = $manifestStatus === self::STATUS_READY && $previewAvailable;

        return [
            'status' => $ready
                ? self::STATUS_READY
                : ($manifestStatus === self::STATUS_MISSING && $queued ? self::STATUS_PENDING : $manifestStatus),
            'ready' => $ready,
            'playback_status' => $playbackStatus === self::STATUS_MISSING && $queued ? self::STATUS_PENDING : $playbackStatus,
            'playback_available' => $playbackAvailable,
            'preview_available' => $previewAvailable,
            'thumbnail_available' => $thumbnailAvailable,
            'scrub_status' => $scrubStatus === self::STATUS_MISSING && $queued ? self::STATUS_PENDING : $scrubStatus,
            'scrub_sprite_available' => $scrubSpriteAvailable,
            'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : null,
            'version_current' => $versionCurrent,
            'generated_at' => is_string($manifest['generated_at'] ?? null) ? $manifest['generated_at'] : null,
            'duration_seconds' => is_numeric($manifest['duration_seconds'] ?? null)
                ? (int) $manifest['duration_seconds']
                : $this->recordingDurationSeconds($recording),
            'error_message' => is_string($manifest['error_message'] ?? null) ? $manifest['error_message'] : null,
        ];
    }

    public function hasReadyAssets(CameraRecording $recording, bool $requireScrubSprite = false): bool
    {
        return $this->assetStateSatisfiesRequirement(
            $this->assetState($recording),
            $recording,
            $requireScrubSprite,
        );
    }

    public function hasReadyPlaybackAsset(CameraRecording $recording): bool
    {
        $assetState = $this->assetState($recording);

        return ($assetState['playback_status'] ?? null) === self::STATUS_READY
            && (bool) ($assetState['playback_available'] ?? false);
    }

    public function ensureQueued(CameraRecording $recording, bool $requireScrubSprite = false): bool
    {
        if ($recording->status !== CameraRecording::STATUS_RECORDED || $recording->relative_path === null) {
            return false;
        }

        if ($this->isQueued($recording->getKey())) {
            return false;
        }

        $assetState = $this->assetState($recording);

        if ($this->assetStateSatisfiesRequirement($assetState, $recording, $requireScrubSprite) || (($assetState['status'] ?? null) === self::STATUS_PENDING)) {
            return false;
        }

        if (!$this->markQueued($recording->getKey())) {
            return false;
        }

        try {
            GenerateRecordingReviewAssetsJob::dispatch($recording->getKey())
                ->onQueue($this->queueName());

            return true;
        } catch (Throwable $exception) {
            $this->clearQueued($recording->getKey());
            Log::warning('Unable to queue review asset generation from a web request fallback.', [
                'recording_id' => $recording->getKey(),
                'camera_id' => $recording->camera_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForRecording(CameraRecording $recording): array
    {
        $sourceRelativePath = $this->preferredPlaybackSourceRelativePath($recording);
        $absoluteRecordingPath = $this->storage->resolveRecordingAbsolutePath(
            $sourceRelativePath,
            max(30, $this->jobTimeoutSeconds() - 30),
        );

        if ($absoluteRecordingPath === null) {
            throw new RuntimeException($this->storage->missingRecordingSegmentMessage($sourceRelativePath));
        }

        $targetRelativePath = $this->playbackRelativePath($recording);
        $targetPlaybackPath = $this->playbackAbsolutePath($recording, true);
        $playbackWorkspacePath = $this->playbackWorkspaceAbsolutePath($recording, $sourceRelativePath);

        if ($targetRelativePath === null || $targetPlaybackPath === null || $playbackWorkspacePath === null) {
            $this->storage->deleteTemporaryFile($absoluteRecordingPath);

            throw new RuntimeException('Unable to resolve the normalized recording storage paths.');
        }

        if ($sourceRelativePath === $targetRelativePath) {
            $existingManifest = $this->manifest($recording);
            $version = $this->assetVersion($recording);
            $previewRelativePath = $this->previewRelativePath($recording);
            $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);
            $expectedScrubStatus = $this->scrubFrameCount($recording) > 0 ? self::STATUS_READY : self::STATUS_MISSING;

            if (
                is_array($existingManifest)
                && ($existingManifest['version'] ?? null) === $version
                && ($existingManifest['playback_status'] ?? null) === self::STATUS_READY
                && ($existingManifest['status'] ?? null) === self::STATUS_READY
                && $this->storage->privateFileExists($targetRelativePath)
                && $previewRelativePath !== null
                && $this->storage->privateFileExists($previewRelativePath)
                && (
                    (($existingManifest['scrub_status'] ?? null) === self::STATUS_READY && $scrubSpriteRelativePath !== null && $this->storage->privateFileExists($scrubSpriteRelativePath))
                    || (($existingManifest['scrub_status'] ?? null) === self::STATUS_MISSING && $expectedScrubStatus === self::STATUS_MISSING)
                )
            ) {
                $this->storage->deleteTemporaryFile($absoluteRecordingPath);

                return $existingManifest;
            }
        }

        $playbackProcess = new Process($this->buildPlaybackAssetCommand($absoluteRecordingPath, $playbackWorkspacePath));
        $playbackProcess->setTimeout(max(60, $this->recordingDurationSeconds($recording) + 90));
        $playbackProcess->run();

        if (!$playbackProcess->isSuccessful() || !is_file($playbackWorkspacePath)) {
            $this->storage->deleteTemporaryFile($absoluteRecordingPath);
            $this->storage->deleteTemporaryFile($playbackWorkspacePath);

            throw new RuntimeException($this->summarizeProcessFailure($playbackProcess, 'Unable to generate the browser playback video.'));
        }

        $this->promotePlaybackWorkspaceOutput($playbackWorkspacePath, $targetPlaybackPath);
        clearstatcache(true, $targetPlaybackPath);

        $normalizedFileSize = is_file($targetPlaybackPath) ? filesize($targetPlaybackPath) : null;

        $recording->forceFill([
            'relative_path' => $targetRelativePath,
            'file_size_bytes' => is_int($normalizedFileSize) ? $normalizedFileSize : $recording->file_size_bytes,
        ])->save();
        $recording = $recording->fresh() ?? $recording;

        $version = $this->assetVersion($recording);
        $manifestRelativePath = $this->manifestRelativePath($recording);
        $playbackRelativePath = $this->playbackRelativePath($recording);
        $previewRelativePath = $this->previewRelativePath($recording);
        $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);
        $previewAbsolutePath = $this->previewAbsolutePath($recording, true);
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording, true);
        $manifestAbsolutePath = $this->manifestAbsolutePath($recording, true);

        if ($manifestRelativePath === null
            || $playbackRelativePath === null
            || $previewRelativePath === null
            || $scrubSpriteRelativePath === null
            || $previewAbsolutePath === null
            || $scrubSpriteAbsolutePath === null
            || $manifestAbsolutePath === null) {
            $this->storage->deleteTemporaryFile($absoluteRecordingPath);
            $this->storage->deleteTemporaryFile($targetPlaybackPath);

            throw new RuntimeException('Unable to resolve the review asset storage paths.');
        }

        $scrubManifest = $this->scrubManifest($recording, $scrubSpriteAbsolutePath);

        $pendingManifest = [
            'status' => self::STATUS_PENDING,
            'version' => $version,
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => $this->recordingDurationSeconds($recording),
            'playback_relative_path' => $playbackRelativePath,
            'playback_status' => self::STATUS_PENDING,
            'preview_relative_path' => $previewRelativePath,
            'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
            'preview_width' => $this->previewWidth(),
        ];

        if ($scrubManifest !== null) {
            $pendingManifest = array_merge($pendingManifest, $scrubManifest, [
                'scrub_status' => self::STATUS_PENDING,
            ]);
        } else {
            $pendingManifest['scrub_status'] = self::STATUS_MISSING;
        }

        file_put_contents($manifestAbsolutePath, json_encode($pendingManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $previewProcess = new Process($this->buildPreviewCommand($targetPlaybackPath, $previewAbsolutePath));
            $previewProcess->setTimeout(max(45, $this->recordingDurationSeconds($recording) + 45));
            $previewProcess->run();

            if (!$previewProcess->isSuccessful() || !is_file($previewAbsolutePath)) {
                throw new RuntimeException($this->summarizeProcessFailure($previewProcess, 'Unable to generate the review preview video.'));
            }

            $readyManifest = [
                'status' => self::STATUS_READY,
                'version' => $version,
                'generated_at' => now()->utc()->toIso8601String(),
                'duration_seconds' => $this->recordingDurationSeconds($recording),
                'playback_relative_path' => $playbackRelativePath,
                'playback_status' => self::STATUS_READY,
                'preview_relative_path' => $previewRelativePath,
                'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
                'preview_width' => $this->previewWidth(),
                'scrub_status' => self::STATUS_MISSING,
            ];

            if ($scrubManifest !== null) {
                try {
                    $scrubProcess = new Process($this->buildScrubSpriteCommand($targetPlaybackPath, $scrubSpriteAbsolutePath, $scrubManifest));
                    $scrubProcess->setTimeout(45);
                    $scrubProcess->run();

                    if (!$scrubProcess->isSuccessful() || !is_file($scrubSpriteAbsolutePath)) {
                        throw new RuntimeException($this->summarizeProcessFailure($scrubProcess, 'Unable to generate the scrub preview sprite.'));
                    }

                    $readyManifest = array_merge($readyManifest, $scrubManifest, [
                        'scrub_status' => self::STATUS_READY,
                    ]);
                } catch (\Throwable $exception) {
                    if (is_file($scrubSpriteAbsolutePath)) {
                        @unlink($scrubSpriteAbsolutePath);
                    }

                    $readyManifest['scrub_status'] = self::STATUS_FAILED;
                    $readyManifest['scrub_error_message'] = Str::limit($exception->getMessage(), 240);
                }
            }

            file_put_contents($manifestAbsolutePath, json_encode($readyManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->storage->finalizeStagedWrite($playbackRelativePath, $targetPlaybackPath);
            $this->storage->finalizeStagedWrite($previewRelativePath, $previewAbsolutePath);

            if (($readyManifest['scrub_status'] ?? null) === self::STATUS_READY) {
                $this->storage->finalizeStagedWrite($scrubSpriteRelativePath, $scrubSpriteAbsolutePath);
            }

            $this->storage->finalizeStagedWrite($manifestRelativePath, $manifestAbsolutePath);

            if ($sourceRelativePath !== null && $sourceRelativePath !== $targetRelativePath) {
                $this->storage->deleteRecordingFile($sourceRelativePath);
            }

            return $readyManifest;
        } catch (\Throwable $exception) {
            if (is_file($previewAbsolutePath)) {
                @unlink($previewAbsolutePath);
            }

            $failedManifest = $pendingManifest;
            $failedManifest['status'] = self::STATUS_FAILED;
            $failedManifest['playback_status'] = self::STATUS_FAILED;
            $failedManifest['error_message'] = Str::limit($exception->getMessage(), 240);

            if (is_file($targetPlaybackPath)) {
                $this->storage->finalizeStagedWrite($playbackRelativePath, $targetPlaybackPath);
                $failedManifest['playback_status'] = self::STATUS_READY;
            }

            file_put_contents($manifestAbsolutePath, json_encode($failedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->storage->finalizeStagedWrite($manifestRelativePath, $manifestAbsolutePath);

            throw $exception;
        } finally {
            $this->storage->deleteTemporaryFile($absoluteRecordingPath);
            $this->storage->deleteTemporaryFile($playbackWorkspacePath);
            $this->storage->deleteTemporaryFile($targetPlaybackPath);
            $this->storage->deleteTemporaryFile($previewAbsolutePath);
            $this->storage->deleteTemporaryFile($scrubSpriteAbsolutePath);
            $this->storage->deleteTemporaryFile($manifestAbsolutePath);
        }
    }

    public function pruneForRecording(CameraRecording $recording): void
    {
        $this->storage->deleteRecordingReviewAssets($recording->relative_path);
    }

    public function previewAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        return $this->storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'preview.mp4', $ensureDirectory);
    }

    public function playbackAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        $relativePath = $this->playbackRelativePath($recording);

        if ($relativePath === null) {
            return null;
        }

        return $ensureDirectory
            ? $this->storage->writableAbsolutePath($relativePath)
            : $this->storage->resolveRecordingAbsolutePath($relativePath);
    }

    public function resolvedPlaybackAbsolutePath(CameraRecording $recording): ?string
    {
        if (!$this->hasReadyPlaybackAsset($recording)) {
            return null;
        }

        return $this->storage->resolveRecordingAbsolutePath($this->playbackRelativePath($recording));
    }

    public function scrubSpriteAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        if ($this->storage->reviewSpritesStoredLocally()) {
            return $this->storage->recordingLocalReviewSpriteAbsolutePath($recording->relative_path, 'scrub-sprite.jpg', $ensureDirectory);
        }

        return $this->storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'scrub-sprite.jpg', $ensureDirectory);
    }

    public function thumbnailSvg(CameraRecording $recording): ?string
    {
        $frame = $this->thumbnailFrameMetadata($recording);

        if ($frame === null) {
            return null;
        }

        $spriteUrl = htmlspecialchars(route('recordings.preview-sprite', ['recording' => $recording], false), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" preserveAspectRatio="xMidYMid slice"><rect width="100%%" height="100%%" fill="#0f172a"/><image href="%3$s" width="%4$d" height="%5$d" x="-%6$d" y="-%7$d" preserveAspectRatio="none"/></svg>',
            $frame['frame_width'],
            $frame['frame_height'],
            $spriteUrl,
            $frame['sprite_width'],
            $frame['sprite_height'],
            $frame['x'],
            $frame['y'],
        );
    }

    public function thumbnailDataUrl(CameraRecording $recording): ?string
    {
        $svg = $this->thumbnailSvg($recording);

        if (!is_string($svg) || trim($svg) === '') {
            return null;
        }

        return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
    }

    /**
     * @return array<string, int|string>|null
     */
    public function scrubSpriteMetadata(CameraRecording $recording): ?array
    {
        $manifest = $this->manifest($recording);
        $scrubSpriteRelativePath = $this->scrubSpriteRelativePath($recording);

        if ($scrubSpriteRelativePath === null) {
            return null;
        }

        $computedManifest = $this->defaultScrubManifest($recording, $scrubSpriteRelativePath);

        if (!is_array($computedManifest)) {
            return null;
        }

        $available = is_array($manifest)
            && ($manifest['scrub_status'] ?? null) === self::STATUS_READY
            && $this->assetFileAvailableForRequest($scrubSpriteRelativePath);

        return [
            'relative_path' => is_string($manifest['scrub_sprite_relative_path'] ?? null)
                ? $manifest['scrub_sprite_relative_path']
                : $computedManifest['scrub_sprite_relative_path'],
            'frame_count' => is_numeric($manifest['scrub_frame_count'] ?? null)
                ? (int) $manifest['scrub_frame_count']
                : (int) $computedManifest['scrub_frame_count'],
            'frame_interval_seconds' => is_numeric($manifest['scrub_frame_interval_seconds'] ?? null)
                ? (int) $manifest['scrub_frame_interval_seconds']
                : (int) $computedManifest['scrub_frame_interval_seconds'],
            'frame_width' => is_numeric($manifest['scrub_frame_width'] ?? null)
                ? (int) $manifest['scrub_frame_width']
                : (int) $computedManifest['scrub_frame_width'],
            'frame_height' => is_numeric($manifest['scrub_frame_height'] ?? null)
                ? (int) $manifest['scrub_frame_height']
                : (int) $computedManifest['scrub_frame_height'],
            'columns' => is_numeric($manifest['scrub_columns'] ?? null)
                ? (int) $manifest['scrub_columns']
                : (int) $computedManifest['scrub_columns'],
            'rows' => is_numeric($manifest['scrub_rows'] ?? null)
                ? (int) $manifest['scrub_rows']
                : (int) $computedManifest['scrub_rows'],
            'available' => $available,
        ];
    }

    private function previewRelativePath(CameraRecording $recording): ?string
    {
        return $this->storage->recordingReviewAssetRelativePath($recording->relative_path, 'preview.mp4');
    }

    private function playbackRelativePath(CameraRecording $recording): ?string
    {
        $normalizedPath = $this->storage->normalizePrivateStorageRelativePath($recording->relative_path);

        if ($normalizedPath === null) {
            return null;
        }

        $directory = trim(dirname($normalizedPath), './');
        $baseName = pathinfo($normalizedPath, PATHINFO_FILENAME);

        if ($baseName === '') {
            return null;
        }

        return ($directory !== '' ? $directory.'/' : '').$baseName.'.mp4';
    }

    private function preferredPlaybackSourceRelativePath(CameraRecording $recording): ?string
    {
        $normalizedPath = $this->storage->normalizePrivateStorageRelativePath($recording->relative_path);

        if ($normalizedPath === null) {
            return null;
        }

        if (strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION)) !== 'mp4') {
            return $normalizedPath;
        }

        $sourceExtension = trim((string) config('recording.extension', 'mkv'));

        if ($sourceExtension === '' || strtolower($sourceExtension) === 'mp4') {
            return $normalizedPath;
        }

        $sourceCandidate = preg_replace('/\.mp4$/i', '.'.$sourceExtension, $normalizedPath);

        if (!is_string($sourceCandidate) || $sourceCandidate === '') {
            return $normalizedPath;
        }

        return $this->storage->recordingExists($sourceCandidate)
            ? $sourceCandidate
            : $normalizedPath;
    }

    private function playbackWorkspaceAbsolutePath(CameraRecording $recording, ?string $sourceRelativePath): ?string
    {
        $targetRelativePath = $this->playbackRelativePath($recording);
        $targetAbsolutePath = $this->playbackAbsolutePath($recording, true);
        $normalizedSourcePath = $this->storage->normalizePrivateStorageRelativePath($sourceRelativePath);

        if ($targetRelativePath === null || $targetAbsolutePath === null) {
            return null;
        }

        if ($normalizedSourcePath !== $targetRelativePath) {
            return $targetAbsolutePath;
        }

        $temporaryDirectory = rtrim((string) config('ffmpeg.temporary_directory', storage_path('app/private/ffmpeg-temp')), '/');
        $workspacePath = $temporaryDirectory.'/normalized-recordings/'.ltrim($targetRelativePath, '/');
        File::ensureDirectoryExists(dirname($workspacePath));

        return $workspacePath;
    }

    private function promotePlaybackWorkspaceOutput(string $workspacePath, string $targetPath): void
    {
        if ($workspacePath === $targetPath) {
            return;
        }

        File::ensureDirectoryExists(dirname($targetPath));

        if (is_file($targetPath)) {
            @unlink($targetPath);
        }

        if (!@rename($workspacePath, $targetPath)) {
            if (!@copy($workspacePath, $targetPath)) {
                throw new RuntimeException('Unable to move the normalized recording into the target recordings path.');
            }

            @unlink($workspacePath);
        }
    }

    private function jobTimeoutSeconds(): int
    {
        return max(180, (int) config('recording.review_assets.job_timeout_seconds', 240));
    }

    private function queuedCacheKey(int $recordingId): string
    {
        return 'camera-recordings:review-assets:queued:'.$recordingId;
    }

    private function usesDatabaseQueue(): bool
    {
        $defaultConnection = (string) config('queue.default', '');

        return $defaultConnection !== ''
            && (string) config('queue.connections.'.$defaultConnection.'.driver', '') === 'database'
            && Schema::hasTable('jobs');
    }

    private function reviewAssetRecordingIdFromPayload(string $payload): ?int
    {
        if (!str_contains($payload, class_basename(GenerateRecordingReviewAssetsJob::class))) {
            return null;
        }

        if (preg_match('/recordingId";i:(\d+);/', $payload, $matches) === 1) {
            return (int) $matches[1];
        }

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) && is_string($decoded['data']['command'] ?? null)
            ? $decoded['data']['command']
            : null;

        if ($command !== null && preg_match('/recordingId";i:(\d+);/', $command, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $assetState
     */
    private function assetStateSatisfiesRequirement(array $assetState, CameraRecording $recording, bool $requireScrubSprite): bool
    {
        if (!(bool) ($assetState['ready'] ?? false)) {
            return false;
        }

        if (!$requireScrubSprite || $this->scrubFrameCount($recording) < 1) {
            return true;
        }

        return ($assetState['scrub_status'] ?? null) === self::STATUS_READY
            && (bool) ($assetState['scrub_sprite_available'] ?? false);
    }

    private function scrubSpriteRelativePath(CameraRecording $recording): ?string
    {
        if ($this->storage->reviewSpritesStoredLocally()) {
            return $this->storage->recordingLocalReviewSpriteRelativePath($recording->relative_path, 'scrub-sprite.jpg');
        }

        return $this->storage->recordingReviewAssetRelativePath($recording->relative_path, 'scrub-sprite.jpg');
    }

    private function manifestRelativePath(CameraRecording $recording): ?string
    {
        return $this->storage->recordingReviewAssetRelativePath($recording->relative_path, 'manifest.json');
    }

    private function recordingCacheKey(CameraRecording $recording): string
    {
        $recordingIdentity = $recording->getKey() ?? $recording->relative_path ?? spl_object_id($recording);

        return $recordingIdentity.':'.$this->assetVersion($recording);
    }

    private function manifestAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        return $this->storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', $ensureDirectory);
    }

    private function previewWidth(): int
    {
        return max(192, (int) config('recording.review_assets.preview_width', 640));
    }

    private function previewHeight(): int
    {
        return max(108, (int) ceil($this->previewWidth() * 9 / 16));
    }

    private function previewFps(): int
    {
        return max(2, (int) config('recording.review_assets.preview_fps', 8));
    }

    private function scrubFrameIntervalSeconds(): int
    {
        return max(1, (int) config('recording.review_assets.scrub_frame_interval_seconds', 10));
    }

    private function scrubFrameWidth(): int
    {
        return max(96, (int) config('recording.review_assets.scrub_frame_width', 128));
    }

    private function scrubFrameHeight(): int
    {
        return max(54, (int) config('recording.review_assets.scrub_frame_height', 72));
    }

    private function scrubColumns(): int
    {
        return max(2, (int) config('recording.review_assets.scrub_columns', 4));
    }

    private function recordingDurationSeconds(CameraRecording $recording): int
    {
        if ($recording->started_at instanceof Carbon && $recording->ended_at instanceof Carbon) {
            return max(1, $recording->started_at->diffInSeconds($recording->ended_at));
        }

        return max(1, (int) config('recording.segment_seconds', 60));
    }

    private function thumbnailOffsetSeconds(CameraRecording $recording): int
    {
        return max(0, (int) floor($this->recordingDurationSeconds($recording) / 2));
    }

    private function assetVersion(CameraRecording $recording): string
    {
        return sha1(json_encode([
            'pipeline_version' => self::ASSET_PIPELINE_VERSION,
            'recording_path' => $recording->relative_path,
            'file_size' => $recording->file_size_bytes,
            'started_at' => $recording->started_at?->timestamp,
            'ended_at' => $recording->ended_at?->timestamp,
            'preview_width' => $this->previewWidth(),
            'preview_height' => $this->previewHeight(),
            'preview_fps' => $this->previewFps(),
            'scrub_frame_interval_seconds' => $this->scrubFrameIntervalSeconds(),
            'scrub_frame_width' => $this->scrubFrameWidth(),
            'scrub_frame_height' => $this->scrubFrameHeight(),
            'scrub_columns' => $this->scrubColumns(),
            'keyframe_interval_seconds' => (int) config('recording.review_assets.keyframe_interval_seconds', 1),
            'video_crf' => (int) config('recording.review_assets.video_crf', 28),
            'playback_preset' => (string) config('recording.review_assets.playback_preset', 'medium'),
            'playback_video_crf' => (int) config('recording.review_assets.playback_video_crf', 26),
            'playback_video_max_bitrate' => (string) config('recording.review_assets.playback_video_max_bitrate', '1500k'),
            'playback_video_buffer_size' => (string) config('recording.review_assets.playback_video_buffer_size', '3000k'),
            'playback_fps' => (int) config('recording.review_assets.playback_fps', 20),
            'playback_audio_bitrate' => (string) config('recording.review_assets.playback_audio_bitrate', '96k'),
            'playback_audio_resample' => (string) config('ffmpeg.playback.audio_resample', 'aresample=async=1000:min_hard_comp=0.100:first_pts=0,asetpts=N/SR/TB'),
        ], JSON_UNESCAPED_SLASHES) ?: 'review-asset');
    }

    /**
     * @return array<int, string>
     */
    private function buildPlaybackAssetCommand(string $inputPath, string $outputPath): array
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $gop = max(24, (int) config('mediamtx.transcode.gop', 30));
        $inputFlags = trim((string) config('ffmpeg.playback.input_fflags', '+genpts+discardcorrupt'));

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            ...($inputFlags !== '' ? ['-fflags', $inputFlags] : []),
            '-i',
            $inputPath,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-sn',
            '-dn',
            '-avoid_negative_ts',
            'make_zero',
            ...$this->playbackVideoArguments($inputPath, $gop),
            ...$this->playbackAudioArguments($inputPath),
            '-max_muxing_queue_size',
            (string) config('ffmpeg.playback.max_muxing_queue_size', 1024),
            '-movflags',
            '+faststart',
            $outputPath,
        ];
    }

    /**
     * @return array<string, int|string>|null
     */
    private function scrubManifest(CameraRecording $recording, string $outputPath): ?array
    {
        $frameCount = $this->scrubFrameCount($recording);

        if ($frameCount < 1) {
            return null;
        }

        $columns = $this->scrubColumns();
        $rows = (int) ceil($frameCount / $columns);

        return [
            'scrub_sprite_relative_path' => $this->storage->recordingRelativePathFromAbsolute($outputPath),
            'scrub_frame_count' => $frameCount,
            'scrub_frame_interval_seconds' => $this->scrubFrameIntervalSeconds(),
            'scrub_frame_width' => $this->scrubFrameWidth(),
            'scrub_frame_height' => $this->scrubFrameHeight(),
            'scrub_columns' => $columns,
            'scrub_rows' => $rows,
        ];
    }

    /**
     * @return array<string, int|string>|null
     */
    private function defaultScrubManifest(CameraRecording $recording, string $scrubSpriteRelativePath): ?array
    {
        $frameCount = $this->scrubFrameCount($recording);

        if ($frameCount < 1) {
            return null;
        }

        $columns = $this->scrubColumns();
        $rows = (int) ceil($frameCount / $columns);

        return [
            'scrub_sprite_relative_path' => $scrubSpriteRelativePath,
            'scrub_frame_count' => $frameCount,
            'scrub_frame_interval_seconds' => $this->scrubFrameIntervalSeconds(),
            'scrub_frame_width' => $this->scrubFrameWidth(),
            'scrub_frame_height' => $this->scrubFrameHeight(),
            'scrub_columns' => $columns,
            'scrub_rows' => $rows,
        ];
    }

    private function assetFileAvailableForRequest(?string $relativePath): bool
    {
        if ($relativePath === null) {
            return false;
        }

        if ($this->shouldSkipRemoteAssetProbe($relativePath)) {
            return true;
        }

        return $this->storage->privateFileExists($relativePath);
    }

    private function shouldSkipRemoteAssetProbe(?string $relativePath): bool
    {
        if (!is_string($relativePath) || trim($relativePath) === '') {
            return false;
        }

        $normalizedPath = $this->storage->normalizePrivateStorageRelativePath($relativePath);

        if (!is_string($normalizedPath) || $normalizedPath === '') {
            return false;
        }

        return $this->storage->pathUsesNetworkStorage($normalizedPath);
    }

    private function scrubFrameCount(CameraRecording $recording): int
    {
        $durationSeconds = $this->recordingDurationSeconds($recording);

        return max(1, (int) floor(max(0, $durationSeconds - 1) / $this->scrubFrameIntervalSeconds()) + 1);
    }

    /**
     * @return array<int, string>
     */
    private function buildPreviewCommand(string $inputPath, string $outputPath): array
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $gop = max(1, $this->previewFps() * max(1, (int) config('recording.review_assets.keyframe_interval_seconds', 1)));
        $previewWidth = $this->previewWidth();
        $previewHeight = $this->previewHeight();

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-map',
            '0:v:0',
            '-an',
            '-sn',
            '-dn',
            '-vf',
            'fps='.$this->previewFps().',scale='.$previewWidth.':'.$previewHeight.':force_original_aspect_ratio=decrease,pad='.$previewWidth.':'.$previewHeight.':(ow-iw)/2:(oh-ih)/2:color=black,setsar=1',
            '-c:v',
            'libx264',
            '-preset',
            'fast',
            '-profile:v',
            'baseline',
            '-pix_fmt',
            'yuv420p',
            '-crf',
            (string) max(16, (int) config('recording.review_assets.video_crf', 24)),
            '-g',
            (string) $gop,
            '-keyint_min',
            (string) $gop,
            '-sc_threshold',
            '0',
            '-movflags',
            '+faststart',
            $outputPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function playbackVideoArguments(string $inputPath, int $gop): array
    {
        if ($this->canCopyPlaybackVideo($inputPath)) {
            return [
                '-c:v',
                'copy',
                '-copyinkf',
            ];
        }

        $outputFps = max(10, (int) config('recording.review_assets.playback_fps', 20));
        $playbackGop = max($gop, $outputFps * 2);

        return [
            '-vf',
            'fps='.$outputFps.',setsar=1',
            '-fps_mode',
            'cfr',
            '-c:v',
            'libx264',
            '-preset',
            (string) config('recording.review_assets.playback_preset', 'medium'),
            '-profile:v',
            'main',
            '-level:v',
            '4.0',
            '-pix_fmt',
            'yuv420p',
            '-crf',
            (string) max(18, (int) config('recording.review_assets.playback_video_crf', 26)),
            '-maxrate',
            (string) config('recording.review_assets.playback_video_max_bitrate', '1500k'),
            '-bufsize',
            (string) config('recording.review_assets.playback_video_buffer_size', '3000k'),
            '-x264-params',
            'nal-hrd=vbr:force-cfr=1',
            '-g',
            (string) $playbackGop,
            '-keyint_min',
            (string) $playbackGop,
            '-sc_threshold',
            '0',
        ];
    }

    private function canCopyPlaybackVideo(string $inputPath): bool
    {
        if (strtolower((string) pathinfo($inputPath, PATHINFO_EXTENSION)) === 'mp4') {
            return false;
        }

        return in_array($this->recordingVideoCodec($inputPath), ['h264', 'h.264'], true);
    }

    /**
     * @return array<int, string>
     */
    private function playbackAudioArguments(string $inputPath): array
    {
        if ($this->canCopyPlaybackAudio($inputPath)) {
            return [
                '-c:a',
                'copy',
            ];
        }

        return [
            '-c:a',
            'aac',
            '-b:a',
            (string) config('recording.review_assets.playback_audio_bitrate', '96k'),
            '-ac',
            '1',
            '-ar',
            '48000',
            '-af',
            (string) config('ffmpeg.playback.audio_resample', 'aresample=async=1000:min_hard_comp=0.100:first_pts=0,asetpts=N/SR/TB'),
        ];
    }

    private function canCopyPlaybackAudio(string $inputPath): bool
    {
        return in_array($this->recordingAudioCodec($inputPath), ['aac'], true);
    }

    private function recordingVideoCodec(string $absolutePath): ?string
    {
        return $this->recordingStreamCodec($absolutePath, 'v:0');
    }

    private function recordingAudioCodec(string $absolutePath): ?string
    {
        return $this->recordingStreamCodec($absolutePath, 'a:0');
    }

    private function recordingStreamCodec(string $absolutePath, string $streamSelector): ?string
    {
        $ffprobeBinary = $this->resolveBinary(config('ffmpeg.ffprobe.binaries', []));

        if ($ffprobeBinary === null || !is_file($absolutePath)) {
            return null;
        }

        try {
            $process = new Process([
                $ffprobeBinary,
                '-v',
                'error',
                '-select_streams',
                $streamSelector,
                '-show_entries',
                'stream=codec_name',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $absolutePath,
            ]);
            $process->setTimeout(5);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            $codec = strtolower(trim($process->getOutput()));

            return $codec !== '' ? $codec : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{frame_height: int, frame_width: int, sprite_height: int, sprite_width: int, x: int, y: int}|null
     */
    private function thumbnailFrameMetadata(CameraRecording $recording): ?array
    {
        $scrubSprite = $this->scrubSpriteMetadata($recording);

        if (!is_array($scrubSprite) || empty($scrubSprite['available'])) {
            return null;
        }

        $frameCount = max(1, (int) ($scrubSprite['frame_count'] ?? 1));
        $frameIntervalSeconds = max(1, (int) ($scrubSprite['frame_interval_seconds'] ?? $this->scrubFrameIntervalSeconds()));
        $frameWidth = max(1, (int) ($scrubSprite['frame_width'] ?? $this->scrubFrameWidth()));
        $frameHeight = max(1, (int) ($scrubSprite['frame_height'] ?? $this->scrubFrameHeight()));
        $columns = max(1, (int) ($scrubSprite['columns'] ?? $this->scrubColumns()));
        $rows = max(1, (int) ($scrubSprite['rows'] ?? 1));
        $frameCapacity = max(1, $columns * $rows);
        $frameIndex = min(
            $frameCount - 1,
            $frameCapacity - 1,
            (int) floor($this->thumbnailOffsetSeconds($recording) / $frameIntervalSeconds),
        );
        $column = $frameIndex % $columns;
        $row = min($rows - 1, (int) floor($frameIndex / $columns));

        return [
            'frame_width' => $frameWidth,
            'frame_height' => $frameHeight,
            'sprite_width' => $columns * $frameWidth,
            'sprite_height' => $rows * $frameHeight,
            'x' => $column * $frameWidth,
            'y' => $row * $frameHeight,
        ];
    }

    /**
     * @param  array<string, int|string>  $scrubManifest
     * @return array<int, string>
     */
    private function buildScrubSpriteCommand(string $inputPath, string $outputPath, array $scrubManifest): array
    {
        $ffmpegBinary = $this->ffmpegBinary();
        $columns = max(2, (int) ($scrubManifest['scrub_columns'] ?? $this->scrubColumns()));
        $rows = max(1, (int) ($scrubManifest['scrub_rows'] ?? 1));
        $intervalSeconds = max(1, (int) ($scrubManifest['scrub_frame_interval_seconds'] ?? $this->scrubFrameIntervalSeconds()));

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-vf',
            'fps=1/'.$intervalSeconds.',scale='.$this->scrubFrameWidth().':'.$this->scrubFrameHeight().':force_original_aspect_ratio=decrease,pad='.$this->scrubFrameWidth().':'.$this->scrubFrameHeight().':(ow-iw)/2:(oh-ih)/2:color=0x0f172a,tile='.$columns.'x'.$rows,
            '-frames:v',
            '1',
            '-q:v',
            '4',
            $outputPath,
        ];
    }

    private function ffmpegBinary(): string
    {
        $resolved = $this->resolveBinary(is_array(config('ffmpeg.ffmpeg.binaries', []))
            ? config('ffmpeg.ffmpeg.binaries', [])
            : []);

        if ($resolved !== null) {
            return $resolved;
        }

        throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
    }

    private function summarizeProcessFailure(Process $process, string $fallbackMessage): string
    {
        $output = trim($process->getErrorOutput() ?: $process->getOutput());

        if ($output === '') {
            return $fallbackMessage;
        }

        return Str::limit($output, 240);
    }
}