<?php

namespace App\Livewire\Recordings;

use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\RecordingTimelineReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * @var array<int, array<string, mixed>>
     */
    public array $timelineTicks = [];

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
     * @var array<string, mixed>|null
     */
    public ?array $initialCurrentSegment = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $initialRailSegments = [];

    public int $initialRailWindowStartMs = 0;

    public int $initialRailWindowEndMs = 1000;

    protected bool $hasInitialCurrentSegment = false;

    protected bool $hasInitialRailSegments = false;

    protected bool $hasInitialRailWindow = false;

    /**
     * @param  array<string, int>  $summary
     * @param  array<int, array<string, mixed>>  $timelineCameraOptions
     * @param  array<int, array<string, mixed>>  $timelineTicks
     * @param  array<int, array<string, mixed>>  $reviewTiles
     * @param  array<string, mixed>  $timelinePayload
     * @param  array<int, int>  $selectedCameraIds
     * @param  array<string, string>  $dateRange
     * @param  array<string, mixed>|null  $initialCurrentSegment
     * @param  array<int, array<string, mixed>>|null  $initialRailSegments
     */
    public function mount(
        array $summary = [],
        array $timelineCameraOptions = [],
        array $timelineTicks = [],
        array $reviewTiles = [],
        int $timelineHours = 24,
        array $timelinePayload = [],
        string $reviewRangeLabel = '',
        array $selectedCameraIds = [],
        array $dateRange = [],
        ?array $initialCurrentSegment = null,
        ?array $initialRailSegments = null,
        ?int $initialRailWindowStartMs = null,
        ?int $initialRailWindowEndMs = null,
    ): void {
        $this->summary = $summary;
        $this->timelineCameraOptions = array_values($timelineCameraOptions);
        $this->timelineTicks = array_values(array_filter($timelineTicks, static fn (mixed $tick): bool => is_array($tick)));
        $this->reviewTiles = $this->summarizeReviewTiles($reviewTiles);
        $this->selectedCameraIds = array_values(array_unique(array_map($this->asInt(...), $selectedCameraIds)));
        $this->dateFrom = is_string($dateRange['from'] ?? null) ? $dateRange['from'] : '';
        $this->dateTo = is_string($dateRange['to'] ?? null) ? $dateRange['to'] : '';
        $this->timelineHours = max(24, $timelineHours);
        $this->timelineZoomMinScale = 1.0;
        $this->timelineZoomMaxScale = (float) max(16, min(96, (int) ceil($this->timelineHours / 2)));
        $this->timelineZoomStepFactor = 1.18;
        $this->timelineBaseHourHeightPx = 88;
        $this->timelineMinTrackHeightPx = 1800;
        $this->timelineZoomScale = $this->clampZoomScale((float) ($timelinePayload['zoomScale'] ?? 1.0));
        $this->dayStartMs = $this->asInt($timelinePayload['dayStartMs'] ?? 0);
        $this->dayEndMs = max($this->dayStartMs + 1000, $this->asInt($timelinePayload['dayEndMs'] ?? ($this->dayStartMs + 1000)));
        $this->focusAtMs = $this->clampFocusMs($this->asInt($timelinePayload['focusAtMs'] ?? $this->dayStartMs));
        $this->reviewRangeLabel = $reviewRangeLabel;
        $this->activeCameraId = $this->resolveActiveCameraId($timelinePayload['activeCameraId'] ?? null);
        $this->hasInitialCurrentSegment = $initialCurrentSegment !== null;
        $this->initialCurrentSegment = is_array($initialCurrentSegment) ? $initialCurrentSegment : null;
        $this->hasInitialRailSegments = $initialRailSegments !== null;
        $this->initialRailSegments = $initialRailSegments !== null
            ? array_values(array_filter($initialRailSegments, static fn (mixed $segment): bool => is_array($segment)))
            : [];
        $this->hasInitialRailWindow = $initialRailWindowStartMs !== null && $initialRailWindowEndMs !== null;
        $this->initialRailWindowStartMs = $initialRailWindowStartMs !== null ? $this->asInt($initialRailWindowStartMs) : $this->dayStartMs;
        $this->initialRailWindowEndMs = $initialRailWindowEndMs !== null
            ? max($this->initialRailWindowStartMs + 1000, $this->asInt($initialRailWindowEndMs))
            : max($this->initialRailWindowStartMs + 1000, $this->dayStartMs + 1000);
    }

    public function render(): View
    {
        $currentTile = $this->currentTile();
        [$initialRailWindowStartMs, $initialRailWindowEndMs] = $this->resolvedInitialRailWindow();
        $currentSegment = $this->providedCurrentSegment($currentTile)
            ?? $this->currentSegment($currentTile);
        $initialRailSegments = $this->providedInitialRailSegments($currentTile, $initialRailWindowStartMs, $initialRailWindowEndMs);

        if ($initialRailSegments === []) {
            $initialRailSegments = $this->loadInitialRailSegments($currentTile, $initialRailWindowStartMs, $initialRailWindowEndMs);
        }

        return view('livewire.recordings.timeline-review', [
            'currentTile' => $currentTile,
            'currentSegment' => $currentSegment,
            'focusLabel' => $this->formatFocusLabel($this->focusAtMs),
            'initialRailSegments' => $initialRailSegments,
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
        if (! is_array($tile) || ! $this->cameraExists((int) ($tile['cameraId'] ?? 0))) {
            return null;
        }

        $cameraId = (int) ($tile['cameraId'] ?? 0);
        $focusAt = Carbon::createFromTimestampUTC((int) floor($this->focusAtMs / 1000));
        $windowStart = Carbon::createFromTimestampUTC((int) floor($this->dayStartMs / 1000));
        $windowEnd = Carbon::createFromTimestampUTC((int) floor($this->dayEndMs / 1000));
        $timeline = app(RecordingTimelineReviewService::class);

        $recording = $timeline->selectRecordingForStage(
            $this->recordingsForCameraWindow($cameraId, $windowStart, $windowEnd),
            $focusAt,
        );

        return $recording instanceof CameraRecording
            ? $timeline->recordingReviewPayload(
                $recording,
                $windowStart,
                $windowEnd,
            )
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<string, mixed>|null
     */
    private function providedCurrentSegment(?array $tile): ?array
    {
        if (! $this->hasInitialCurrentSegment || ! is_array($this->initialCurrentSegment) || ! is_array($tile)) {
            return null;
        }

        return (int) ($this->initialCurrentSegment['cameraId'] ?? 0) === (int) ($tile['cameraId'] ?? 0)
            ? $this->initialCurrentSegment
            : null;
    }

    private function clampFocusMs(int $focusMs): int
    {
        return min($this->timelineMaximumFocusMs(), max($this->dayStartMs, $focusMs));
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
        return array_values(array_map(function (array $tile): array {
            $cameraName = (string) ($tile['cameraName'] ?? '');
            $cameraPreviewUrl = $this->normalizeOptionalString($tile['cameraPreviewUrl'] ?? $tile['previewThumbnailUrl'] ?? null);
            $cameraPreviewAlt = $this->normalizeOptionalString($tile['cameraPreviewAlt'] ?? null)
                ?? ($cameraName !== '' ? $cameraName.' camera preview' : 'Camera preview');

            return [
                'cameraId' => (int) ($tile['cameraId'] ?? 0),
                'cameraName' => $cameraName,
                'cameraIp' => (string) ($tile['cameraIp'] ?? ''),
                'cameraInitials' => $this->cameraInitials($cameraName),
                'cameraPreviewAlt' => $cameraPreviewAlt,
                'cameraPreviewAvailable' => $cameraPreviewUrl !== null,
                'cameraPreviewUrl' => $cameraPreviewUrl,
                'hasFocusSegment' => ! empty($tile['hasFocusSegment']),
                'latestRecordingLabel' => $tile['latestRecordingLabel'] ?? null,
                'previewThumbnailUrl' => $cameraPreviewUrl,
                'previewTimeLabel' => $tile['previewTimeLabel'] ?? null,
                'segmentCount' => (int) ($tile['segmentCount'] ?? 0),
            ];
        }, array_values(array_filter($reviewTiles, static fn (mixed $tile): bool => is_array($tile)))));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function cameraInitials(string $cameraName): string
    {
        $parts = preg_split('/\s+/', trim($cameraName));
        $parts = is_array($parts) ? array_values(array_filter($parts)) : [];

        return strtoupper(substr((string) ($parts[0] ?? ''), 0, 1).substr((string) ($parts[1] ?? ''), 0, 1));
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
     * @return array{0: int, 1: int}
     */
    private function resolvedInitialRailWindow(): array
    {
        if (! $this->hasInitialRailWindow) {
            return $this->initialRailWindow();
        }

        return $this->normalizeRailWindow($this->initialRailWindowStartMs, $this->initialRailWindowEndMs);
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<int, array<string, mixed>>
     */
    private function loadInitialRailSegments(?array $tile, int $windowStartMs, int $windowEndMs): array
    {
        $cameraId = is_array($tile) ? (int) ($tile['cameraId'] ?? 0) : 0;

        if (! $this->cameraExists($cameraId)) {
            return [];
        }

        return $this->segmentPayloadsForCameraWindow($cameraId, $windowStartMs, $windowEndMs);
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<int, array<string, mixed>>
     */
    private function providedInitialRailSegments(?array $tile, int $windowStartMs, int $windowEndMs): array
    {
        $cameraId = is_array($tile) ? (int) ($tile['cameraId'] ?? 0) : 0;

        if (! $this->hasInitialRailSegments || ! $this->cameraExists($cameraId)) {
            return [];
        }

        [$normalizedStartMs, $normalizedEndMs] = $this->normalizeRailWindow($windowStartMs, $windowEndMs);

        return array_values(array_filter($this->initialRailSegments, static function (mixed $segment) use ($cameraId, $normalizedStartMs, $normalizedEndMs): bool {
            if (! is_array($segment)) {
                return false;
            }

            $segmentStartMs = is_numeric($segment['startMs'] ?? null) ? (int) $segment['startMs'] : 0;
            $segmentEndMs = is_numeric($segment['endMs'] ?? null) ? (int) $segment['endMs'] : $segmentStartMs;

            return (int) ($segment['cameraId'] ?? 0) === $cameraId
                && $segmentStartMs < $normalizedEndMs
                && max($segmentStartMs + 1000, $segmentEndMs) > $normalizedStartMs;
        }));
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
     * @return Collection<int, CameraRecording>
     */
    private function recordingsForCameraWindow(int $cameraId, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        return CameraRecording::query()
            ->select($this->timelineRecordingColumns())
            ->where('camera_id', $cameraId)
            ->where('status', CameraRecording::STATUS_RECORDED)
            ->whereNotNull('relative_path')
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
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function timelineRecordingColumns(): array
    {
        return [
            'id',
            'camera_id',
            'capture_mode',
            'status',
            'scheduled_for',
            'started_at',
            'ended_at',
            'relative_path',
            'file_size_bytes',
            'message',
        ];
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
