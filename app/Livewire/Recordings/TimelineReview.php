<?php

namespace App\Livewire\Recordings;

use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\RecordingTimelineReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

class TimelineReview extends Component
{
    /**
     * @var array<string, int>
     */
    public array $summary = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $timelineCameraOptions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $reviewTiles = [];

    /**
     * @var array<int, int>
     */
    public array $selectedCameraIds = [];

    public string $dateFrom = '';

    public string $dateTo = '';

    public int $timelineHours = 24;

    public float $timelineZoomScale = 1.0;

    public float $timelineZoomMinScale = 1.0;

    public float $timelineZoomMaxScale = 8.0;

    public float $timelineZoomStepFactor = 1.18;

    public int $timelineBaseHourHeightPx = 88;

    public int $timelineMinTrackHeightPx = 1800;

    public int $railChunkDurationMs = 28800000;

    public int $railBufferDurationMs = 14400000;

    public int $dayStartMs = 0;

    public int $dayEndMs = 1000;

    public int $focusAtMs = 0;

    public string $reviewRangeLabel = '';

    public ?int $activeCameraId = null;

    /**
     * @param  array<string, int>  $summary
     * @param  array<int, array<string, mixed>>  $timelineCameraOptions
     * @param  array<int, array<string, mixed>>  $reviewTiles
     * @param  array<string, mixed>  $timelinePayload
     * @param  array<int, int>  $selectedCameraIds
     * @param  array<string, string>  $dateRange
     */
    public function mount(
        array $summary = [],
        array $timelineCameraOptions = [],
        array $reviewTiles = [],
        int $timelineHours = 24,
        array $timelinePayload = [],
        string $reviewRangeLabel = '',
        array $selectedCameraIds = [],
        array $dateRange = [],
    ): void {
        $this->summary = $summary;
        $this->timelineCameraOptions = array_values($timelineCameraOptions);
        $this->reviewTiles = $this->summarizeReviewTiles($reviewTiles);
        $this->selectedCameraIds = array_values(array_unique(array_map($this->asInt(...), $selectedCameraIds)));
        $this->dateFrom = is_string($dateRange['from'] ?? null) ? $dateRange['from'] : '';
        $this->dateTo = is_string($dateRange['to'] ?? null) ? $dateRange['to'] : '';
        $this->timelineHours = max(24, $timelineHours);
        $this->timelineZoomMinScale = 1.0;
        $this->timelineZoomMaxScale = (float) max(8, min(48, (int) ceil($this->timelineHours / 6)));
        $this->timelineZoomStepFactor = 1.18;
        $this->timelineBaseHourHeightPx = 88;
        $this->timelineMinTrackHeightPx = 1800;
        $this->timelineZoomScale = $this->clampZoomScale((float) ($timelinePayload['zoomScale'] ?? 1.0));
        $this->dayStartMs = $this->asInt($timelinePayload['dayStartMs'] ?? 0);
        $this->dayEndMs = max($this->dayStartMs + 1000, $this->asInt($timelinePayload['dayEndMs'] ?? ($this->dayStartMs + 1000)));
        $this->focusAtMs = $this->clampFocusMs($this->asInt($timelinePayload['focusAtMs'] ?? $this->dayStartMs));
        $this->reviewRangeLabel = $reviewRangeLabel;
        $this->activeCameraId = $this->resolveActiveCameraId($timelinePayload['activeCameraId'] ?? null);
    }

    #[On('timeline-focus-selected')]
    public function selectFocus(int $focusMs, ?float $zoomScale = null): void
    {
        $currentSegment = $this->currentSegment($this->currentTile());

        if ($zoomScale !== null) {
            $this->timelineZoomScale = $this->clampZoomScale($zoomScale);
        }

        $this->focusAtMs = $this->clampFocusMs($focusMs);

        if (is_array($currentSegment) && $this->segmentContainsFocus($currentSegment, $this->focusAtMs)) {
            $this->skipRender();
        }
    }

    public function syncZoomScale(float $zoomScale): void
    {
        $this->timelineZoomScale = $this->clampZoomScale($zoomScale);
    }

    public function selectCamera(int $cameraId): void
    {
        $cameraId = $this->resolveActiveCameraId($cameraId);

        if ($cameraId !== null) {
            $this->activeCameraId = $cameraId;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRailChunk(int $cameraId, int $windowStartMs, int $windowEndMs): array
    {
        if (!$this->cameraExists($cameraId)) {
            return [
                'cameraId' => $cameraId,
                'segments' => [],
                'windowEndMs' => $this->dayStartMs,
                'windowStartMs' => $this->dayStartMs,
            ];
        }

        [$normalizedStartMs, $normalizedEndMs] = $this->normalizeRailWindow($windowStartMs, $windowEndMs);

        return [
            'cameraId' => $cameraId,
            'segments' => $this->segmentPayloadsForCameraWindow($cameraId, $normalizedStartMs, $normalizedEndMs),
            'windowEndMs' => $normalizedEndMs,
            'windowStartMs' => $normalizedStartMs,
        ];
    }

    public function render(): View
    {
        $currentTile = $this->currentTile();
        $currentSegment = $this->currentSegment($currentTile);
        [$initialRailWindowStartMs, $initialRailWindowEndMs] = $this->initialRailWindow();

        return view('livewire.recordings.timeline-review', [
            'currentTile' => $currentTile,
            'currentSegment' => $currentSegment,
            'focusLabel' => $this->formatFocusLabel($this->focusAtMs),
            'initialRailSegments' => $this->initialRailSegments($currentTile, $initialRailWindowStartMs, $initialRailWindowEndMs),
            'initialRailWindowEndMs' => $initialRailWindowEndMs,
            'initialRailWindowStartMs' => $initialRailWindowStartMs,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentTile(): ?array
    {
        foreach ($this->reviewTiles as $tile) {
            if ((int) ($tile['cameraId'] ?? 0) === (int) ($this->activeCameraId ?? 0)) {
                return $tile;
            }
        }

        return $this->reviewTiles[0] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<string, mixed>|null
     */
    private function currentSegment(?array $tile): ?array
    {
        if (!is_array($tile) || !$this->cameraExists((int) ($tile['cameraId'] ?? 0))) {
            return null;
        }

        $cameraId = (int) ($tile['cameraId'] ?? 0);
        $segmentSeconds = max(1, (int) config('recording.segment_seconds', 60));
        $focusAt = Carbon::createFromTimestampUTC((int) floor($this->focusAtMs / 1000));
        $windowStart = $focusAt->copy()->subSeconds($segmentSeconds);
        $windowEnd = $focusAt->copy()->addSecond();
        $timeline = app(RecordingTimelineReviewService::class);

        $recording = $this->recordingsForCameraWindow($cameraId, $windowStart, $windowEnd)
            ->first(function (CameraRecording $candidate) use ($focusAt, $timeline): bool {
                [$recordingStart, $recordingEnd] = $timeline->recordingBounds($candidate);

                return $timeline->timeRangeContainsFocus($recordingStart, $recordingEnd, $focusAt);
            });

        return $recording instanceof CameraRecording
            ? $timeline->recordingReviewPayload(
                $recording,
                Carbon::createFromTimestampUTC((int) floor($this->dayStartMs / 1000)),
                Carbon::createFromTimestampUTC((int) floor($this->dayEndMs / 1000)),
            )
            : null;
    }

    private function clampFocusMs(int $focusMs): int
    {
        return min($this->timelineMaximumFocusMs(), max($this->dayStartMs, $focusMs));
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function segmentContainsFocus(array $segment, int $focusMs): bool
    {
        $segmentStartMs = $this->asInt($segment['startMs'] ?? 0);
        $segmentEndMs = max($segmentStartMs, $this->asInt($segment['endMs'] ?? $segmentStartMs));

        if ($segmentEndMs <= $segmentStartMs) {
            return $focusMs === $segmentStartMs;
        }

        return $segmentStartMs <= $focusMs
            && $focusMs < $segmentEndMs;
    }

    private function timelineDurationMs(): int
    {
        return max(1, $this->dayEndMs - $this->dayStartMs);
    }

    private function timelineMaximumFocusMs(): int
    {
        return max($this->dayStartMs, $this->dayEndMs - 1000);
    }

    private function resolveActiveCameraId(mixed $cameraId): ?int
    {
        $cameraId = $this->asInt($cameraId);

        foreach ($this->reviewTiles as $tile) {
            if ((int) ($tile['cameraId'] ?? 0) === $cameraId) {
                return $cameraId;
            }
        }

        return isset($this->reviewTiles[0]['cameraId']) ? (int) ($this->reviewTiles[0]['cameraId'] ?? 0) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviewTiles
     */
    private function summarizeReviewTiles(array $reviewTiles): array
    {
        return array_values(array_map(static function (array $tile): array {
            return [
                'cameraId' => (int) ($tile['cameraId'] ?? 0),
                'cameraName' => (string) ($tile['cameraName'] ?? ''),
                'cameraIp' => (string) ($tile['cameraIp'] ?? ''),
                'hasFocusSegment' => !empty($tile['hasFocusSegment']),
                'latestRecordingLabel' => $tile['latestRecordingLabel'] ?? null,
                'previewThumbnailUrl' => $tile['previewThumbnailUrl'] ?? null,
                'previewTimeLabel' => $tile['previewTimeLabel'] ?? null,
                'segmentCount' => (int) ($tile['segmentCount'] ?? 0),
            ];
        }, array_values(array_filter($reviewTiles, static fn (mixed $tile): bool => is_array($tile)))));
    }

    private function cameraExists(int $cameraId): bool
    {
        foreach ($this->reviewTiles as $tile) {
            if ((int) ($tile['cameraId'] ?? 0) === $cameraId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function initialRailWindow(): array
    {
        $halfWindowMs = (int) floor($this->railChunkDurationMs / 2);
        $windowStartMs = max($this->dayStartMs, $this->focusAtMs - $halfWindowMs);
        $windowEndMs = min($this->dayEndMs, $windowStartMs + $this->railChunkDurationMs);

        if (($windowEndMs - $windowStartMs) < $this->railChunkDurationMs) {
            $windowStartMs = max($this->dayStartMs, $windowEndMs - $this->railChunkDurationMs);
        }

        return [$windowStartMs, max($windowStartMs + 1000, $windowEndMs)];
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<int, array<string, mixed>>
     */
    private function initialRailSegments(?array $tile, int $windowStartMs, int $windowEndMs): array
    {
        $cameraId = is_array($tile) ? (int) ($tile['cameraId'] ?? 0) : 0;

        if (!$this->cameraExists($cameraId)) {
            return [];
        }

        return $this->segmentPayloadsForCameraWindow($cameraId, $windowStartMs, $windowEndMs);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function normalizeRailWindow(int $windowStartMs, int $windowEndMs): array
    {
        $normalizedStartMs = max($this->dayStartMs, min($this->dayEndMs, $windowStartMs));
        $normalizedEndMs = max($normalizedStartMs + 1000, min($this->dayEndMs, $windowEndMs));

        return [$normalizedStartMs, $normalizedEndMs];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function segmentPayloadsForCameraWindow(int $cameraId, int $windowStartMs, int $windowEndMs): array
    {
        [$normalizedStartMs, $normalizedEndMs] = $this->normalizeRailWindow($windowStartMs, $windowEndMs);
        $windowStart = Carbon::createFromTimestampUTC((int) floor($normalizedStartMs / 1000));
        $windowEnd = Carbon::createFromTimestampUTC((int) floor($normalizedEndMs / 1000));
        $timelineStart = Carbon::createFromTimestampUTC((int) floor($this->dayStartMs / 1000));
        $timelineEnd = Carbon::createFromTimestampUTC((int) floor($this->dayEndMs / 1000));
        $timeline = app(RecordingTimelineReviewService::class);

        return $this->recordingsForCameraWindow($cameraId, $windowStart, $windowEnd)
            ->map(fn (CameraRecording $recording): array => $timeline->recordingReviewPayload($recording, $timelineStart, $timelineEnd))
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, CameraRecording>
     */
    private function recordingsForCameraWindow(int $cameraId, Carbon $windowStart, Carbon $windowEnd): \Illuminate\Support\Collection
    {
        return CameraRecording::query()
            ->with('camera')
            ->where('camera_id', $cameraId)
            ->where('status', CameraRecording::STATUS_RECORDED)
            ->where(function (Builder $windowQuery) use ($windowStart, $windowEnd): void {
                $windowQuery
                    ->where(function (Builder $scheduledQuery) use ($windowStart, $windowEnd): void {
                        $scheduledQuery
                            ->where('scheduled_for', '>=', $windowStart)
                            ->where('scheduled_for', '<', $windowEnd);
                    })
                    ->orWhere(function (Builder $boundedQuery) use ($windowStart, $windowEnd): void {
                        $boundedQuery
                            ->whereNotNull('started_at')
                            ->whereNotNull('ended_at')
                            ->where('started_at', '<', $windowEnd)
                            ->where('ended_at', '>', $windowStart);
                    });
            })
            ->orderByRaw('COALESCE(started_at, scheduled_for) asc')
            ->orderBy('id')
            ->get();
    }

    private function formatFocusLabel(int $focusMs): string
    {
        return app(ApplicationSettingsService::class)->formatDateTime(
            now()->setTimestamp((int) floor($focusMs / 1000))->utc(),
            'Y-m-d H:i:s'
        ) ?? gmdate('Y-m-d H:i:s', (int) floor($focusMs / 1000));
    }

    private function clampZoomScale(float $zoomScale): float
    {
        return max($this->timelineZoomMinScale, min($this->timelineZoomMaxScale, $zoomScale));
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}