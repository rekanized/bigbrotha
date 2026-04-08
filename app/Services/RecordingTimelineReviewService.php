<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\CameraRecording;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecordingTimelineReviewService
{
    public function __construct(
        private readonly ApplicationSettingsService $settings,
        private readonly RecordingReviewAssetService $reviewAssets,
    ) {
    }

    /**
     * @param  Collection<int, Camera>  $selectedCameras
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     * @return Collection<int, array<string, mixed>>
     */
    public function buildCameraSummaries(
        Collection $selectedCameras,
        Collection $reviewRecordings,
        Carbon $focusAt,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
    ): Collection {
        $recordingsByCamera = $reviewRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording)
            ->groupBy('camera_id');

        return $selectedCameras->map(function (Camera $camera) use ($focusAt, $recordingsByCamera, $reviewWindowStart, $reviewWindowEnd): array {
            /** @var Collection<int, CameraRecording> $cameraRecordings */
            $cameraRecordings = $recordingsByCamera->get($camera->getKey(), collect())
                ->sortBy(fn (CameraRecording $recording): int => (int) ($recording->scheduled_for?->getTimestamp() ?? 0))
                ->values();

            $selectedRecording = $this->selectRecordingForFocus($cameraRecordings, $focusAt);
            $latestRecording = $cameraRecordings->last();
            $selectedRecordingPayload = $selectedRecording instanceof CameraRecording
                ? $this->recordingReviewPayload($selectedRecording, $reviewWindowStart, $reviewWindowEnd)
                : null;
            $latestRecordingPayload = $latestRecording instanceof CameraRecording
                ? $this->recordingReviewPayload($latestRecording, $reviewWindowStart, $reviewWindowEnd)
                : null;

            return [
                'cameraId' => (int) $camera->getKey(),
                'cameraName' => (string) $camera->name,
                'cameraIp' => (string) $camera->local_ip,
                'orientation' => 'landscape',
                'columnSpan' => 1,
                'rowSpan' => 1,
                'segmentCount' => $cameraRecordings->count(),
                'hasFocusSegment' => $selectedRecording instanceof CameraRecording,
                'latestRecordingLabel' => $latestRecordingPayload['timeLabel'] ?? null,
                'previewThumbnailUrl' => $this->cameraPreviewThumbnailUrl($camera),
                'previewTimeLabel' => $latestRecordingPayload['timeLabel'] ?? null,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, CameraRecording>  $cameraRecordings
     */
    public function selectRecordingForFocus(Collection $cameraRecordings, Carbon $focusAt): ?CameraRecording
    {
        $matchingRecording = $cameraRecordings->first(function (CameraRecording $recording) use ($focusAt): bool {
            [$recordingStart, $recordingEnd] = $this->recordingBounds($recording);

            return $this->timeRangeContainsFocus($recordingStart, $recordingEnd, $focusAt);
        });

        return $matchingRecording instanceof CameraRecording ? $matchingRecording : null;
    }

    /**
     * @param  Collection<int, CameraRecording>  $cameraRecordings
     */
    public function selectRecordingForStage(Collection $cameraRecordings, Carbon $focusAt): ?CameraRecording
    {
        $matchingRecording = $this->selectRecordingForFocus($cameraRecordings, $focusAt);

        if ($matchingRecording instanceof CameraRecording) {
            return $matchingRecording;
        }

        $orderedRecordings = $cameraRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording)
            ->sortBy(fn (CameraRecording $recording): int => (int) ($this->recordingBounds($recording)[0]->getTimestamp() ?? 0))
            ->values();

        $latestPriorRecording = $orderedRecordings
            ->filter(function (CameraRecording $recording) use ($focusAt): bool {
                [$recordingStart] = $this->recordingBounds($recording);

                return $recordingStart->lessThanOrEqualTo($focusAt);
            })
            ->last();

        if ($latestPriorRecording instanceof CameraRecording) {
            return $latestPriorRecording;
        }

        $nextRecording = $orderedRecordings->first(function (CameraRecording $recording) use ($focusAt): bool {
            [$recordingStart] = $this->recordingBounds($recording);

            return $recordingStart->greaterThan($focusAt);
        });

        return $nextRecording instanceof CameraRecording ? $nextRecording : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function recordingReviewPayload(CameraRecording $recording, Carbon $reviewWindowStart, Carbon $reviewWindowEnd): array
    {
        [$recordingStart, $recordingEnd] = $this->recordingBounds($recording);
        $timelineAssets = $this->reviewAssets->timelinePlaybackMetadata($recording);
        $scrubSprite = is_array($timelineAssets['scrub'] ?? null) ? $timelineAssets['scrub'] : null;

        $clippedStart = $recordingStart->greaterThan($reviewWindowStart)
            ? $recordingStart->copy()
            : $reviewWindowStart->copy();
        $clippedEnd = $recordingEnd->lessThan($reviewWindowEnd)
            ? $recordingEnd->copy()
            : $reviewWindowEnd->copy();

        $reviewDurationHours = max(1, $this->timelineDurationHours($reviewWindowStart, $reviewWindowEnd));
        $topHours = min($reviewDurationHours, max(0, $this->timelineHourOffset($reviewWindowStart, $clippedStart)));
        $bottomHours = min($reviewDurationHours, max($topHours + (1 / 3600), $this->timelineHourOffset($reviewWindowStart, $clippedEnd)));
        $spanHours = max(1 / 3600, $bottomHours - $topHours);
        $spanSeconds = max(1, $clippedStart->diffInSeconds($clippedEnd));
        $midpoint = $clippedStart->copy()->addSeconds((int) floor($spanSeconds / 2));
        $modeLabel = $recording->capture_mode === Camera::RECORDING_MODE_MOTION ? 'Movement clip' : 'Continuous clip';
        $durationSeconds = is_numeric($timelineAssets['duration_seconds'] ?? null)
            ? max(1, (int) $timelineAssets['duration_seconds'])
            : ($this->durationSeconds($recording) ?? max(1, $recordingStart->diffInSeconds($recordingEnd)));
        $thumbnailFrameIndex = $this->thumbnailFrameIndex($durationSeconds, $scrubSprite);
        $scrubSpriteUrl = is_array($scrubSprite) && !empty($scrubSprite['relative_path']) && !empty($scrubSprite['available'])
            ? route('recordings.preview-sprite', ['recording' => $recording])
            : null;

        return [
            'id' => $recording->getKey(),
            'cameraId' => $recording->camera_id,
            'captureMode' => $recording->capture_mode,
            'modeLabel' => $modeLabel,
            'message' => $recording->message,
            'startMs' => $recordingStart->valueOf(),
            'endMs' => $recordingEnd->valueOf(),
            'midpointMs' => $midpoint->valueOf(),
            'startPercent' => round(($topHours / $reviewDurationHours) * 100, 6),
            'topHours' => round($topHours, 6),
            'topPercent' => round(($topHours / $reviewDurationHours) * 100, 6),
            'widthPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'heightHours' => round($spanHours, 6),
            'heightPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'renderWidthPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'renderHeightHours' => round($spanHours, 6),
            'renderHeightPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'previewStatus' => $timelineAssets['status'] ?? RecordingReviewAssetService::STATUS_MISSING,
            'streamUrl' => $recording->relative_path ? route('recordings.review-stream', ['recording' => $recording]) : null,
            'thumbnailUrl' => $recording->relative_path ? route('recordings.preview-thumbnail', ['recording' => $recording]) : null,
            'thumbnailFallbackUrl' => asset('img/recording-preview-missing.svg'),
            'thumbnailSpriteUrl' => $scrubSpriteUrl,
            'thumbnailFrameIndex' => $thumbnailFrameIndex,
            'scrubSpriteUrl' => $scrubSpriteUrl,
            'scrubFrameCount' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_count'] ?? 0) : 0,
            'scrubFrameIntervalMs' => is_array($scrubSprite) ? ((int) ($scrubSprite['frame_interval_seconds'] ?? 0) * 1000) : 0,
            'scrubFrameWidth' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_width'] ?? 0) : 0,
            'scrubFrameHeight' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_height'] ?? 0) : 0,
            'scrubColumns' => is_array($scrubSprite) ? (int) ($scrubSprite['columns'] ?? 0) : 0,
            'scrubRows' => is_array($scrubSprite) ? (int) ($scrubSprite['rows'] ?? 0) : 0,
            'showUrl' => route('recordings.show', ['recording' => $recording]),
            'downloadUrl' => $recording->relative_path ? route('recordings.download', ['recording' => $recording]) : null,
            'startLabel' => $this->settings->formatDateTime($recordingStart, 'Y-m-d H:i:s') ?? 'Recorded segment',
            'scheduledLabel' => $recording->scheduled_for instanceof Carbon
                ? ($this->settings->formatDateTime($recording->scheduled_for, 'Y-m-d H:i:s') ?? 'Recorded segment')
                : 'Recorded segment',
            'timeLabel' => $this->settings->formatTimeRange($recordingStart, $recordingEnd, 'H:i:s') ?? 'Saved clip',
            'durationLabel' => $durationSeconds.' s',
            'durationSeconds' => $durationSeconds,
            'fileSizeLabel' => $recording->file_size_bytes
                ? number_format($recording->file_size_bytes / 1048576, 2).' MB'
                : 'No file saved',
        ];
    }

    private function cameraPreviewThumbnailUrl(Camera $camera): ?string
    {
        $preview = $camera->latestRtspPreview();

        if (!is_array($preview) || !is_numeric($preview['index'] ?? null)) {
            return null;
        }

        return route('camera-fleet.preview', [
            'camera' => $camera,
            'profileIndex' => (int) $preview['index'],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function recordingBounds(CameraRecording $recording): array
    {
        $recordingStart = $recording->started_at instanceof Carbon
            ? $recording->started_at->copy()->utc()
            : ($recording->scheduled_for instanceof Carbon
                ? $recording->scheduled_for->copy()->utc()
                : now()->utc());

        $recordingEnd = $recording->ended_at instanceof Carbon
            ? $recording->ended_at->copy()->utc()
            : $recordingStart->copy()->addSeconds(max(1, (int) config('recording.segment_seconds', 60)));

        if ($recordingEnd->lessThanOrEqualTo($recordingStart)) {
            $recordingEnd = $recordingStart->copy()->addSecond();
        }

        return [$recordingStart, $recordingEnd];
    }

    public function timeRangeContainsFocus(Carbon $rangeStart, Carbon $rangeEnd, Carbon $focusAt): bool
    {
        if ($rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return $focusAt->equalTo($rangeStart);
        }

        return $rangeStart->lessThanOrEqualTo($focusAt)
            && $focusAt->lessThan($rangeEnd);
    }

    public function timelineDurationHours(Carbon $reviewWindowStart, Carbon $reviewWindowEnd): float
    {
        return max(1, $this->timelineHourOffset($reviewWindowStart, $reviewWindowEnd));
    }

    public function timelineHourOffset(Carbon $reviewWindowStart, Carbon $moment): float
    {
        $displayWindowStart = $this->settings->toDisplayTimezone($reviewWindowStart)->copy()->startOfHour();
        $displayMoment = $this->settings->toDisplayTimezone($moment);

        if ($displayMoment->lessThanOrEqualTo($displayWindowStart)) {
            return 0.0;
        }

        $displayHourStart = $displayMoment->copy()->startOfHour();
        $elapsedWholeHours = max(0, $displayWindowStart->diffInHours($displayHourStart, false));
        $secondsWithinHour = ($displayMoment->minute * 60) + $displayMoment->second;

        return $elapsedWholeHours + ($secondsWithinHour / 3600);
    }

    private function durationSeconds(CameraRecording $recording): ?int
    {
        if (!$recording->started_at instanceof Carbon || !$recording->ended_at instanceof Carbon) {
            return null;
        }

        return max(0, $recording->started_at->diffInSeconds($recording->ended_at));
    }

    /**
     * @param  array<string, mixed>|null  $scrubSprite
     */
    private function thumbnailFrameIndex(int $durationSeconds, ?array $scrubSprite): int
    {
        if (!is_array($scrubSprite)) {
            return 0;
        }

        $frameCount = max(0, (int) ($scrubSprite['frame_count'] ?? 0));

        if ($frameCount < 1) {
            return 0;
        }

        $frameIntervalSeconds = max(1, (int) ($scrubSprite['frame_interval_seconds'] ?? 1));
        $columns = max(1, (int) ($scrubSprite['columns'] ?? 1));
        $rows = max(1, (int) ($scrubSprite['rows'] ?? 1));
        $frameCapacity = max(1, $columns * $rows);
        $thumbnailOffsetSeconds = max(0, (int) floor($durationSeconds / 2));

        return min(
            $frameCount - 1,
            $frameCapacity - 1,
            (int) floor($thumbnailOffsetSeconds / $frameIntervalSeconds),
        );
    }
}