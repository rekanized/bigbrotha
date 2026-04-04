<?php

namespace App\Services;

use App\Models\CameraRecording;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class RecordingReviewAssetService
{
    private const ASSET_PIPELINE_VERSION = 2;

    public const STATUS_READY = 'ready';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MISSING = 'missing';

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

    public function recordJobFailure(CameraRecording $recording, string $message): void
    {
        $manifestAbsolutePath = $this->manifestAbsolutePath($recording, true);
        $previewAbsolutePath = $this->previewAbsolutePath($recording, true);
        $thumbnailAbsolutePath = $this->thumbnailAbsolutePath($recording, true);
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording, true);

        if ($manifestAbsolutePath === null || $previewAbsolutePath === null || $thumbnailAbsolutePath === null || $scrubSpriteAbsolutePath === null) {
            return;
        }

        $scrubManifest = $this->scrubManifest($recording, $scrubSpriteAbsolutePath);
        $manifest = [
            'status' => self::STATUS_FAILED,
            'version' => $this->assetVersion($recording),
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => $this->recordingDurationSeconds($recording),
            'preview_relative_path' => $this->storage->recordingRelativePathFromAbsolute($previewAbsolutePath),
            'thumbnail_relative_path' => $this->storage->recordingRelativePathFromAbsolute($thumbnailAbsolutePath),
            'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
            'preview_width' => $this->previewWidth(),
            'thumbnail_width' => $this->thumbnailWidth(),
            'scrub_status' => $scrubManifest === null ? self::STATUS_MISSING : self::STATUS_FAILED,
            'error_message' => Str::limit($message, 240),
        ];

        if ($scrubManifest !== null) {
            $manifest = array_merge($manifest, $scrubManifest, [
                'scrub_error_message' => Str::limit($message, 240),
            ]);
        }

        file_put_contents($manifestAbsolutePath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(CameraRecording $recording): ?array
    {
        $manifestAbsolutePath = $this->manifestAbsolutePath($recording);

        if ($manifestAbsolutePath === null || !is_file($manifestAbsolutePath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($manifestAbsolutePath), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function assetState(CameraRecording $recording): array
    {
        $manifest = $this->manifest($recording);
        $previewAbsolutePath = $this->previewAbsolutePath($recording);
        $thumbnailAbsolutePath = $this->thumbnailAbsolutePath($recording);
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording);
        $versionCurrent = is_array($manifest) && ($manifest['version'] ?? null) === $this->assetVersion($recording);
        $previewFileAvailable = $previewAbsolutePath !== null && is_file($previewAbsolutePath);
        $thumbnailFileAvailable = $thumbnailAbsolutePath !== null && is_file($thumbnailAbsolutePath);
        $scrubSpriteFileAvailable = $scrubSpriteAbsolutePath !== null && is_file($scrubSpriteAbsolutePath);
        $previewAvailable = $versionCurrent && $previewFileAvailable;
        $thumbnailAvailable = $versionCurrent && $thumbnailFileAvailable;
        $scrubSpriteAvailable = $versionCurrent && $scrubSpriteFileAvailable;
        $ready = $versionCurrent && ($manifest['status'] ?? null) === self::STATUS_READY && $previewAvailable;

        return [
            'status' => $ready
                ? self::STATUS_READY
                : ($versionCurrent && is_string($manifest['status'] ?? null) ? $manifest['status'] : self::STATUS_MISSING),
            'ready' => $ready,
            'preview_available' => $previewAvailable,
            'thumbnail_available' => $thumbnailAvailable,
            'scrub_status' => $versionCurrent && is_string($manifest['scrub_status'] ?? null) ? $manifest['scrub_status'] : self::STATUS_MISSING,
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

    /**
     * @return array<string, mixed>
     */
    public function generateForRecording(CameraRecording $recording): array
    {
        $absoluteRecordingPath = $this->storage->resolveRecordingAbsolutePath($recording->relative_path);

        if ($absoluteRecordingPath === null) {
            throw new RuntimeException('The saved recording segment is not available on disk.');
        }

        $version = $this->assetVersion($recording);
        $existingManifest = $this->manifest($recording);
        $previewAbsolutePath = $this->previewAbsolutePath($recording, true);
        $thumbnailAbsolutePath = $this->thumbnailAbsolutePath($recording, true);
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording, true);
        $manifestAbsolutePath = $this->manifestAbsolutePath($recording, true);
        $expectedScrubStatus = $this->scrubFrameCount($recording) > 0 ? self::STATUS_READY : self::STATUS_MISSING;

        if (
            is_array($existingManifest)
            && ($existingManifest['version'] ?? null) === $version
            && ($existingManifest['status'] ?? null) === self::STATUS_READY
            && $previewAbsolutePath !== null
            && is_file($previewAbsolutePath)
            && $thumbnailAbsolutePath !== null
            && is_file($thumbnailAbsolutePath)
            && (
                (($existingManifest['scrub_status'] ?? null) === self::STATUS_READY && $scrubSpriteAbsolutePath !== null && is_file($scrubSpriteAbsolutePath))
                || (($existingManifest['scrub_status'] ?? null) === self::STATUS_FAILED)
                || (($existingManifest['scrub_status'] ?? null) === self::STATUS_MISSING && $expectedScrubStatus === self::STATUS_MISSING)
            )
        ) {
            return $existingManifest;
        }

        if ($previewAbsolutePath === null || $thumbnailAbsolutePath === null || $scrubSpriteAbsolutePath === null || $manifestAbsolutePath === null) {
            throw new RuntimeException('Unable to resolve the review asset storage paths.');
        }

        $scrubManifest = $this->scrubManifest($recording, $scrubSpriteAbsolutePath);

        $pendingManifest = [
            'status' => self::STATUS_PENDING,
            'version' => $version,
            'generated_at' => now()->utc()->toIso8601String(),
            'duration_seconds' => $this->recordingDurationSeconds($recording),
            'preview_relative_path' => $this->storage->recordingRelativePathFromAbsolute($previewAbsolutePath),
            'thumbnail_relative_path' => $this->storage->recordingRelativePathFromAbsolute($thumbnailAbsolutePath),
            'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
            'preview_width' => $this->previewWidth(),
            'thumbnail_width' => $this->thumbnailWidth(),
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
            $previewProcess = new Process($this->buildPreviewCommand($absoluteRecordingPath, $previewAbsolutePath));
            $previewProcess->setTimeout(max(45, $this->recordingDurationSeconds($recording) + 45));
            $previewProcess->run();

            if (!$previewProcess->isSuccessful() || !is_file($previewAbsolutePath)) {
                throw new RuntimeException($this->summarizeProcessFailure($previewProcess, 'Unable to generate the review preview video.'));
            }

            $thumbnailProcess = new Process($this->buildThumbnailCommand($absoluteRecordingPath, $thumbnailAbsolutePath, $recording));
            $thumbnailProcess->setTimeout(45);
            $thumbnailProcess->run();

            if (!$thumbnailProcess->isSuccessful() || !is_file($thumbnailAbsolutePath)) {
                throw new RuntimeException($this->summarizeProcessFailure($thumbnailProcess, 'Unable to generate the review preview thumbnail.'));
            }

            $readyManifest = [
                'status' => self::STATUS_READY,
                'version' => $version,
                'generated_at' => now()->utc()->toIso8601String(),
                'duration_seconds' => $this->recordingDurationSeconds($recording),
                'preview_relative_path' => $this->storage->recordingRelativePathFromAbsolute($previewAbsolutePath),
                'thumbnail_relative_path' => $this->storage->recordingRelativePathFromAbsolute($thumbnailAbsolutePath),
                'thumbnail_offset_seconds' => $this->thumbnailOffsetSeconds($recording),
                'preview_width' => $this->previewWidth(),
                'thumbnail_width' => $this->thumbnailWidth(),
                'scrub_status' => self::STATUS_MISSING,
            ];

            if ($scrubManifest !== null) {
                try {
                    $scrubProcess = new Process($this->buildScrubSpriteCommand($absoluteRecordingPath, $scrubSpriteAbsolutePath, $scrubManifest));
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

            return $readyManifest;
        } catch (\Throwable $exception) {
            if (is_file($previewAbsolutePath)) {
                @unlink($previewAbsolutePath);
            }

            if (is_file($thumbnailAbsolutePath)) {
                @unlink($thumbnailAbsolutePath);
            }

            $failedManifest = $pendingManifest;
            $failedManifest['status'] = self::STATUS_FAILED;
            $failedManifest['error_message'] = Str::limit($exception->getMessage(), 240);

            file_put_contents($manifestAbsolutePath, json_encode($failedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            throw $exception;
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

    public function thumbnailAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        return $this->storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'poster.jpg', $ensureDirectory);
    }

    public function scrubSpriteAbsolutePath(CameraRecording $recording, bool $ensureDirectory = false): ?string
    {
        return $this->storage->recordingReviewAssetAbsolutePath($recording->relative_path, 'scrub-sprite.jpg', $ensureDirectory);
    }

    /**
     * @return array<string, int|string>|null
     */
    public function scrubSpriteMetadata(CameraRecording $recording): ?array
    {
        $scrubSpriteAbsolutePath = $this->scrubSpriteAbsolutePath($recording);
        $manifest = $this->manifest($recording);

        if ($scrubSpriteAbsolutePath === null) {
            return null;
        }

        $computedManifest = $this->scrubManifest($recording, $scrubSpriteAbsolutePath);

        if (!is_array($computedManifest)) {
            return null;
        }

        $available = is_array($manifest)
            && ($manifest['scrub_status'] ?? null) === self::STATUS_READY
            && is_file($scrubSpriteAbsolutePath);

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

    private function thumbnailWidth(): int
    {
        return max(120, (int) config('recording.review_assets.thumbnail_width', 320));
    }

    private function scrubFrameIntervalSeconds(): int
    {
        return max(1, (int) config('recording.review_assets.scrub_frame_interval_seconds', 2));
    }

    private function scrubFrameWidth(): int
    {
        return max(96, (int) config('recording.review_assets.scrub_frame_width', 160));
    }

    private function scrubFrameHeight(): int
    {
        return max(54, (int) config('recording.review_assets.scrub_frame_height', 90));
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
            'thumbnail_width' => $this->thumbnailWidth(),
            'scrub_frame_interval_seconds' => $this->scrubFrameIntervalSeconds(),
            'scrub_frame_width' => $this->scrubFrameWidth(),
            'scrub_frame_height' => $this->scrubFrameHeight(),
            'scrub_columns' => $this->scrubColumns(),
            'keyframe_interval_seconds' => (int) config('recording.review_assets.keyframe_interval_seconds', 1),
            'video_crf' => (int) config('recording.review_assets.video_crf', 28),
        ], JSON_UNESCAPED_SLASHES) ?: 'review-asset');
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
            'veryfast',
            '-profile:v',
            'baseline',
            '-pix_fmt',
            'yuv420p',
            '-crf',
            (string) max(16, (int) config('recording.review_assets.video_crf', 28)),
            '-g',
            (string) $gop,
            '-keyint_min',
            (string) $gop,
            '-movflags',
            '+faststart',
            $outputPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildThumbnailCommand(string $inputPath, string $outputPath, CameraRecording $recording): array
    {
        $ffmpegBinary = $this->ffmpegBinary();

        return [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            (string) $this->thumbnailOffsetSeconds($recording),
            '-i',
            $inputPath,
            '-frames:v',
            '1',
            '-vf',
            'scale='.$this->thumbnailWidth().':-2:force_original_aspect_ratio=decrease',
            '-q:v',
            '4',
            $outputPath,
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
        $candidates = config('ffmpeg.ffmpeg.binaries', []);

        foreach (is_array($candidates) ? $candidates : [] as $candidate) {
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

        throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
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

    private function summarizeProcessFailure(Process $process, string $fallbackMessage): string
    {
        $output = trim($process->getErrorOutput() ?: $process->getOutput());

        if ($output === '') {
            return $fallbackMessage;
        }

        return Str::limit($output, 240);
    }
}